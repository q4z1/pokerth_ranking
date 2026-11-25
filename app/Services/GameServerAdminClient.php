<?php

namespace App\Services;

use RuntimeException;

/**
 * Minimaler PokerTH-Client über die WebSocket-Schnittstelle des Game-Servers,
 * nur für Admin-Aktionen (Kickban, Global Notice).
 *
 * Ablauf (siehe ServerLobbyThread im pokerth-Repo):
 *   Announce ← | → Init (authenticatedLogin, Passwort in clientUserData)
 *   AuthServerChallenge ← | → AuthClientResponse (leer, SCRAM ist serverseitig entfernt)
 *   InitAck ← | PlayerList(new)* ← | → PlayerInfoRequest | PlayerInfoReply* ←
 *   → AdminBanPlayer(uniqueId) | AdminBanPlayerAck(pending) ← | AdminBanPlayerAck(accepted|dbError) ←
 *
 * Tischliste: nach InitAck kommt je offenem Tisch ein GameListNew (Name,
 * Modus, Spieler-IDs, NetGameInfo) – dieselbe Liste, die jeder Client in der
 * Lobby sieht. lobbySnapshot() liest sie samt Spielerliste nur mit und meldet
 * sich wieder ab.
 *
 * Private Nachricht: → ChatRequest(targetPlayerId, text). Der Server bestätigt
 * nicht; Fehlschlag = ChatReject mit unserem Text. Sitzt das Ziel an einem
 * laufenden Tisch, hält der Server die PM bis Spielende zurück (bzw. lehnt
 * sie bei älteren Servern ab). Die PM geht nicht vom Bot, sondern vom
 * Game-Account des angemeldeten Admins (withLogin()), damit Antworten im
 * eigenen Client ankommen. Voraussetzung: der Admin ist dabei nicht selbst
 * verbunden – ein WebSocket-Login unter einem belegten Namen lehnt der Server
 * mit "player name in use" ab (code in_use), ein TCP-Client würde gekickt.
 *
 * Global Notice: nach InitAck → AdminGlobalNotice(text) | AdminGlobalNoticeAck ←.
 * Der Server schickt den Text als chatTypeBroadcast-ChatMessage an alle
 * Sessions (Lobby und Tische), daher dieselben 128 Byte wie jeder Chattext.
 *
 * AdminBanPlayerMessage erwartet die *Unique-Player-ID der laufenden Session*,
 * nicht die DB-player_id – daher der Umweg über PlayerList/PlayerInfo und die
 * Zuordnung per Nickname. Der Server kickt den Spieler, sperrt dessen IP für
 * 24 h und setzt player.active = 4 (dauerhafter Bann).
 *
 * Das Bot-Konto muss in admin_player stehen. Der Server liest admin_player nur
 * beim Start – ein neu eingetragenes Konto wirkt erst nach einem Neustart.
 * Der Bot braucht ein eigenes Konto: ein zweiter Login unter demselben Namen
 * wirft die bestehende Session raus.
 */
class GameServerAdminClient
{
    // PokerTHMessageType → Feldnummer im PokerTHMessage-Envelope = Typ + 1.
    private const T_ANNOUNCE        = 1;
    private const T_INIT            = 2;
    private const T_AUTH_CHALLENGE  = 3;
    private const T_AUTH_RESPONSE   = 4;
    private const T_INIT_ACK        = 6;
    private const T_PLAYER_LIST     = 12;
    private const T_GAME_LIST_NEW   = 13;
    private const T_GAME_LIST_UPDATE = 14;
    private const T_GAME_LIST_JOINED = 15;
    private const T_GAME_LIST_LEFT  = 16;
    private const T_PLAYER_INFO_REQ = 18;
    private const T_PLAYER_INFO_REP = 19;
    private const T_CHAT_REQUEST    = 63;
    private const T_CHAT_REJECT     = 65;
    private const T_ERROR           = 73;
    private const T_ADMIN_BAN       = 76;
    private const T_ADMIN_BAN_ACK   = 77;
    private const T_GLOBAL_NOTICE   = 82;
    private const T_GLOBAL_NOTICE_ACK = 83;

