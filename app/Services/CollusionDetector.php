<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Finds "black sheep": accounts controlled by ONE person that sit together at
 * RANKED tables (self-collusion / multiple seats at one sit-and-go, chip
 * dumping between a person's own nicks).
 *
 * Method, calibrated on a real multi-seat case (Nov 2026) where one operator
 * put several of their own nicks at the same ranked table:
 *
 *  1. IP-POOL LINKAGE instead of "same IP in the same game". An operator uses a
 *     different connection/VPN per seat, and the opposing seat's IP is only
 *     known to the game server, never recorded in the game itself. Across ALL
 *     sessions (server_session.ip) plus player.last_ip the IP pool overlaps
 *     though - this is also what the dashboard's player page shows as "related
 *     accounts".
 *
 *  2. SHARED RANKED TABLE. Only once two IP-linked accounts repeatedly sat at
 *     the same game_has_player.game_idgame is it manipulation - two household
 *     members at different tables are not. Casual / non-ranked tables do not
 *     count (only game_has_player is the ranked history).
 *
 *  3. BOOSTERS. Same password (AES blob, see listener::ranking_password_sql)
 *     or a very similar nick strengthen "one person"; an IP used by only a few
 *     accounts is private (strong), one used by many is public/VPN (weak,
 *     CGNAT/university).
 *
 * A shared IP stays an indication, not proof: the command lists candidates,
 * the human makes the call (kickban / void points).
 */
class CollusionDetector
{
    /** Webclient proxy + loopback: would otherwise link completely unrelated accounts. */
    private const EXCLUDE_IPS = [
        '10.7.7.160', '::ffff:10.7.7.160',
        '127.0.0.1', '::ffff:127.0.0.1', '::1',
    ];

    private const BOT_NICKS = ['bbcbot'];

    private const BANNED = 4; // ReportStats::PLAYER_BANNED

    /** Confidence ordering for the --confidence filter (higher = stronger). */
    private const CONFIDENCE_ORDER = ['review' => 1, 'medium' => 2, 'high' => 3];

    /**
     * @param array{days?:?int,minTables?:int,ipMaxAccounts?:int,player?:string|int|null,limit?:int,minConfidence?:?string} $opts
     * @return array{rings:array<int,array<string,mixed>>,total:int,params:array<string,mixed>}
     */
    public function detect(array $opts = []): array
    {
        $days          = isset($opts['days']) && $opts['days'] !== null ? max(1, (int) $opts['days']) : null;
        $minTables     = max(1, (int) ($opts['minTables'] ?? 2));
        $ipMaxAccounts = max(2, (int) ($opts['ipMaxAccounts'] ?? 12));
        $limit         = max(1, (int) ($opts['limit'] ?? 100));
        $focusId       = $this->resolvePlayer($opts['player'] ?? null);
        $minConfRank   = self::CONFIDENCE_ORDER[$opts['minConfidence'] ?? ''] ?? 0;

        $botIds = $this->botIds();

        // 1) Pairs that sat at the same ranked table >= minTables times.
        //    The focus does NOT filter here - otherwise members that only link
        //    to the focus account through a third party (e.g. the central nick)
        //    would be lost. It is applied to the finished rings instead.
        $pairs = $this->coTablePairs($days, $minTables, $botIds);
        if (!$pairs) {
            return ['rings' => [], 'total' => 0, 'params' => $this->params($days, $minTables, $ipMaxAccounts, $focusId)];
        }

        // Collect the candidate player set.
        $ids = [];
        foreach ($pairs as $p) { $ids[$p->p1] = true; $ids[$p->p2] = true; }
        $ids = array_map('intval', array_keys($ids));

        $ipByPlayer = $this->ipSets($ids, $botIds);          // player_id => [ip => true]
        $ipAccounts = $this->ipAccountCounts($ipByPlayer, $botIds); // ip => #distinct accounts (global)
        $pwByPlayer = $this->passwordHashes($ids);           // player_id => sha256(plaintext) | null
        $meta       = $this->playerMeta($ids);               // player_id => {username,last_login,active}

        // 2) Only IP-linked (or password-equal) pairs become edges.
        $edges = [];
        foreach ($pairs as $p) {
            $a = (int) $p->p1; $b = (int) $p->p2;
            $sharedIps = array_keys(array_intersect_key($ipByPlayer[$a] ?? [], $ipByPlayer[$b] ?? []));

            $minAccts = null;
            foreach ($sharedIps as $ip) {
                $n = $ipAccounts[$ip] ?? PHP_INT_MAX;
                $minAccts = $minAccts === null ? $n : min($minAccts, $n);
            }
            // "private enough" = at least one shared IP used by few accounts.
            $ipLinked = $minAccts !== null && $minAccts <= $ipMaxAccounts;

            $pwMatch = isset($pwByPlayer[$a], $pwByPlayer[$b])
                && $pwByPlayer[$a] !== null && $pwByPlayer[$a] === $pwByPlayer[$b];

            if (!$ipLinked && !$pwMatch) {
                continue; // table overlap without linkage -> not a candidate
            }

            $nameSim = $this->nameSimilar($meta[$a]->username ?? '', $meta[$b]->username ?? '');

            $edges[] = [
                'a' => $a, 'b' => $b,
                'tables' => (int) $p->tables,
                'last_seen' => $p->last_seen,
                'shared_ips' => $sharedIps,
                'min_ip_accounts' => $minAccts,
                'ip_linked' => $ipLinked,
                'pw_match' => $pwMatch,
                'name_similar' => $nameSim,
            ];
        }
        if (!$edges) {
            return ['rings' => [], 'total' => 0, 'params' => $this->params($days, $minTables, $ipMaxAccounts, $focusId)];
        }

        // 3) Connected components of the edges = rings.
        $rings = $this->components($edges);

        // Focus: keep only the ring that contains the requested account.
        if ($focusId !== null) {
            $rings = array_values(array_filter($rings, fn ($m) => in_array($focusId, $m, true)));
        }

        // Current season ranking per member (for prioritisation).
        $ranking = $this->rankingInfo($ids);

        // Describe + score the rings.
        $out = [];
        foreach ($rings as $memberIds) {
            $out[] = $this->describeRing($memberIds, $edges, $meta, $ipByPlayer, $ipAccounts, $pwByPlayer, $ranking, $days, $botIds);
        }

        // Sort by priority: best (lowest) member rank first - a ring with a
        // top-ranked player hurts the leaderboard most. Rings without any
        // ranked member go last; ties break on collusion score, then recency.
        usort($out, function ($x, $y) {
            $rx = $x['top_rank'] ?? PHP_INT_MAX;
            $ry = $y['top_rank'] ?? PHP_INT_MAX;
            if ($rx !== $ry) return $rx <=> $ry;                 // lower rank first
            if ($x['score'] !== $y['score']) return $y['score'] <=> $x['score']; // higher score first
            return strcmp((string) $y['last_seen'], (string) $x['last_seen']);   // more recent first
        });

        // Confidence filter (keep rings at or above the requested level).
        if ($minConfRank > 0) {
            $out = array_values(array_filter(
                $out,
                fn ($r) => (self::CONFIDENCE_ORDER[$r['confidence']] ?? 0) >= $minConfRank
            ));
        }

        $total = count($out);

        return [
            'rings'  => array_slice($out, 0, $limit),
            'total'  => $total,
            'params' => $this->params($days, $minTables, $ipMaxAccounts, $focusId),
        ];
    }

    /** Pairs at the same ranked table (self-join over game_has_player). */
    private function coTablePairs(?int $days, int $minTables, array $botIds): array
    {
        $botPh = implode(',', array_fill(0, count($botIds), '?'));
        $where = ["a.player_idplayer NOT IN ($botPh)", "b.player_idplayer NOT IN ($botPh)"];
        $bind  = array_merge($botIds, $botIds);

        if ($days !== null) {
            $where[] = 'a.start_time >= (NOW() - INTERVAL ? DAY)';
            $bind[]  = $days;
        }
        $bind[] = $minTables;

        return DB::select(
            'SELECT a.player_idplayer AS p1, b.player_idplayer AS p2,
                    COUNT(DISTINCT a.game_idgame) AS tables, MAX(a.start_time) AS last_seen
               FROM game_has_player a
               JOIN game_has_player b
                 ON b.game_idgame = a.game_idgame
                AND b.player_idplayer > a.player_idplayer
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY a.player_idplayer, b.player_idplayer
             HAVING tables >= ?',
            $bind
        );
    }

    /** player_id => set of its IPs (last_ip + all session IPs), proxy/loopback removed. */
    private function ipSets(array $ids, array $botIds): array
    {
        $out = array_fill_keys($ids, []);
        $idPh = implode(',', array_fill(0, count($ids), '?'));

        foreach (DB::select(
            "SELECT player_id, last_ip FROM player WHERE player_id IN ($idPh) AND last_ip <> ''",
            $ids
        ) as $r) {
            if ($this->usableIp($r->last_ip)) $out[(int) $r->player_id][$r->last_ip] = true;
        }

        foreach (DB::select(
            "SELECT DISTINCT player_id, INET6_NTOA(ip) AS ip FROM server_session
              WHERE player_id IN ($idPh) AND ip IS NOT NULL",
            $ids
        ) as $r) {
            if ($r->ip !== null && $this->usableIp($r->ip)) $out[(int) $r->player_id][$r->ip] = true;
        }

        return $out;
    }

    /** For each IP seen: how many distinct accounts use it globally (last_ip + sessions). */
    private function ipAccountCounts(array $ipByPlayer, array $botIds): array
    {
        $ips = [];
        foreach ($ipByPlayer as $set) foreach ($set as $ip => $_) $ips[$ip] = true;
        $ips = array_keys($ips);
        if (!$ips) return [];

        $counts = [];
        // In chunks so the IN() list stays bounded.
        foreach (array_chunk($ips, 500) as $chunk) {
            $in     = implode(',', array_fill(0, count($chunk), 'INET6_ATON(?)'));
            $botPh  = implode(',', array_fill(0, count($botIds), '?'));
            $acc = [];
            foreach (DB::select(
                "SELECT INET6_NTOA(ip) AS ip, player_id FROM server_session
                  WHERE ip IN ($in) AND player_id IS NOT NULL AND player_id NOT IN ($botPh)",
                array_merge($chunk, $botIds)
            ) as $r) {
                $acc[$r->ip][(int) $r->player_id] = true;
            }
            $likePh = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::select(
                "SELECT last_ip AS ip, player_id FROM player
                  WHERE last_ip IN ($likePh) AND player_id NOT IN ($botPh)",
                array_merge($chunk, $botIds)
            ) as $r) {
                $acc[$r->ip][(int) $r->player_id] = true;
            }
            foreach ($chunk as $ip) $counts[$ip] = isset($acc[$ip]) ? count($acc[$ip]) : 0;
        }
        return $counts;
    }

    /** player_id => sha256(plaintext password) | null. Plaintext is never returned. */
    private function passwordHashes(array $ids): array
    {
        $key = $this->aesKey();
        if ($key === null) return [];
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::select(
            "SELECT player_id, password FROM player WHERE player_id IN ($idPh) AND password IS NOT NULL",
            $ids
        ) as $r) {
            $pt = openssl_decrypt($r->password, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
            $out[(int) $r->player_id] = $pt === false ? null : hash('sha256', $pt);
        }
        return $out;
    }

    /**
     * Current season ranking per player: position (like PlayerController - the
     * number of non-deleted accounts with final_score >= theirs), score and
     * season games. The position is computed once over all scores instead of a
     * count query per player.
     *
     * @return array<int,array{rank:?int,score:?int,games:?int}>
     */
    private function rankingInfo(array $ids): array
    {
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("SELECT player_id, final_score, season_games FROM player_ranking WHERE player_id IN ($idPh)", $ids);
        if (!$rows) return [];

        // All scores of the rankable (non-deleted) accounts, ascending.
        $scores = array_map('intval', DB::table('player_ranking')
            ->where('username', 'NOT LIKE', 'deleted_%')
            ->orderBy('final_score')->pluck('final_score')->all());
        $total = count($scores);

        $out = [];
        foreach ($rows as $r) {
            $score = (int) $r->final_score;
            // position = number of scores >= score = total - (first index with score >= score).
            $rank = $total - $this->lowerBound($scores, $score);
            $out[(int) $r->player_id] = [
                'rank'  => $rank > 0 ? $rank : 1,
                'score' => $score,
                'games' => (int) $r->season_games,
            ];
        }
        return $out;
    }

    /** Index of the first element >= $x in the ascending-sorted $a. */
    private function lowerBound(array $a, int $x): int
    {
        $lo = 0; $hi = count($a);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($a[$mid] < $x) $lo = $mid + 1; else $hi = $mid;
        }
        return $lo;
    }

    private function playerMeta(array $ids): array
    {
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::select(
            "SELECT player_id, username, last_login, active FROM player WHERE player_id IN ($idPh)",
            $ids
        ) as $r) {
            $out[(int) $r->player_id] = $r;
        }
        return $out;
    }

    private function describeRing(array $memberIds, array $edges, array $meta, array $ipByPlayer, array $ipAccounts, array $pwByPlayer, array $ranking, ?int $days, array $botIds): array
    {
        sort($memberIds);
        $set = array_fill_keys($memberIds, true);

        // Password groups: members whose password blob is shared by >= 2 members
        // of THIS ring get a group letter (A, B, …). Same letter = same password.
        $hashCount = [];
        foreach ($memberIds as $id) {
            $h = $pwByPlayer[$id] ?? null;
            if ($h !== null) $hashCount[$h] = ($hashCount[$h] ?? 0) + 1;
        }
        $pwGroupOf = [];
        foreach ($memberIds as $id) {
            $h = $pwByPlayer[$id] ?? null;
            if ($h !== null && ($hashCount[$h] ?? 0) >= 2 && !isset($pwGroupOf[$h])) {
                $n = count($pwGroupOf);
                $pwGroupOf[$h] = $n < 26 ? chr(65 + $n) : 'A' . ($n - 25);
            }
        }

        $lastSeen = null; $pwMatch = false; $nameSim = false; $minAccts = null;
        $ips = [];
        foreach ($edges as $e) {
            if (!isset($set[$e['a']], $set[$e['b']])) continue;
            $lastSeen = $lastSeen === null ? $e['last_seen'] : max($lastSeen, $e['last_seen']);
            $pwMatch  = $pwMatch || $e['pw_match'];
            $nameSim  = $nameSim || $e['name_similar'];
            if ($e['min_ip_accounts'] !== null) {
                $minAccts = $minAccts === null ? $e['min_ip_accounts'] : min($minAccts, $e['min_ip_accounts']);
            }
            foreach ($e['shared_ips'] as $ip) $ips[$ip] = true;
        }

        // Number of ranked tables where >= 2 ring members sat together.
        $tablesTotal = $this->ringSharedTables($memberIds, $days, $botIds);

        // Score: tables dominate, linkage strength modulates.
        $score = $tablesTotal
            + ($pwMatch ? 5 : 0)
            + ($nameSim ? 3 : 0)
            + ($minAccts !== null && $minAccts <= 3 ? 3 : 0)
            + count($memberIds); // larger rings = more systematic

        $members = [];
        $topRank = null; // best (lowest) rank in the ring
        foreach ($memberIds as $id) {
            $m = $meta[$id] ?? null;
            $r = $ranking[$id] ?? null;
            $rank = $r['rank'] ?? null;
            if ($rank !== null) $topRank = $topRank === null ? $rank : min($topRank, $rank);
            $h = $pwByPlayer[$id] ?? null;
            $members[] = [
                'player_id'  => $id,
                'username'   => $m->username ?? '?',
                'last_login' => $m->last_login ?? null,
                'banned'     => $m !== null && (int) $m->active === self::BANNED,
                'ips'        => count($ipByPlayer[$id] ?? []),
                'rank'       => $rank,
                'score'      => $r['score'] ?? null,
                'games'      => $r['games'] ?? null,
                'pw_group'   => $h !== null ? ($pwGroupOf[$h] ?? null) : null,
            ];
        }

        // Members: best rank first, unranked last.
        usort($members, fn ($a, $b) => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

        return [
            'members'       => $members,
            'size'          => count($memberIds),
            'shared_tables' => $tablesTotal,
            'last_seen'     => $lastSeen,
            'shared_ips'    => array_values(array_slice(array_keys($ips), 0, 20)),
            'min_ip_accounts' => $minAccts,
            'pw_match'      => $pwMatch,
            'name_similar'  => $nameSim,
            'confidence'    => $this->confidence($pwMatch, $nameSim, $minAccts),
            'top_rank'      => $topRank,
            'score'         => $score,
        ];
    }

    /** Number of ranked tables where >= 2 of the ring's members sat. */
    private function ringSharedTables(array $memberIds, ?int $days, array $botIds): int
    {
        $in   = implode(',', array_fill(0, count($memberIds), '?'));
        $bind = $memberIds;
        $extra = '';
        if ($days !== null) { $extra = ' AND start_time >= (NOW() - INTERVAL ? DAY)'; $bind[] = $days; }

        $row = DB::selectOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT game_idgame FROM game_has_player
                 WHERE player_idplayer IN ($in){$extra}
                 GROUP BY game_idgame HAVING COUNT(DISTINCT player_idplayer) >= 2
             ) t",
            $bind
        );
        return (int) ($row->c ?? 0);
    }

    private function confidence(bool $pwMatch, bool $nameSim, ?int $minAccts): string
    {
        if ($pwMatch || $nameSim || ($minAccts !== null && $minAccts <= 3)) return 'high';
        if ($minAccts !== null && $minAccts <= 6) return 'medium';
        return 'review';
    }

    /** Union-find over the edges -> list of member id lists. */
    private function components(array $edges): array
    {
        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            while (($parent[$x] ?? $x) !== $x) {
                $parent[$x] = $parent[$parent[$x] ?? $x] ?? $parent[$x];
                $x = $parent[$x];
            }
            return $x;
        };
        $union = function ($a, $b) use (&$parent, $find) {
            $ra = $find($a); $rb = $find($b);
            if ($ra !== $rb) $parent[$ra] = $rb;
        };
        foreach ($edges as $e) {
            $parent[$e['a']] = $parent[$e['a']] ?? $e['a'];
            $parent[$e['b']] = $parent[$e['b']] ?? $e['b'];
            $union($e['a'], $e['b']);
        }
        $groups = [];
        foreach ($parent as $node => $_) $groups[$find($node)][] = $node;
        return array_values($groups);
    }

    private function nameSimilar(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a)); $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        if (mb_strlen($a) >= 3 && mb_strlen($b) >= 3 && (str_contains($a, $b) || str_contains($b, $a))) return true;
        return levenshtein($a, $b) <= 2;
    }

    private function usableIp(?string $ip): bool
    {
        if ($ip === null || $ip === '') return false;
        if (in_array($ip, self::EXCLUDE_IPS, true)) return false;
        return (bool) filter_var($ip, FILTER_VALIDATE_IP);
    }

    private function resolvePlayer($player): ?int
    {
        if ($player === null || $player === '') return null;
        if (is_int($player) || ctype_digit((string) $player)) return (int) $player;
        $id = DB::table('player')->where('username', (string) $player)->value('player_id');
        return $id !== null ? (int) $id : -1; // -1 = no match, yields an empty set
    }

    private function aesKey(): ?string
    {
        $salt = (string) env('APP_SALT', '');
        if ($salt === '') return null;
        $key = str_repeat("\0", 16);
        for ($i = 0, $n = strlen($salt); $i < $n; $i++) {
            $key[$i % 16] = chr(ord($key[$i % 16]) ^ ord($salt[$i]));
        }
        return $key;
    }

    /** @return int[] */
    private function botIds(): array
    {
        $nicks = array_values(array_unique(array_filter(
            array_merge([(string) config('services.game_server_admin.nick')], self::BOT_NICKS)
        )));
        if (!$nicks) return [0];
        $ids = DB::table('player')->whereIn('username', $nicks)->pluck('player_id')->map(fn ($v) => (int) $v)->all();
        return $ids ?: [0];
    }

    private function params(?int $days, int $minTables, int $ipMaxAccounts, ?int $focusId): array
    {
        return compact('days', 'minTables', 'ipMaxAccounts', 'focusId');
    }
}
