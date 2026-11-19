<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sucht in server_log (Kopie von server_messages.log) und server_session nach
 * Angriffsmustern, wie sie beim WS-Angriff in der Nacht 17./18.11.2026
 * aufgetreten sind: Passwort-Raten, Paket-Fuzzing auf dem nativen Port,
 * Test-/XSS-Gastnamen über /pthlive, Gast-Fluten über den Webclient-Proxy,
 * hängender Game-Server.
 *
 * Alle Zeitstempel beider Tabellen sind die Wanduhr des Game-Servers
 * (Europe/Berlin), nicht UTC wie die DB-Uhr - daher wird das Fenster hier in
 * SERVER_TZ gerechnet.
 *
 * Schwellen sind an den Daten bis 19.11.2026 kalibriert (Normalbetrieb):
 * - Authentication failed: max. 2 je IP/Tag
 * - Password-Block / Login-Rate-Limit: einzelne IPs, max. 6 Rate-Limits in 3 min
 * - Invalid packet: max. 4 je IP, immer dieselbe Variante (Scanner, "GET ")
 * - Sessions gesamt: Spitze 95 / 30 min
 * - Gast-Sessions über den /pthlive-Proxy: Spitze 16 / 30 min (Angriff: 31)
 * - Registrierte Nicks je IP: max. 3 / Stunde
 * Ändern sich die Spitzen, mit `gameserver:attack-check --replay=14` gegenprüfen.
 */
class GameServerAttackDetector
{
    public const SERVER_TZ = 'Europe/Berlin';

    /**
     * Der Webclient-Proxy (/pthlive -> ws-backend). Alle Browser-Clients
     * erscheinen am Game-Server mit dieser IP, die echte steht nur im
     * nginx pokerth_access.log (Feld 1). admin-bot kommt ebenfalls von hier.
     */
    public const PROXY_IPS = ['10.7.7.160', '::ffff:10.7.7.160'];

    private const LOOPBACK_IPS = ['127.0.0.1', '::ffff:127.0.0.1', '::1'];

    /** Zeichen, die in einem Nick nur als Injection-/XSS-Probe Sinn ergeben. */
    private const INJECTION_CHARS = '/[<>"\'`;={}\\\\]|\(\)/u';

    private const SAMPLE_LINES = 5;

    /**
     * IP als Text, IPv4-mapped (::ffff:a.b.c.d) auf reines IPv4 gekürzt -
     * der Server loggt dieselbe Adresse je nach Socket in beiden Formen.
     */
    private const NIP = "REGEXP_REPLACE(INET6_NTOA(ip), '^::ffff:', '')";