    /** Grenze aus NetPacketValidator::ValidateAdminGlobalNoticeMessage (Bytes). */
    public const NOTICE_MAX_BYTES = 128;

    private const BAN_RESULTS = [
        0 => 'accepted',
        1 => 'pending',
        2 => 'no_db',
        3 => 'db_error',
        4 => 'invalid',
    ];

    /** ErrorMessage.ErrorReason playerNameInUse. */
    private const ERR_NAME_IN_USE = 4;

    /** ErrorMessage.ErrorReason → lesbarer Text. */
    private const ERROR_REASONS = [
        1 => 'version not supported',
        2 => 'server full',
        3 => 'authentication failed',
        4 => 'player name in use',
        5 => 'invalid player name',
        6 => 'server maintenance',
        7 => 'blocked',
        9 => 'invalid packet',
        10 => 'invalid state',
        11 => 'kicked from server',
        12 => 'banned from server',
        13 => 'blocked by server',
        14 => 'session timeout',
    ];

    /**
     * Max. IDs pro PlayerInfoRequest. Empirisch (2026-11-16): bis 10 IDs
     * antwortet der Server, ab 12 trennt er die Verbindung; eine leere
     * Anfrage ebenso. Die Batches laufen nacheinander.
     */
    private const INFO_BATCH = 8;

