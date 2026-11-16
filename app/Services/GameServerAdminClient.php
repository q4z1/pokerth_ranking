<?php

namespace App\Services;

use RuntimeException;

/**
 * Minimaler PokerTH-Client über die WebSocket-Schnittstelle des Game-Servers,
 * nur für Admin-Aktionen (derzeit: Kickban).
 *
 * Ablauf (siehe ServerLobbyThread im pokerth-Repo):
 *   Announce ← | → Init (authenticatedLogin, Passwort in clientUserData)
 *   AuthServerChallenge ← | → AuthClientResponse (leer, SCRAM ist serverseitig entfernt)
 *   InitAck ← | PlayerList(new)* ← | → PlayerInfoRequest | PlayerInfoReply* ←
 *   → AdminBanPlayer(uniqueId) | AdminBanPlayerAck(pending) ← | AdminBanPlayerAck(accepted|dbError) ←
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
    private const T_PLAYER_INFO_REQ = 18;
    private const T_PLAYER_INFO_REP = 19;
    private const T_ERROR           = 73;
    private const T_ADMIN_BAN       = 76;
    private const T_ADMIN_BAN_ACK   = 77;

    private const BAN_RESULTS = [
        0 => 'accepted',
        1 => 'pending',
        2 => 'no_db',
        3 => 'db_error',
        4 => 'invalid',
    ];

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

            $uniqueId = null;
            foreach ($players as $id => $name) {
                if ($name === $nick) { $uniqueId = $id; break; }
            }
            if ($uniqueId === null) {
                // Der Server vergleicht Namen ohne Groß-/Kleinschreibung (PAD SPACE /
                // ci-Collation), also als zweiter Versuch ebenso.
                foreach ($players as $id => $name) {
                    if (strcasecmp($name, $nick) === 0) { $uniqueId = $id; break; }
                }
            }
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
                throw new RuntimeException('Game server refused the bot login: ' . $this->errorText($body) . '.');
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
        $ids = [];
        // Die Liste kommt am Stück; Ende = kurze Funkstille. Die Obergrenze
        // greift, falls Lobby-Verkehr (Chat, Tischlisten) nie abreißt.
        $until = microtime(true) + 4;
        while (microtime(true) < $until && ($msg = $this->receive(0.6)) !== null) {
            [$type, $body] = $msg;
            if ($type !== self::T_PLAYER_LIST) continue;
            $fields = $this->pbDecode($body);
            $id = (int) ($this->pbFirst($fields, 1) ?? 0);
            $left = (int) ($this->pbFirst($fields, 2) ?? 0) === 1;
            if ($left) unset($ids[$id]); else $ids[$id] = true;
        }

        $names = [];
        if (!$ids) return $names;
        foreach (array_chunk(array_keys($ids), self::INFO_BATCH) as $chunk) {
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
