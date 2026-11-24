<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Expands a ring of one person's accounts from known seed accounts and spots
 * fresh throwaway accounts - built for VPN-hopping ban evaders where neither a
 * client fingerprint nor a fixed IP is usable. Seeds live in config/rings.php
 * (real names only in the gitignored config/rings.local.php).
 *
 * Linkage signals (one hop from the seeds):
 *   - STRONG: same exact IP (session/last_ip) used by only a few accounts; same
 *     password blob (AES-ECB is deterministic, same plaintext => same bytes, no
 *     decryption needed); same, rare avatar hash.
 *   - WEAK: same /24 of a sparsely used (VPN) range - catches the next
 *     throwaway account but also pulls in unrelated VPN users, so review only.
 *
 * Everything stays an indication. The command highlights NEW accounts
 * (created <= N days) so the moderator can ban them before the next damage.
 */
class RingTracker
{
    private const EXCLUDE_IPS = [
        '10.7.7.160', '::ffff:10.7.7.160',
        '127.0.0.1', '::ffff:127.0.0.1', '::1',
    ];

    private const BANNED = 4; // ReportStats::PLAYER_BANNED

    /**
     * @param string[]|int[] $seeds  Nicknames or player ids
     * @param array{newDays?:int,ipMax?:int,subnetMax?:int,avatarMax?:int,pwMax?:int,showWeak?:bool} $opts
     */
    public function track(array $seeds, array $opts = []): array
    {
        $newDays   = max(1, (int) ($opts['newDays'] ?? 7));
        $ipMax     = max(1, (int) ($opts['ipMax'] ?? 4));
        $subnetMax = max(1, (int) ($opts['subnetMax'] ?? 6));
        $avatarMax = max(1, (int) ($opts['avatarMax'] ?? 5));
        $pwMax     = max(1, (int) ($opts['pwMax'] ?? 8));
        $showWeak  = (bool) ($opts['showWeak'] ?? false);

        $seedIds  = $this->resolveSeeds($seeds, $missing);
        if (!$seedIds) {
            return ['members' => [], 'missing' => $missing, 'params' => compact('newDays', 'ipMax', 'subnetMax', 'avatarMax')];
        }

        // Collect the seeds' signals.
        $ips     = $this->ipSets($seedIds);                 // id => [ip,...]
        $allIps  = array_values(array_unique(array_merge(...array_values($ips) ?: [[]])));
        $pwBlobs = $this->passwordBlobs($seedIds);          // id => hex blob
        $avatars = $this->avatars($seedIds);                // id => hash

        // How "private" is each IP / each /24 globally?
        $ipAcc     = $this->ipAccountCounts($allIps);
        $subnetAcc = $this->subnetAccountCounts($allIps);

        $strongIps = array_values(array_filter($allIps, fn ($ip) => ($ipAcc[$ip] ?? 0) <= $ipMax));
        $rareSubs  = [];
        foreach ($allIps as $ip) {
            $s = $this->subnet($ip);
            if ($s !== null && ($subnetAcc[$s] ?? 0) <= $subnetMax) $rareSubs[$s] = true;
        }
        $rareSubs = array_keys($rareSubs);

        // Find candidates (one hop).
        $links = []; // id => ['type'=>strong|weak, 'why'=>[...]]
        foreach ($seedIds as $id) $links[$id] = ['type' => 'seed', 'why' => []];

        $this->addExactIpMatches($strongIps, $seedIds, $links);
        $this->addPasswordMatches(array_values(array_unique($pwBlobs)), $pwMax, $seedIds, $links);
        $this->addAvatarMatches(array_values(array_filter(array_unique($avatars))), $avatarMax, $seedIds, $links);
        if ($showWeak) {
            $this->addSubnetMatches($rareSubs, $seedIds, $links);
        }

        // Enrich.
        $meta = $this->playerMeta(array_keys($links));
        $members = [];
        $threshold = now()->subDays($newDays);
        foreach ($links as $id => $l) {
            $m = $meta[$id] ?? null;
            if (!$m) continue;
            $created = $m->created ?? null;
            $members[] = [
                'player_id' => (int) $id,
                'username'  => $m->username,
                'created'   => $created,
                'last_login' => $m->last_login,
                'banned'    => (int) $m->active === self::BANNED,
                'is_new'    => $created !== null && $created >= $threshold,
                'link'      => $l['type'],
                'why'       => array_values(array_unique($l['why'])),
                'ips'       => count($ips[$id] ?? []) ?: $this->countIps($id),
            ];
        }

        // Sort: new first, then by creation date descending.
        usort($members, fn ($a, $b) => [$b['is_new'], $b['created'] ?? ''] <=> [$a['is_new'], $a['created'] ?? '']);

        return [
            'members' => $members,
            'missing' => $missing,
            'params'  => compact('newDays', 'ipMax', 'subnetMax', 'avatarMax', 'pwMax', 'showWeak'),
        ];
    }