    /** @var resource|null */
    private $sock = null;
    private string $buffer = '';
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? config('services.game_server_admin');
    }

    /** Gleicher Server, aber Login mit einem anderen Account (statt des Bots). */
    public function withLogin(string $nick, string $password): static
    {
        return new static(array_merge($this->cfg, ['nick' => $nick, 'password' => $password]));
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg['host']) && !empty($this->cfg['nick']) && (string) ($this->cfg['password'] ?? '') !== '';
    }

    /**
     * Kickbannt den Spieler mit diesem Nickname, falls er gerade online ist.
     *
     * @return array{ok:bool, code:string, msg:string, unique_id?:int, online?:int}
     */
    public function kickban(string $nick): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'code' => 'not_configured', 'msg' => 'Game server admin account is not configured (PTH_ADMIN_BOT_* in .env).'];
        }
        $nick = trim($nick);
        if ($nick === '') return ['ok' => false, 'code' => 'invalid', 'msg' => 'No nickname given.'];
        if (strcasecmp($nick, (string) $this->cfg['nick']) === 0) {
            return ['ok' => false, 'code' => 'invalid', 'msg' => 'Refusing to ban the admin bot itself.'];
        }

        try {
            $this->connect();
            $this->login();
            $players = $this->collectPlayers();

            $uniqueId = $this->findUniqueId($players, $nick);
            if ($uniqueId === null) {
                return ['ok' => false, 'code' => 'not_online', 'msg' => "\"{$nick}\" is not online on the game server.", 'online' => count($players)];
            }

            $this->send(self::T_ADMIN_BAN, $this->pbVarint(1, $uniqueId));
            $result = $this->awaitBanResult($uniqueId);
            $label = self::BAN_RESULTS[$result] ?? 'unknown';

            $ok = in_array($label, ['accepted', 'pending', 'no_db'], true);
            $msg = [
                'accepted' => "\"{$nick}\" kicked, IP banned for 24 h and account banned.",
                'pending'  => "\"{$nick}\" kicked and IP banned for 24 h; account ban was sent to the database (no confirmation within the timeout).",
                'no_db'    => "\"{$nick}\" kicked and IP banned for 24 h (guest – no account to ban).",
                'db_error' => "\"{$nick}\" kicked and IP banned for 24 h, but the database ban FAILED.",
                'invalid'  => 'The game server rejected the ban (bot not admin, target is an admin, or target just left).',
            ][$label] ?? "Unknown ban result ({$result}).";

            return ['ok' => $ok, 'code' => $label, 'msg' => $msg, 'unique_id' => $uniqueId];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => 'error', 'msg' => $e->getMessage()];
        } finally {
            $this->close();
        }
    }

    /**
     * Schickt dem Spieler mit diesem Nickname eine private Nachricht vom
     * eingeloggten Account (Bot oder per withLogin() der Admin selbst).
     *
     * $allowOffline (nur Accounts): ist der Spieler nicht online, geht die PM
     * per Name (targetPlayerName, targetPlayerId = 0). Der Server sucht den
     * Account asynchron in der DB und hält die PM bis zum nächsten Login
     * (max. 30 Tage). Die Lookup-Antwort verwirft er, wenn unsere Session
     * schon zu ist – daher die Wartezeit unten vor dem Abmelden.
     *
     * @return array{ok:bool, code:string, msg:string}
     */
    public function privateMessage(string $nick, string $text, bool $allowOffline = false): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'code' => 'not_configured', 'msg' => 'Game server admin account is not configured (PTH_ADMIN_BOT_* in .env).'];
        }
        $nick = trim($nick);
        $text = trim($text);
        if ($nick === '') return ['ok' => false, 'code' => 'invalid', 'msg' => 'No nickname given.'];
        if (strcasecmp($nick, (string) $this->cfg['nick']) === 0) {
            return ['ok' => false, 'code' => 'invalid', 'msg' => 'You cannot send a message to yourself.'];
        }
        if ($text === '') return ['ok' => false, 'code' => 'invalid', 'msg' => 'The message is empty.'];
        if (!mb_check_encoding($text, 'UTF-8')) return ['ok' => false, 'code' => 'invalid', 'msg' => 'The message is not valid UTF-8.'];
        if (strlen($text) > self::NOTICE_MAX_BYTES) {
            return ['ok' => false, 'code' => 'invalid', 'msg' => 'The message is longer than ' . self::NOTICE_MAX_BYTES . ' bytes (' . strlen($text) . ').'];
        }

        try {
            $this->connect();
            $this->login();
            $uniqueId = $this->findUniqueId($this->collectPlayers(), $nick);
            $offline = $uniqueId === null;
            if ($offline && !$allowOffline) {
                return ['ok' => false, 'code' => 'not_online', 'msg' => "\"{$nick}\" is not online on the game server."];
            }

            $this->send(self::T_CHAT_REQUEST, $offline
                ? $this->pbVarint(2, 0) . $this->pbBytes(3, $text) . $this->pbBytes(4, $nick)
                : $this->pbVarint(2, $uniqueId) . $this->pbBytes(3, $text));

            // Kein Ack bei Erfolg – nur ein ChatReject käme zurück. Offline
            // etwas länger: der Server fragt erst die DB nach dem Namen.
            $deadline = microtime(true) + ($offline ? 3 : 2);
            while (microtime(true) < $deadline) {
                $msg = $this->receive(max(0.1, $deadline - microtime(true)));
                if ($msg === null) break;
                [$type, $body] = $msg;
                if ($type === self::T_ERROR) throw new RuntimeException('Game server error: ' . $this->errorText($body) . '.');
                if ($type !== self::T_CHAT_REJECT) continue;
                $f = $this->pbDecode($body);
                if ((string) ($this->pbFirst($f, 1) ?? '') !== $text) continue;
                $limit = (int) ($this->pbFirst($f, 2) ?? 0) === 1;
                if ($offline) {
                    $why = $limit
                        ? 'too many messages are already waiting for this player.'
                        : 'no such account, or the server does not hold messages for offline players.';
                } else {
                    $why = $limit
                        ? 'too many messages are already waiting for this player (they are at a running table).'
                        : 'player is at a running table, or the chat rate limit was hit.';
                }
                return ['ok' => false, 'code' => 'rejected', 'msg' => "The game server rejected the message: {$why}"];
            }
            if ($offline) {
                return ['ok' => true, 'code' => 'queued', 'msg' => "\"{$nick}\" is offline – the message from \"{$this->cfg['nick']}\" is delivered at the next login (kept for 30 days)."];
            }
            return ['ok' => true, 'code' => 'sent', 'msg' => "Message sent to \"{$nick}\" as \"{$this->cfg['nick']}\"."];
        } catch (RuntimeException $e) {
            if ($e->getCode() === self::ERR_NAME_IN_USE) {
                return ['ok' => false, 'code' => 'in_use', 'msg' => "Not sent: \"{$this->cfg['nick']}\" is currently connected to the game server. Disconnect your client and send the message again."];
            }
            return ['ok' => false, 'code' => 'error', 'msg' => $e->getMessage()];
        } finally {
            $this->close();
        }
    }

    /**
     * Schickt eine Global Notice an alle verbundenen Clients.
     *
     * @return array{ok:bool, code:string, msg:string}
     */
    public function globalNotice(string $text): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'code' => 'not_configured', 'msg' => 'Game server admin account is not configured (PTH_ADMIN_BOT_* in .env).'];
        }
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'code' => 'invalid', 'msg' => 'The notice is empty.'];
        if (!mb_check_encoding($text, 'UTF-8')) return ['ok' => false, 'code' => 'invalid', 'msg' => 'The notice is not valid UTF-8.'];
        if (strlen($text) > self::NOTICE_MAX_BYTES) {
            return ['ok' => false, 'code' => 'invalid', 'msg' => 'The notice is longer than ' . self::NOTICE_MAX_BYTES . ' bytes (' . strlen($text) . ').'];
        }

        try {
            $this->connect();
            $this->login();
            $this->send(self::T_GLOBAL_NOTICE, $this->pbBytes(1, $text));

            $deadline = microtime(true) + (float) $this->cfg['timeout'];
            while (microtime(true) < $deadline) {
                $msg = $this->receive(max(0.1, $deadline - microtime(true)));
                if ($msg === null) break;
                [$type, $body] = $msg;
                if ($type === self::T_ERROR) {
                    // Ein Server ohne AdminGlobalNotice verwirft den Typ als ungültiges Paket.
                    $reason = (int) ($this->pbFirst($this->pbDecode($body), 1) ?? 0);
                    $hint = $reason === 9 ? ' – the game server probably does not support global notices yet' : '';
                    throw new RuntimeException('Game server error: ' . $this->errorText($body) . $hint . '.');
                }
                if ($type !== self::T_GLOBAL_NOTICE_ACK) continue;
                $result = (int) ($this->pbFirst($this->pbDecode($body), 1) ?? 1);
                return $result === 0
                    ? ['ok' => true, 'code' => 'accepted', 'msg' => 'Global notice sent to all connected players.']
                    : ['ok' => false, 'code' => 'invalid', 'msg' => 'The game server rejected the notice (bot is not an admin?).'];
            }
            return ['ok' => false, 'code' => 'error', 'msg' => 'No answer from the game server to the global notice (it may or may not have been sent).'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => 'error', 'msg' => $e->getMessage()];
        } finally {
            $this->close();
        }
    }

    /**
     * Lobby-Snapshot: Tische (offen = wartet auf Spieler, running = gestartet)
     * und Spieler mit Namen; "waiting" = an keinem Tisch, auch nicht als
     * Zuschauer. Der Bot selbst ist nicht enthalten.
     *
     * @return array{ok:bool, code:string, msg:string,
     *   games?:list<array{id:int,name:string,running:bool,players:int,max:int,type:string,private:bool}>,
     *   players?:list<array{id:int,name:string,game_id:?int,spectator:bool}>}
     */
    public function lobbySnapshot(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'code' => 'not_configured', 'msg' => 'Game server admin account is not configured (PTH_ADMIN_BOT_* in .env).'];
        }

        try {
            $this->connect();
            $this->login();
            [$ids, $games] = $this->collectLobby();
            $names = $this->resolveNames(array_keys($ids));

            $seat = [];   // playerId → [gameId, spectator]
            foreach ($games as $g) {
                foreach (array_keys($g['spectators']) as $pid) $seat[$pid] = [$g['id'], true];
                foreach (array_keys($g['players']) as $pid) $seat[$pid] = [$g['id'], false];
            }

            $players = [];
            foreach (array_keys($ids) as $pid) {
                $name = $names[$pid] ?? null;
                if ($name !== null && strcasecmp($name, (string) $this->cfg['nick']) === 0) continue;
                $players[] = [
                    'id' => $pid,
                    'name' => $name ?? "#{$pid}",
                    'game_id' => $seat[$pid][0] ?? null,
                    'spectator' => $seat[$pid][1] ?? false,
                ];
            }

            $list = array_map(fn ($g) => [
                'id' => $g['id'],
                'name' => $g['name'],
                'running' => $g['mode'] === 2, // netGameStarted
                'players' => count($g['players']),
                'max' => $g['max'],
                'type' => $g['type'],
                'private' => $g['private'],
            ], array_values($games));

            return ['ok' => true, 'code' => 'ok', 'msg' => count($list) . ' tables, ' . count($players) . ' players', 'games' => $list, 'players' => $players];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => 'error', 'msg' => $e->getMessage()];
        } finally {
            $this->close();
        }
    }

    // ── Ablauf ───────────────────────────────────────────────────────────

    private function login(): void
    {
        [$type, $body] = $this->receive();
        if ($type !== self::T_ANNOUNCE) throw new RuntimeException("Expected Announce from game server, got type {$type}.");
        $protocol = $this->pbDecode($this->pbFirst($this->pbDecode($body), 1) ?? '');
        $major = (int) ($this->pbFirst($protocol, 1) ?? 5);
        $minor = (int) ($this->pbFirst($protocol, 2) ?? 1);

        $init = $this->pbBytes(1, $this->pbVarint(1, $major) . $this->pbVarint(2, $minor))
            . $this->pbVarint(2, (int) $this->cfg['build_id'])
            . $this->pbVarint(5, 1) // authenticatedLogin
            . $this->pbBytes(6, (string) $this->cfg['nick'])
            . $this->pbBytes(7, (string) $this->cfg['password']);
        $this->send(self::T_INIT, $init);

        while (true) {
            [$type, $body] = $this->receive();
            if ($type === self::T_AUTH_CHALLENGE) {
                $this->send(self::T_AUTH_RESPONSE, $this->pbBytes(1, ''));
            } else if ($type === self::T_INIT_ACK) {
                return;
            } else if ($type === self::T_ERROR) {
                $reason = (int) ($this->pbFirst($this->pbDecode($body), 1) ?? 0);
                throw new RuntimeException('Game server refused the login of "' . $this->cfg['nick'] . '": ' . $this->errorText($body) . '.', $reason);
            }
        }
    }

    /**
     * Sammelt die Spielerliste, die der Server direkt nach dem Login schickt,
     * und löst die IDs zu Nicknames auf.
     *
     * @return array<int,string> uniqueId → Nickname
     */
    private function collectPlayers(): array
    {
        [$ids] = $this->collectLobby();
        return $this->resolveNames(array_keys($ids));
    }

    /**
     * Liest Spieler- und Tischliste mit, die der Server nach InitAck schickt,
     * inkl. Änderungen, die währenddessen eintreffen.
     *
     * @return array{0: array<int,true>, 1: array<int,array>} [Spieler-IDs, Tische]
     */
    private function collectLobby(): array
    {
        $ids = [];
        $games = [];
        // Die Listen kommen am Stück; Ende = kurze Funkstille. Die Obergrenze
        // greift, falls Lobby-Verkehr (Chat, Tischlisten) nie abreißt.
        $until = microtime(true) + 4;
        while (microtime(true) < $until && ($msg = $this->receive(0.6)) !== null) {
            [$type, $body] = $msg;
            $f = $this->pbDecode($body);
            $id = (int) ($this->pbFirst($f, 1) ?? 0);

            if ($type === self::T_PLAYER_LIST) {
                $left = (int) ($this->pbFirst($f, 2) ?? 0) === 1;
                if ($left) unset($ids[$id]); else $ids[$id] = true;
            } else if ($type === self::T_GAME_LIST_NEW) {
                $info = $this->pbDecode((string) ($this->pbFirst($f, 6) ?? ''));
                $games[$id] = [
                    'id' => $id,
                    'name' => (string) ($this->pbFirst($info, 1) ?? ''),
                    'mode' => (int) ($this->pbFirst($f, 2) ?? 1),
                    'players' => $this->packedIds($f[4] ?? []),
                    'spectators' => $this->packedIds($f[7] ?? []),
                    'max' => (int) ($this->pbFirst($info, 3) ?? 0),
                    'type' => [1 => 'normal', 2 => 'registered', 3 => 'invite', 4 => 'ranking'][(int) ($this->pbFirst($info, 2) ?? 1)] ?? 'normal',
                    'private' => (bool) ($this->pbFirst($f, 3) ?? 0),
                ];
            } else if ($type === self::T_GAME_LIST_UPDATE && isset($games[$id])) {
                $mode = (int) ($this->pbFirst($f, 2) ?? 1);
                if ($mode === 3) unset($games[$id]); // netGameClosed
                else $games[$id]['mode'] = $mode;
            } else if ($type === self::T_GAME_LIST_JOINED && isset($games[$id])) {
                $games[$id]['players'][(int) ($this->pbFirst($f, 2) ?? 0)] = true;
            } else if ($type === self::T_GAME_LIST_LEFT && isset($games[$id])) {
                unset($games[$id]['players'][(int) ($this->pbFirst($f, 2) ?? 0)]);
            }
        }
        return [$ids, $games];
    }

    /**
     * packed repeated uint32 → Set; unpacked (Wire-Typ 0) wäre schon ein int.
     *
     * @return array<int,true>
     */
    private function packedIds(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_int($v)) { $out[$v] = true; continue; }
            $pos = 0;
            while ($pos < strlen($v)) $out[$this->readVarint($v, $pos)] = true;
        }
        return $out;
    }

    /** @return array<int,string> uniqueId → Nickname */
    private function resolveNames(array $ids): array
    {
        $names = [];
        if (!$ids) return $names;
        foreach (array_chunk($ids, self::INFO_BATCH) as $chunk) {
            $packed = '';
            foreach ($chunk as $id) $packed .= $this->varint($id);
            $this->send(self::T_PLAYER_INFO_REQ, $this->pbBytes(1, $packed));
            $pending = array_flip($chunk);
            $deadline = microtime(true) + 5;
            while ($pending && microtime(true) < $deadline) {
                $msg = $this->receive(max(0.1, $deadline - microtime(true)));
                if ($msg === null) break;
                [$type, $body] = $msg;
                if ($type !== self::T_PLAYER_INFO_REP) continue;
                $fields = $this->pbDecode($body);
                $id = (int) ($this->pbFirst($fields, 1) ?? 0);
                unset($pending[$id]);
                $data = $this->pbFirst($fields, 2);
                if ($data === null) continue;
                $name = $this->pbFirst($this->pbDecode($data), 1);
                if ($name !== null) $names[$id] = (string) $name;
            }
        }
        return $names;
    }

    /** Exakter Treffer zuerst, dann ohne Groß-/Kleinschreibung (wie der Server, ci-Collation). */
    private function findUniqueId(array $players, string $nick): ?int
    {
        foreach ($players as $id => $name) {
            if ($name === $nick) return $id;
        }
        foreach ($players as $id => $name) {
            if (strcasecmp($name, $nick) === 0) return $id;
        }
        return null;
    }

    private function awaitBanResult(int $uniqueId): int
    {
        $result = null;
        $deadline = microtime(true) + (float) $this->cfg['timeout'];
        while (microtime(true) < $deadline) {
            $msg = $this->receive(max(0.1, $deadline - microtime(true)));
            if ($msg === null) break;
            [$type, $body] = $msg;
            if ($type === self::T_ERROR) throw new RuntimeException('Game server error: ' . $this->errorText($body) . '.');
            if ($type !== self::T_ADMIN_BAN_ACK) continue;
            $fields = $this->pbDecode($body);
            if ((int) ($this->pbFirst($fields, 1) ?? 0) !== $uniqueId) continue;
            $result = (int) ($this->pbFirst($fields, 2) ?? 4);
            // pending = DB-Bann läuft asynchron, das endgültige Ack folgt.
            if ($result !== 1) return $result;
        }
        if ($result === null) throw new RuntimeException('No answer from the game server to the ban request.');
        return $result;
    }

    private function errorText(string $body): string
    {
        $reason = (int) ($this->pbFirst($this->pbDecode($body), 1) ?? 0);
        return self::ERROR_REASONS[$reason] ?? "error {$reason}";
    }

    // ── PokerTH-Envelope ─────────────────────────────────────────────────

    private function send(int $type, string $payload): void
    {
        $this->wsSend($this->pbVarint(1, $type) . $this->pbBytes($type + 1, $payload));
    }

    /**
     * Nächste PokerTH-Nachricht als [Typ, Payload]; null bei Timeout, wenn
     * $timeout gesetzt ist.
     */
    private function receive(?float $timeout = null): ?array
    {
        $frame = $this->wsReceive($timeout ?? (float) $this->cfg['timeout']);
        if ($frame === null) {
            if ($timeout !== null) return null;
            throw new RuntimeException('Timeout waiting for the game server.');
        }
        $fields = $this->pbDecode($frame);
        $type = (int) ($this->pbFirst($fields, 1) ?? 0);
        return [$type, (string) ($this->pbFirst($fields, $type + 1) ?? '')];
    }

    // ── Protobuf (nur was gebraucht wird) ────────────────────────────────

    private function varint(int $v): string
    {
        $out = '';
        do {
            $byte = $v & 0x7f;
            $v >>= 7;
            $out .= chr($v ? $byte | 0x80 : $byte);
        } while ($v);
        return $out;
    }

    private function pbVarint(int $field, int $v): string
    {
        return $this->varint($field << 3) . $this->varint($v);
    }

    private function pbBytes(int $field, string $data): string
    {
        return $this->varint(($field << 3) | 2) . $this->varint(strlen($data)) . $data;
    }

    /** @return array<int, list<int|string>> Feldnummer → Werte */
    private function pbDecode(string $buf): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($buf);
        while ($pos < $len) {
            $key = $this->readVarint($buf, $pos);
            $field = $key >> 3;
            switch ($key & 7) {
                case 0: $out[$field][] = $this->readVarint($buf, $pos); break;
                case 1: $out[$field][] = substr($buf, $pos, 8); $pos += 8; break;
                case 2:
                    $l = $this->readVarint($buf, $pos);
                    $out[$field][] = substr($buf, $pos, $l);
                    $pos += $l;
                    break;
                case 5: $out[$field][] = substr($buf, $pos, 4); $pos += 4; break;
                default: throw new RuntimeException('Malformed packet from the game server.');
            }
        }
        return $out;
    }

    private function pbFirst(array $fields, int $field)
    {
        return $fields[$field][0] ?? null;
    }

    private function readVarint(string $buf, int &$pos): int
    {
        $v = 0;
        $shift = 0;
        $len = strlen($buf);
        while ($pos < $len) {
            $b = ord($buf[$pos++]);
            $v |= ($b & 0x7f) << $shift;
            if (!($b & 0x80)) return $v;
            $shift += 7;
            if ($shift > 63) break;
        }
        throw new RuntimeException('Malformed varint from the game server.');
    }

    // ── WebSocket (RFC 6455, Client-Seite) ───────────────────────────────

    private function connect(): void
    {
        $host = (string) $this->cfg['host'];
        $port = (int) $this->cfg['port'];
        $scheme = $this->cfg['tls'] ? 'tls' : 'tcp';
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $errstr, (float) $this->cfg['timeout'], STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new RuntimeException("Cannot connect to the game server ({$errstr}).");
        $this->sock = $sock;
        $this->buffer = '';

        $key = base64_encode(random_bytes(16));
        $request = 'GET ' . ($this->cfg['resource'] ?: '/') . " HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . ($this->cfg['origin'] ? 'Origin: ' . $this->cfg['origin'] . "\r\n" : '')
            . "User-Agent: pthranking-admin\r\n\r\n";
        $this->write($request);

        $deadline = microtime(true) + (float) $this->cfg['timeout'];
        while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
            if (!$this->fill($deadline)) throw new RuntimeException('Game server did not answer the WebSocket handshake.');
        }
        $head = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 4);
        if (!preg_match('#^HTTP/1\.1 101#', $head)) {
            throw new RuntimeException('WebSocket handshake rejected: ' . strtok($head, "\r\n"));
        }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $m) || $m[1] !== $accept) {
            throw new RuntimeException('WebSocket handshake: invalid Sec-WebSocket-Accept.');
        }
    }

    private function close(): void
    {
        if (!$this->sock) return;
        try {
            $this->wsFrame(0x8, pack('n', 1000));
        } catch (\Throwable $e) {
        }
        @fclose($this->sock);
        $this->sock = null;
    }

    private function wsSend(string $payload): void
    {
        $this->wsFrame(0x2, $payload);
    }

    private function wsFrame(int $opcode, string $payload): void
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($len < 126) $head .= chr(0x80 | $len);
        else if ($len < 65536) $head .= chr(0x80 | 126) . pack('n', $len);
        else $head .= chr(0x80 | 127) . pack('J', $len);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $mask[$i & 3];
        $this->write($head . $mask . $masked);
    }

    /** Nächste vollständige Binärnachricht, null bei Timeout. */
    private function wsReceive(float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;
        $message = '';
        while (true) {
            if (!$this->need(2, $deadline)) return null;
            $b0 = ord($this->buffer[0]);
            $b1 = ord($this->buffer[1]);
            $len = $b1 & 0x7f;
            $off = 2;
            if ($len === 126) {
                if (!$this->need(4, $deadline)) return null;
                $len = unpack('n', substr($this->buffer, 2, 2))[1];
                $off = 4;
            } else if ($len === 127) {
                if (!$this->need(10, $deadline)) return null;
                $len = unpack('J', substr($this->buffer, 2, 8))[1];
                $off = 10;
            }
            $maskKey = '';
            if ($b1 & 0x80) {
                if (!$this->need($off + 4, $deadline)) return null;
                $maskKey = substr($this->buffer, $off, 4);
                $off += 4;
            }
            if (!$this->need($off + $len, $deadline)) return null;
            $payload = substr($this->buffer, $off, $len);
            $this->buffer = substr($this->buffer, $off + $len);
            if ($maskKey !== '') {
                for ($i = 0; $i < $len; $i++) $payload[$i] = $payload[$i] ^ $maskKey[$i & 3];
            }

            $opcode = $b0 & 0x0f;
            if ($opcode === 0x9) { $this->wsFrame(0xA, $payload); continue; }
            if ($opcode === 0xA) continue;
            if ($opcode === 0x8) throw new RuntimeException('Game server closed the connection.');
            $message .= $payload;
            if ($b0 & 0x80) {
                if ($opcode === 0x1) { $message = ''; continue; } // Textframes ignorieren
                return $message;
            }
        }
    }

    private function need(int $bytes, float $deadline): bool
    {
        while (strlen($this->buffer) < $bytes) {
            if (!$this->fill($deadline)) return false;
        }
        return true;
    }

    private function fill(float $deadline): bool
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) return false;
        $read = [$this->sock];
        $w = $e = null;
        $n = @stream_select($read, $w, $e, (int) $left, (int) (($left - (int) $left) * 1e6));
        if (!$n) return false;
        $chunk = fread($this->sock, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($this->sock)) throw new RuntimeException('Game server closed the connection.');
            return false;
        }
        $this->buffer .= $chunk;
        return true;
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $n = @fwrite($this->sock, $data);
            if ($n === false || $n === 0) throw new RuntimeException('Write to the game server failed.');
            $data = substr($data, $n);
        }
    }
}
