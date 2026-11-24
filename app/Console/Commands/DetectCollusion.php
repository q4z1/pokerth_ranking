<?php

namespace App\Console\Commands;

use App\Services\CollusionDetector;
use Illuminate\Console\Command;

/**
 * Lists "black sheep": account rings controlled by one person that sit together
 * at ranked tables (collusion / multi-seat).
 *
 * Detection, see App\Services\CollusionDetector - calibrated on a real
 * multi-seat case (Nov 2026): IP-pool linkage across ALL sessions (not "same
 * IP in the game") combined with repeated shared ranked tables.
 *
 * Examples:
 *   php artisan ranking:detect-collusion                 # all time, >=2 tables
 *   php artisan ranking:detect-collusion --since=week    # incidents in the last week
 *   php artisan ranking:detect-collusion --confidence=high
 *   php artisan ranking:detect-collusion --player="SomeNick"
 *   php artisan ranking:detect-collusion --min-tables=3 --json
 */
class DetectCollusion extends Command
{
    protected $signature = 'ranking:detect-collusion
                            {--days= : Only consider tables from the last N days (default: whole history)}
                            {--since= : Shorthand time window: day|week|month|year (overridden by --days)}
                            {--confidence= : Only rings at or above this confidence: review|medium|high}
                            {--min-tables=2 : Minimum shared ranked tables per pair}
                            {--ip-max-accounts=12 : A shared IP only links when at most this many accounts use it (keeps public VPN/CGNAT IPs out)}
                            {--player= : Only the ring containing this account (nick or id)}
                            {--limit=100 : Maximum number of rings to print}
                            {--json : Output as JSON instead of a table}';

    protected $description = 'Finds account rings of one person that sit together at ranked tables (collusion/multi-seat)';

    private const SINCE_DAYS = ['day' => 1, 'week' => 7, 'month' => 30, 'year' => 365];

    public function handle(CollusionDetector $detector): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        if ($days === null && $this->option('since') !== null) {
            $since = strtolower((string) $this->option('since'));
            if (!isset(self::SINCE_DAYS[$since])) {
                $this->error('Invalid --since; use one of: ' . implode(', ', array_keys(self::SINCE_DAYS)) . '.');
                return self::FAILURE;
            }
            $days = self::SINCE_DAYS[$since];
        }

        $confidence = $this->option('confidence');
        if ($confidence !== null) {
            $confidence = strtolower((string) $confidence);
            if (!in_array($confidence, ['review', 'medium', 'high'], true)) {
                $this->error('Invalid --confidence; use one of: review, medium, high.');
                return self::FAILURE;
            }
        }

        $result = $detector->detect([
            'days'          => $days,
            'minTables'     => (int) $this->option('min-tables'),
            'ipMaxAccounts' => (int) $this->option('ip-max-accounts'),
            'player'        => $this->option('player'),
            'limit'         => (int) $this->option('limit'),
            'minConfidence' => $confidence,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($result['rings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $p = $result['params'];
        $this->info(sprintf(
            'Collusion scan: %s, min %d shared tables, IP counts as private up to %d accounts%s%s',
            $p['days'] !== null ? 'last ' . $p['days'] . ' days' : 'whole history',
            $p['minTables'], $p['ipMaxAccounts'],
            $confidence !== null ? ', confidence >= ' . $confidence : '',
            $p['focusId'] !== null ? ', focused on id=' . $p['focusId'] : ''
        ));

        if (!$result['rings']) {
            $this->line('No suspicious rings found.');
            return self::SUCCESS;
        }

        $shown = count($result['rings']);
        $this->line($shown < $result['total']
            ? "Showing top $shown of {$result['total']} suspicious rings (raise --limit for more), sorted by best rank:"
            : "$shown suspicious ring(s), sorted by best rank:");
        $this->newLine();

        foreach ($result['rings'] as $i => $ring) {
            $this->line(sprintf(
                '<fg=yellow>#%d</>  best rank: %s | %d accounts | %d shared ranked tables | confidence: %s | score %d',
                $i + 1,
                $ring['top_rank'] !== null ? '#' . $ring['top_rank'] : '-',
                $ring['size'], $ring['shared_tables'], strtoupper($ring['confidence']), $ring['score']
            ));
            $signals = [];
            if ($ring['pw_match'])     $signals[] = 'same password';
            if ($ring['name_similar']) $signals[] = 'similar nicks';
            if ($ring['min_ip_accounts'] !== null) $signals[] = 'private IP (min ' . $ring['min_ip_accounts'] . ' accounts/IP)';
            $this->line('     signals: ' . ($signals ? implode(', ', $signals) : '-')
                . ' | last seen together: ' . ($ring['last_seen'] ?? '?'));

            $rows = [];
            foreach ($ring['members'] as $m) {
                $rows[] = [
                    $m['player_id'],
                    $m['username'] . ($m['banned'] ? ' [BANNED]' : ''),
                    $m['pw_group'] !== null ? 'pw ' . $m['pw_group'] : '-',
                    $m['rank'] !== null ? '#' . $m['rank'] : '-',
                    $m['score'] !== null ? $m['score'] : '-',
                    $m['games'] !== null ? $m['games'] : '-',
                    $m['last_login'] ? substr($m['last_login'], 0, 10) : 'never',
                    $m['ips'],
                ];
            }
            $this->table(['id', 'nick', 'pw', 'rank', 'score', 'games', 'last_login', '#IPs'], $rows);

            if ($ring['shared_ips']) {
                $this->line('     shared IPs: ' . implode(', ', $ring['shared_ips']));
            }
            $this->newLine();
        }

        $this->comment('Note: a shared IP is an indication, not proof (household/CGNAT possible). '
            . 'Confidence HIGH = same password, similar nick or private IP. '
            . 'pw A/B marks members that share the same password (same letter = same password); '
            . 'rank/score/games are the current season standing (for prioritisation).');

        return self::SUCCESS;
    }
}
