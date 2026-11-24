<?php

namespace App\Console\Commands;

use App\Services\RingTracker;
use Illuminate\Console\Command;

/**
 * Expands the ring of a ban evader from known seed accounts (config/rings.php,
 * real names in the gitignored config/rings.local.php) and highlights NEW
 * throwaway accounts in colour. Logic: App\Services\RingTracker.
 *
 * Examples:
 *   php artisan ranking:track-ring                      # preset 'default'
 *   php artisan ranking:track-ring default --show-weak  # incl. VPN /24 neighbours
 *   php artisan ranking:track-ring --seed=SomeNick --seed=12345
 *   php artisan ranking:track-ring default --new-days=14 --json
 */
class TrackRing extends Command
{
    protected $signature = 'ranking:track-ring
                            {ring=default : Name of a preset from config/rings.php}
                            {--seed=* : Seed accounts (nick or id); overrides the preset}
                            {--new-days=7 : Accounts younger than N days count as NEW}
                            {--ip-max=4 : An exact IP links only when at most this many accounts use it}
                            {--subnet-max=6 : A /24 links (weak) only when at most this many accounts use it}
                            {--avatar-max=5 : An avatar links only when at most this many accounts carry it}
                            {--show-weak : Also include weak /24 (VPN) neighbours}
                            {--json : Output as JSON}';

    protected $description = 'Shows the account ring of a ban evader and highlights new accounts';

    public function handle(RingTracker $tracker): int
    {
        $seeds = $this->option('seed');
        if (!$seeds) {
            $ring  = (string) $this->argument('ring');
            $seeds = config("rings.$ring");
            if (!$seeds) {
                $this->error("No preset '$ring' in config/rings.php and no --seed given.");
                return self::FAILURE;
            }
        }

        $result = $tracker->track($seeds, [
            'newDays'   => (int) $this->option('new-days'),
            'ipMax'     => (int) $this->option('ip-max'),
            'subnetMax' => (int) $this->option('subnet-max'),
            'avatarMax' => (int) $this->option('avatar-max'),
            'showWeak'  => (bool) $this->option('show-weak'),
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($result['missing']) {
            $this->warn('Seeds not found: ' . implode(', ', $result['missing']));
        }
        if (!$result['members']) {
            $this->line('No ring expanded.');
            return self::SUCCESS;
        }

        $new = array_filter($result['members'], fn ($m) => $m['is_new']);
        $this->info(sprintf(
            '%d account(s) in the ring, %d NEW (<= %d days). %s',
            count($result['members']), count($new), $result['params']['newDays'],
            $result['params']['showWeak'] ? 'incl. weak /24 neighbours.' : '(--show-weak for VPN /24 neighbours)'
        ));
        $this->newLine();

        foreach ($result['members'] as $m) {
            $tag = match ($m['link']) {
                'seed'   => '<fg=cyan>seed</>',
                'strong' => '<fg=magenta>strong</>',
                default  => '<fg=gray>weak</>',
            };
            $created = $m['created'] ? substr($m['created'], 0, 10) : '?';
            $nameCell = $m['username'];
            if ($m['is_new']) {
                // NEW: black on yellow, hard to miss.
                $nameCell = "<fg=black;bg=yellow;options=bold> NEW </> <fg=yellow;options=bold>{$m['username']}</>";
            } elseif ($m['banned']) {
                $nameCell = "<fg=red>{$m['username']}</>";
            }
            $flags = [];
            if ($m['banned']) $flags[] = '<fg=red>BANNED</>';
            $this->line(sprintf(
                '  %s%s  id=%d  created=%s  last=%s  [%s]  %s',
                $nameCell,
                $flags ? ' ' . implode(' ', $flags) : '',
                $m['player_id'], $created,
                $m['last_login'] ? substr($m['last_login'], 0, 10) : 'never',
                $tag,
                $m['why'] ? '<fg=gray>' . implode(', ', array_slice($m['why'], 0, 4)) . '</>' : ''
            ));
        }

        $this->newLine();
        $this->comment('Yellow = new account (<= ' . $result['params']['newDays'] . ' days). '
            . 'strong = exact IP/password/avatar; weak = VPN /24. Indication, not proof.');

        return self::SUCCESS;
    }
}
