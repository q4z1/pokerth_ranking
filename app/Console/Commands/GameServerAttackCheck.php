<?php

namespace App\Console\Commands;

use App\Services\GameServerAttackDetector;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Prüft die Game-Server-Logs (server_log) und Sessions (server_session) auf
 * Angriffsmuster - die Regeln stehen in App\Services\GameServerAttackDetector.
 *
 * Neue Funde landen
 *  - in storage/app/gameserver_alerts.jsonl (Alerts-Seite + Badge unter "Logs"),
 *  - als Discord-Embed im selben Webhook wie attack:check,
 *  - als Mail an GAMESERVER_ALERT_MAIL (sp0ck).
 *
 * Läuft alle 5 min über ein gleitendes 30-min-Fenster. Damit derselbe Fund
 * nicht sechsmal hintereinander meldet, gilt je Regel+IP ein Cooldown von
 * 60 min; ein andauernder Angriff meldet sich also stündlich wieder.
 */
class GameServerAttackCheck extends Command
{
    protected $signature = 'gameserver:attack-check
                            {--dry-run : Only print findings - no alert file, Discord or mail}
                            {--window=30 : Window in minutes to look back}
                            {--replay= : Dry run over the last N days in consecutive windows (for tuning thresholds)}
                            {--test : Send a test alert via Discord and mail, nothing is stored}';

    protected $description = 'Scan game-server logs/sessions for attack patterns and alert (badge, Discord, mail)';

    public const ALERT_FILE = 'gameserver_alerts.jsonl';
    private const STATE_FILE = 'gameserver_attack_state.json';

    private const COOLDOWN_MIN = 60;
    private const HEARTBEAT_STALE_MIN = 10;

    /** Ab dieser Größe wird die Alert-Datei auf die letzten KEEP_ALERTS Zeilen gekürzt. */
    private const MAX_FILE_BYTES = 1_000_000;
    private const KEEP_ALERTS = 1000;

    private const ADMIN_URL = 'https://www.pokerth.net/pthranking/';