    /**
     * @return array<int, array{rule:string,severity:string,ip:?string,title:string,detail:string,count:int,samples:array}>
     */
    public function detect(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->setTimezone(self::SERVER_TZ)->format('Y-m-d H:i:s');
        $to = $to->copy()->setTimezone(self::SERVER_TZ)->format('Y-m-d H:i:s');
        $minutes = (int) round((strtotime($to) - strtotime($from)) / 60);

        $findings = [];

        // --- Passwort-Raten -------------------------------------------------
        $this->perIpLogRule($findings, $from, $to, 'auth_failed', 'Authentication failed%',
            perIp: 6, distinctIps: 8, distinctTotal: 20, severity: 'critical',
            title: 'Password guessing',
            detailIp: fn ($c) => "$c failed logins within $minutes min",
            detailDist: fn ($c, $ips) => "$c failed logins from $ips IPs within $minutes min (distributed)");

        $this->perIpLogRule($findings, $from, $to, 'password_block', 'Password logins from % blocked%',
            perIp: 2, distinctIps: 4, distinctTotal: 4, severity: 'critical',
            title: 'Password-login lockouts',
            detailIp: fn ($c) => "locked out $c times within $minutes min",
            detailDist: fn ($c, $ips) => "$ips IPs locked out within $minutes min");

        $this->perIpLogRule($findings, $from, $to, 'login_rate_limit', 'Login rate limit reached by%',
            perIp: 10, distinctIps: 6, distinctTotal: 6, severity: 'warning',
            title: 'Login rate limit',
            detailIp: fn ($c) => "hit the login rate limit $c times within $minutes min",
            detailDist: fn ($c, $ips) => "$ips IPs hit the login rate limit within $minutes min");

        $this->perIpLogRule($findings, $from, $to, 'tls_failures', 'TLS handshake from % failed%',
            perIp: 20, distinctIps: 30, distinctTotal: 60, severity: 'warning',
            title: 'TLS handshake failures',
            detailIp: fn ($c) => "$c failed TLS handshakes within $minutes min",
            detailDist: fn ($c, $ips) => "$c failed TLS handshakes from $ips IPs within $minutes min");

        // --- Paket-Fuzzing auf dem nativen Port --------------------------------
        // Ein HTTP-Scanner ("GET " = Größe 1195725856) liefert immer dieselbe
        // Variante; Fuzzing erkennt man an wechselnden Größen/Codes.
        $rows = DB::select(
            "SELECT " . self::NIP . " i, COUNT(*) c,
                    COUNT(DISTINCT REGEXP_REPLACE(message, '^.*Session [0-9]+ - ', '')) v
               FROM server_log
              WHERE logged_at BETWEEN ? AND ? AND message LIKE '%Invalid packet%'
              GROUP BY i HAVING c >= 15 OR v >= 3",
            [$from, $to]
        );
        foreach ($rows as $r) {
            $findings[] = $this->finding('packet_fuzzing', 'critical', $r->i, 'Packet fuzzing',
                "{$r->c} invalid packets ({$r->v} different kinds) within $minutes min",
                (int) $r->c, $this->logSamples($from, $to, '%Invalid packet%', $r->i));
        }

        // --- Gastnamen ---------------------------------------------------------
        // Seit Run #23 (18.11.2026) lehnt der Server Gastnamen außer GuestNNNN
        // ab. Eine Gast-Session mit anderem Namen heißt also: Prüfung umgangen.
        $rows = DB::select(
            "SELECT " . self::NIP . " i, COUNT(*) c, GROUP_CONCAT(DISTINCT nick ORDER BY connected_at SEPARATOR '\n') nicks
               FROM server_session
              WHERE connected_at BETWEEN ? AND ? AND is_guest = 1 AND nick NOT REGEXP '^Guest[0-9]+$'
              GROUP BY i",
            [$from, $to]
        );
        foreach ($rows as $r) {
            $nicks = array_filter(explode("\n", (string) $r->nicks));
            $probe = $this->hasInjection($nicks);
            $findings[] = $this->finding('odd_guest_name', $probe ? 'critical' : 'warning', $r->i,
                $probe ? 'Injection probe in guest name' : 'Non-standard guest name accepted',
                "{$r->c} guest session(s) with custom names: " . $this->list($nicks),
                (int) $r->c, []);
        }

        $rows = DB::select(
            "SELECT " . self::NIP . " i, COUNT(*) c, GROUP_CONCAT(message ORDER BY log_id SEPARATOR '\n') msgs
               FROM server_log
              WHERE logged_at BETWEEN ? AND ? AND message LIKE 'Rejected guest login from % invalid guest name%'
              GROUP BY i",
            [$from, $to]
        );
        foreach ($rows as $r) {
            preg_match_all('/invalid guest name "(.*)"\.?$/m', (string) $r->msgs, $m);
            $names = array_values(array_unique($m[1]));
            $probe = $this->hasInjection($names);
            if (!$probe && $r->c < 3) continue; // einzelne Tippfehler/Altclients
            $findings[] = $this->finding('guest_name_rejected', $probe ? 'critical' : 'warning', $r->i,
                $probe ? 'Injection probe in guest name' : 'Rejected guest names',
                "{$r->c} rejected guest logins: " . $this->list($names),
                (int) $r->c, []);
        }

        // --- Session-Muster ----------------------------------------------------
        $exclude = array_merge(self::PROXY_IPS, self::LOOPBACK_IPS);
        $marks = implode(',', array_fill(0, count($exclude), 'INET6_ATON(?)'));
        $rows = DB::select(
            "SELECT " . self::NIP . " i, COUNT(DISTINCT nick) n, GROUP_CONCAT(DISTINCT nick SEPARATOR '\n') nicks
               FROM server_session
              WHERE connected_at BETWEEN ? AND ? AND is_guest = 0 AND ip NOT IN ($marks)
              GROUP BY i HAVING n >= 4",
            array_merge([$from, $to], $exclude)
        );
        foreach ($rows as $r) {
            $findings[] = $this->finding('account_hopping', 'critical', $r->i, 'One IP, many accounts',
                "logged into {$r->n} different accounts within $minutes min: " . $this->list(explode("\n", (string) $r->nicks)),
                (int) $r->n, []);
        }

        $proxyMarks = implode(',', array_fill(0, count(self::PROXY_IPS), 'INET6_ATON(?)'));
        $r = DB::selectOne(
            "SELECT COUNT(*) c, COUNT(DISTINCT nick) n FROM server_session
              WHERE connected_at BETWEEN ? AND ? AND is_guest = 1 AND ip IN ($proxyMarks) AND nick <> 'admin-bot'",
            array_merge([$from, $to], self::PROXY_IPS)
        );
        if ($r && $r->c >= 20) {
            $findings[] = $this->finding('webclient_guest_flood', 'warning', self::PROXY_IPS[0],
                'Guest flood via webclient (/pthlive)',
                "{$r->c} guest sessions ({$r->n} nicks) through the /pthlive proxy within $minutes min - real IPs only in nginx pokerth_access.log",
                (int) $r->c, []);
        }

        $c = DB::table('server_session')->whereBetween('connected_at', [$from, $to])->count();
        if ($c >= 180) {
            $findings[] = $this->finding('session_flood', 'warning', null, 'Connection flood',
                "$c sessions within $minutes min (normal peak: <100 / 30 min)", $c, []);
        }

        return $findings;
    }