    private function addExactIpMatches(array $ips, array $seedIds, array &$links): void
    {
        if (!$ips) return;
        foreach (array_chunk($ips, 300) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), 'INET6_ATON(?)'));
            foreach (DB::select(
                "SELECT DISTINCT player_id, INET6_NTOA(ip) AS ip FROM server_session
                  WHERE ip IN ($in) AND player_id IS NOT NULL", $chunk
            ) as $r) {
                $this->mark($links, (int) $r->player_id, 'strong', 'IP ' . $r->ip);
            }
            $likePh = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::select(
                "SELECT player_id, last_ip FROM player WHERE last_ip IN ($likePh)", $chunk
            ) as $r) {
                $this->mark($links, (int) $r->player_id, 'strong', 'IP ' . $r->last_ip);
            }
        }
    }

    private function addPasswordMatches(array $blobsHex, int $pwMax, array $seedIds, array &$links): void
    {
        $blobsHex = array_values(array_filter($blobsHex));
        if (!$blobsHex) return;
        $in = implode(',', array_fill(0, count($blobsHex), 'UNHEX(?)'));
        // Rare blobs only: a weak common password (same blob for many accounts)
        // is coincidence, not identity.
        foreach (DB::select(
            "SELECT HEX(password) AS h, COUNT(*) c FROM player WHERE password IN ($in) GROUP BY password", $blobsHex
        ) as $g) {
            if ((int) $g->c > $pwMax) continue;
            foreach (DB::select("SELECT player_id FROM player WHERE password = UNHEX(?)", [$g->h]) as $r) {
                $this->mark($links, (int) $r->player_id, 'strong', 'password');
            }
        }
    }

    private function addAvatarMatches(array $hashes, int $avatarMax, array $seedIds, array &$links): void
    {
        $hashes = array_values(array_filter($hashes));
        if (!$hashes) return;
        $ph = implode(',', array_fill(0, count($hashes), '?'));
        // Rare avatars only (otherwise a default image links half the lobby).
        foreach (DB::select(
            "SELECT avatar_hash, COUNT(*) c FROM player WHERE avatar_hash IN ($ph) GROUP BY avatar_hash", $hashes
        ) as $r) {
            if ((int) $r->c > $avatarMax) continue;
            foreach (DB::table('player')->where('avatar_hash', $r->avatar_hash)->pluck('player_id') as $pid) {
                $this->mark($links, (int) $pid, 'strong', 'avatar');
            }
        }
    }

    private function addSubnetMatches(array $subnets, array $seedIds, array &$links): void
    {
        foreach ($subnets as $s) {
            [$from, $to] = $this->subnetRange($s);
            if ($from === null) continue;
            foreach (DB::select(
                "SELECT DISTINCT player_id FROM server_session
                  WHERE ip BETWEEN INET6_ATON(?) AND INET6_ATON(?) AND LENGTH(ip)=4 AND player_id IS NOT NULL",
                [$from, $to]
            ) as $r) {
                $this->mark($links, (int) $r->player_id, 'weak', '/24 ' . $s);
            }
            foreach (DB::select(
                "SELECT player_id FROM player WHERE INET_ATON(last_ip) BETWEEN INET_ATON(?) AND INET_ATON(?)",
                [$from, $to]
            ) as $r) {
                $this->mark($links, (int) $r->player_id, 'weak', '/24 ' . $s);
            }
        }
    }

    private function mark(array &$links, int $id, string $type, string $why): void
    {
        if (!isset($links[$id])) {
            $links[$id] = ['type' => $type, 'why' => [$why]];
            return;
        }
        if ($links[$id]['type'] === 'seed') { $links[$id]['why'][] = $why; return; }
        // strong wins over weak
        if ($type === 'strong') $links[$id]['type'] = 'strong';
        $links[$id]['why'][] = $why;
    }

    private function ipSets(array $ids): array
    {
        $out = array_fill_keys($ids, []);
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        foreach (DB::select("SELECT player_id, last_ip FROM player WHERE player_id IN ($idPh) AND last_ip <> ''", $ids) as $r) {
            if ($this->usableIp($r->last_ip)) $out[(int) $r->player_id][] = $r->last_ip;
        }
        foreach (DB::select("SELECT DISTINCT player_id, INET6_NTOA(ip) AS ip FROM server_session WHERE player_id IN ($idPh) AND ip IS NOT NULL", $ids) as $r) {
            if ($r->ip !== null && $this->usableIp($r->ip)) $out[(int) $r->player_id][] = $r->ip;
        }
        foreach ($out as $k => $v) $out[$k] = array_values(array_unique($v));
        return $out;
    }

    private function ipAccountCounts(array $ips): array
    {
        if (!$ips) return [];
        $counts = [];
        foreach (array_chunk($ips, 300) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), 'INET6_ATON(?)'));
            $acc = [];
            foreach (DB::select("SELECT INET6_NTOA(ip) AS ip, player_id FROM server_session WHERE ip IN ($in) AND player_id IS NOT NULL", $chunk) as $r) {
                $acc[$r->ip][(int) $r->player_id] = true;
            }
            $likePh = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::select("SELECT last_ip AS ip, player_id FROM player WHERE last_ip IN ($likePh)", $chunk) as $r) {
                $acc[$r->ip][(int) $r->player_id] = true;
            }
            foreach ($chunk as $ip) $counts[$ip] = isset($acc[$ip]) ? count($acc[$ip]) : 0;
        }
        return $counts;
    }

    private function subnetAccountCounts(array $ips): array
    {
        $subs = [];
        foreach ($ips as $ip) { $s = $this->subnet($ip); if ($s !== null) $subs[$s] = true; }
        $subs = array_keys($subs);
        $out = [];
        foreach ($subs as $s) {
            [$from, $to] = $this->subnetRange($s);
            if ($from === null) { $out[$s] = PHP_INT_MAX; continue; }
            $acc = [];
            foreach (DB::select(
                "SELECT DISTINCT player_id FROM server_session WHERE ip BETWEEN INET6_ATON(?) AND INET6_ATON(?) AND LENGTH(ip)=4 AND player_id IS NOT NULL",
                [$from, $to]
            ) as $r) $acc[(int) $r->player_id] = true;
            foreach (DB::select(
                "SELECT player_id FROM player WHERE INET_ATON(last_ip) BETWEEN INET_ATON(?) AND INET_ATON(?)",
                [$from, $to]
            ) as $r) $acc[(int) $r->player_id] = true;
            $out[$s] = count($acc);
        }
        return $out;
    }

    private function passwordBlobs(array $ids): array
    {
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::select("SELECT player_id, HEX(password) AS h FROM player WHERE player_id IN ($idPh) AND password IS NOT NULL", $ids) as $r) {
            $out[(int) $r->player_id] = $r->h;
        }
        return $out;
    }

    private function avatars(array $ids): array
    {
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::select("SELECT player_id, avatar_hash FROM player WHERE player_id IN ($idPh)", $ids) as $r) {
            if ($r->avatar_hash) $out[(int) $r->player_id] = $r->avatar_hash;
        }
        return $out;
    }

    private function playerMeta(array $ids): array
    {
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::select("SELECT player_id, username, created, last_login, active FROM player WHERE player_id IN ($idPh)", $ids) as $r) {
            $out[(int) $r->player_id] = $r;
        }
        return $out;
    }

    private function countIps(int $id): int
    {
        return (int) DB::table('server_session')->where('player_id', $id)->whereNotNull('ip')->distinct()->count('ip');
    }

    /** @return int[] */
    private function resolveSeeds(array $seeds, &$missing): array
    {
        $missing = [];
        $ids = [];
        foreach ($seeds as $s) {
            $s = trim((string) $s);
            if ($s === '') continue;
            if (ctype_digit($s)) { $ids[(int) $s] = true; continue; }
            $id = DB::table('player')->where('username', $s)->value('player_id');
            if ($id !== null) { $ids[(int) $id] = true; continue; }
            // Self-deleted / renamed accounts (username is now deleted_<id>):
            // the original nick still lives in suspended_nicknames (live +
            // season snapshots) and in the sessions. player_id stays stable.
            $resolved = array_merge(
                $this->suspendedNickIds($s),
                DB::table('server_session')->where('nick', $s)->whereNotNull('player_id')->distinct()->pluck('player_id')->all()
            );
            $resolved = array_values(array_unique(array_map('intval', $resolved)));
            if ($resolved) {
                foreach ($resolved as $pid) $ids[$pid] = true;
            } else {
                $missing[] = $s;
            }
        }
        return array_keys($ids);
    }

    /**
     * player_ids for a freed nick across all suspended_nicknames tables
     * (pokerth_ranking live + pokerth_seasons snapshots).
     *
     * @return int[]
     */
    private function suspendedNickIds(string $nick): array
    {
        $tables = DB::select(
            "SELECT TABLE_SCHEMA AS s, TABLE_NAME AS t FROM information_schema.TABLES
              WHERE TABLE_NAME LIKE '%suspended_nicknames'
                AND TABLE_SCHEMA IN ('pokerth_ranking', 'pokerth_seasons')"
        );
        $ids = [];
        foreach ($tables as $tbl) {
            try {
                foreach (DB::table($tbl->s . '.' . $tbl->t)->where('nickname', $nick)
                    ->whereNotNull('player_id')->pluck('player_id') as $pid) {
                    $ids[(int) $pid] = true;
                }
            } catch (\Throwable $e) {
                // Skip a table without a player_id column etc.
            }
        }
        return array_keys($ids);
    }

    private function subnet(?string $ip): ?string
    {
        if ($ip === null || strpos($ip, '.') === false) return null; // IPv4 only
        return preg_replace('/\.\d+$/', '.0/24', $ip);
    }

    private function subnetRange(string $cidr): array
    {
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/24$#', $cidr, $m)) return [null, null];
        $base = ip2long($m[1]);
        if ($base === false) return [null, null];
        $base &= 0xFFFFFF00;
        return [long2ip($base), long2ip($base | 0xFF)];
    }

    private function usableIp(?string $ip): bool
    {
        if ($ip === null || $ip === '' || in_array($ip, self::EXCLUDE_IPS, true)) return false;
        return (bool) filter_var($ip, FILTER_VALIDATE_IP);
    }
}