    public function handle(GameServerAttackDetector $detector)
    {
        if ($this->option('test')) {
            return $this->sendTest();
        }

        if ($days = (int) $this->option('replay')) {
            return $this->replay($detector, $days);
        }

        $window = max(5, (int) $this->option('window'));
        $to = Carbon::now(GameServerAttackDetector::SERVER_TZ);
        $from = $to->copy()->subMinutes($window);

        $findings = $detector->detect($from, $to);
        if ($hb = $detector->heartbeat(self::HEARTBEAT_STALE_MIN)) {
            $findings[] = $hb;
        }

        if ($this->option('dry-run')) {
            $this->printFindings($findings, $from, $to);
            return 0;
        }

        $state = $this->loadState();
        $now = time();
        $new = [];
        foreach ($findings as $f) {
            $key = $f['rule'] . '|' . ($f['ip'] ?? '*');
            if (($state['cooldown'][$key] ?? 0) > $now - self::COOLDOWN_MIN * 60) continue;
            $state['cooldown'][$key] = $now;
            $f['id'] = ++$state['last_id'];
            $f['ts'] = $to->format('Y-m-d H:i:s');
            $f['window_from'] = $from->format('Y-m-d H:i:s');
            $new[] = $f;
        }

        // Alte Cooldowns aufräumen, damit die State-Datei klein bleibt.
        $state['cooldown'] = array_filter($state['cooldown'], fn ($t) => $t > $now - 86400);
        $this->saveState($state);

        if (!$new) {
            $this->info('No new findings.');
            return 0;
        }

        foreach ($new as $f) {
            Storage::append(self::ALERT_FILE, json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $this->trimAlertFile();
        $this->printFindings($new, $from, $to);

        $this->notifyDiscord($new);
        $this->notifyMail($new);

        return 0;
    }

    private function replay(GameServerAttackDetector $detector, int $days): int
    {
        $window = max(5, (int) $this->option('window'));
        $end = Carbon::now(GameServerAttackDetector::SERVER_TZ);
        $t = $end->copy()->subDays($days);
        $hits = 0;
        while ($t->lt($end)) {
            $to = $t->copy()->addMinutes($window);
            $findings = $detector->detect($t, $to);
            if ($findings) {
                $hits += count($findings);
                $this->printFindings($findings, $t, $to);
            }
            $t = $to;
        }
        $this->info("Replay over $days days: $hits finding(s).");
        return 0;
    }

    private function printFindings(array $findings, Carbon $from, Carbon $to): void
    {
        $this->line(sprintf('<comment>%s – %s</comment> %d finding(s)', $from->format('Y-m-d H:i'), $to->format('H:i'), count($findings)));
        foreach ($findings as $f) {
            $this->line(sprintf('  [%s] %s – %s: %s', $f['severity'], $f['title'], $f['ip'] ?? 'all', $f['detail']));
        }
    }

    private function notifyDiscord(array $alerts): void
    {
        $url = env('DISCORD_ATTACK_WEBHOOK');
        if (!$url) return;

        $critical = collect($alerts)->contains('severity', 'critical');
        $lines = [];
        foreach (array_slice($alerts, 0, 10) as $a) {
            $icon = $a['severity'] === 'critical' ? '🔴' : '🟠';
            $lines[] = "$icon **{$a['title']}** – `" . ($a['ip'] ?? 'all') . "`\n" . $a['detail'];
        }
        if (count($alerts) > 10) $lines[] = '… and ' . (count($alerts) - 10) . ' more';
        $lines[] = "\n[Open Logs → Game-Server Alerts](" . self::ADMIN_URL . ')';

        $embed = [
            'title' => '🚨 Game-Server: ' . count($alerts) . ' suspicious finding(s)',
            'description' => mb_substr(implode("\n\n", $lines), 0, 4000),
            'color' => $critical ? 15158332 : 15105570,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        $this->postDiscord(['embeds' => [$embed]]);
    }

    private function postDiscord(array $payload): void
    {
        $curl = curl_init(env('DISCORD_ATTACK_WEBHOOK'));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($response === false || $code >= 300) {
            Log::warning('gameserver:attack-check Discord webhook failed', ['http' => $code, 'body' => $response]);
            $this->warn("Discord webhook failed (HTTP $code)");
        }
    }

    private function notifyMail(array $alerts): void
    {
        $to = env('GAMESERVER_ALERT_MAIL');
        if (!$to) return;

        $critical = collect($alerts)->contains('severity', 'critical');
        $subject = sprintf('[PokerTH] %sGame-Server: %d suspicious finding(s)', $critical ? 'CRITICAL – ' : '', count($alerts));

        $body = "The game-server attack check found the following (times = game-server clock, CET/CEST):\n\n";
        foreach ($alerts as $a) {
            $body .= sprintf("[%s] %s – %s\n  %s\n  window %s – %s\n", strtoupper($a['severity']), $a['title'],
                $a['ip'] ?? 'all IPs', $a['detail'], $a['window_from'], $a['ts']);
            foreach ($a['samples'] as $s) $body .= "    $s\n";
            $body .= "\n";
        }
        $body .= "Details: " . self::ADMIN_URL . " → Logs → Game-Server Alerts\n";
        if (collect($alerts)->contains(fn ($a) => in_array($a['ip'], GameServerAttackDetector::PROXY_IPS, true))) {
            $body .= "Note: 10.7.7.160 is the /pthlive webclient proxy - the real IPs are only in nginx pokerth_access.log.\n";
        }

        $this->sendMail($to, $subject, $body);
    }

    private function sendMail(string $to, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::error('gameserver:attack-check mail failed: ' . $e->getMessage());
            $this->warn('Mail failed: ' . $e->getMessage());
        }
    }

    private function sendTest(): int
    {
        $this->postDiscord(['embeds' => [[
            'title' => '🧪 Game-Server attack check: test alert',
            'description' => 'Test from `php artisan gameserver:attack-check --test` – no action needed.',
            'color' => 3447003,
        ]]]);
        if ($to = env('GAMESERVER_ALERT_MAIL')) {
            $this->sendMail($to, '[PokerTH] Game-Server attack check: test alert',
                "Test from `php artisan gameserver:attack-check --test` – no action needed.\n");
        } else {
            $this->warn('GAMESERVER_ALERT_MAIL is not set - no mail sent.');
        }
        $this->info('Test alert sent.');
        return 0;
    }

    private function loadState(): array
    {
        $raw = Storage::exists(self::STATE_FILE) ? json_decode(Storage::get(self::STATE_FILE), true) : null;
        $state = is_array($raw) ? $raw : [];
        $state['cooldown'] = $state['cooldown'] ?? [];
        // last_id aus der Alert-Datei rekonstruieren, falls die State-Datei fehlt.
        $state['last_id'] = $state['last_id'] ?? (int) (self::readAlerts(1)[0]['id'] ?? 0);
        return $state;
    }

    private function saveState(array $state): void
    {
        Storage::put(self::STATE_FILE, json_encode($state));
    }

    private function trimAlertFile(): void
    {
        $path = Storage::path(self::ALERT_FILE);
        if (@filesize($path) < self::MAX_FILE_BYTES) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        Storage::put(self::ALERT_FILE, implode("\n", array_slice($lines, -self::KEEP_ALERTS)) . "\n");
    }

    /**
     * Die neuesten `$limit` Alerts, neueste zuerst. Auch vom Controller benutzt.
     */
    public static function readAlerts(int $limit = 200): array
    {
        $path = Storage::path(self::ALERT_FILE);
        if (!is_file($path)) return [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $a = json_decode($line, true);
            if (is_array($a)) $out[] = $a;
            if (count($out) >= $limit) break;
        }
        return $out;
    }
}