    /**
     * Der Game-Server schreibt server_live_stats als Heartbeat. Steht der
     * Eintrag, hängt oder fehlt der Server (so endete die Angriffsnacht).
     */
    public function heartbeat(int $staleMinutes): ?array
    {
        $row = DB::table('server_live_stats')->orderByDesc('updated_at')->first();
        if (!$row) return null;
        $last = Carbon::parse($row->updated_at, self::SERVER_TZ);
        $age = (int) $last->diffInMinutes(Carbon::now(self::SERVER_TZ), true);
        if ($age < $staleMinutes) return null;

        return $this->finding('heartbeat_stale', 'critical', null, 'Game server not responding',
            "no heartbeat for $age min (last: {$row->updated_at}, run #{$row->run_id})", $age, []);
    }

    /** Pro-IP-Schwelle plus "verteilt über viele IPs"-Schwelle für ein Log-Muster. */
    private function perIpLogRule(array &$findings, string $from, string $to, string $rule, string $like,
        int $perIp, int $distinctIps, int $distinctTotal, string $severity, string $title,
        \Closure $detailIp, \Closure $detailDist): void
    {
        $rows = DB::select(
            "SELECT " . self::NIP . " i, COUNT(*) c FROM server_log
              WHERE logged_at BETWEEN ? AND ? AND message LIKE ? GROUP BY i",
            [$from, $to, $like]
        );

        $total = 0;
        foreach ($rows as $r) {
            $total += $r->c;
            if ($r->c >= $perIp) {
                $findings[] = $this->finding($rule, $severity, $r->i, $title, $detailIp((int) $r->c),
                    (int) $r->c, $this->logSamples($from, $to, $like, $r->i));
            }
        }

        if (count($rows) >= $distinctIps && $total >= $distinctTotal) {
            $findings[] = $this->finding($rule . '_distributed', $severity, null, $title . ' (distributed)',
                $detailDist($total, count($rows)), $total, $this->logSamples($from, $to, $like, null));
        }
    }

    private function logSamples(string $from, string $to, string $like, ?string $ip): array
    {
        $q = DB::table('server_log')
            ->whereBetween('logged_at', [$from, $to])
            ->where('message', 'like', $like)
            ->orderByDesc('log_id')
            ->limit(self::SAMPLE_LINES)
            ->select('logged_at', 'level', 'message');
        if ($ip !== null) $q->whereRaw("ip IN (INET6_ATON(?), INET6_ATON(CONCAT('::ffff:', ?)))", [$ip, $ip]);

        return $q->get()->reverse()->map(fn ($r) => "{$r->logged_at} {$r->level}: {$r->message}")->values()->all();
    }

    private function hasInjection(array $names): bool
    {
        foreach ($names as $n) {
            if (preg_match(self::INJECTION_CHARS, $n)) return true;
        }
        return false;
    }

    private function list(array $items, int $max = 12): string
    {
        $items = array_values(array_unique($items));
        $more = count($items) - $max;
        $out = implode(', ', array_map(fn ($s) => '"' . mb_substr($s, 0, 40) . '"', array_slice($items, 0, $max)));
        return $more > 0 ? "$out (+$more more)" : $out;
    }

    private function finding(string $rule, string $severity, ?string $ip, string $title, string $detail, int $count, array $samples): array
    {
        if ($ip !== null && str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);
        if ($ip === '') $ip = null;

        return compact('rule', 'severity', 'ip', 'title', 'detail', 'count', 'samples');
    }
}
