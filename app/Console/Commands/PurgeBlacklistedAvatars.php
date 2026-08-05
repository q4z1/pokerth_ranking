<?php

namespace App\Console\Commands;

use App\Models\AvatarBlacklist;
use App\Services\AvatarBlacklistService;
use Illuminate\Console\Command;

/**
 * Räumt Altlasten ab: bis die Webseite die Blacklist kannte, wurden gesperrte
 * Avatare nur vom Game-Server ignoriert – die Dateien lagen weiterhin öffentlich
 * unter /images/avatars/game/ und waren über ihre direkte URL abrufbar.
 */
class PurgeBlacklistedAvatars extends Command
{
    protected $signature = 'avatars:purge-blacklisted {--dry-run : Nur anzeigen, nichts verschieben}';

    protected $description = 'Move files of already blacklisted avatars out of the public avatar directory';

    public function handle(AvatarBlacklistService $service)
    {
        $dryRun = (bool) $this->option('dry-run');
        $hashes = AvatarBlacklist::pluck('avatar_hash')
            ->filter()->map(fn($h) => strtolower(trim($h)))->unique()->values();

        $this->info($hashes->count() . ' hash(es) on the blacklist.');
        $this->line('Public directory:     ' . $service->gameDir());
        $this->line('Quarantine directory: ' . $service->quarantineDir());
        if ($dryRun) $this->warn('Dry run – no files will be moved.');
        $this->newLine();

        $found = 0;
        $moved = 0;
        $failed = 0;

        foreach ($hashes as $hash) {
            $files = $service->publicFiles($hash);
            if (!count($files)) continue;

            $found += count($files);
            foreach ($files as $file) {
                $this->line('  ' . basename($file) . ' (' . number_format(filesize($file)) . ' bytes)');
            }

            if ($dryRun) continue;

            try {
                $moved += count($service->quarantine($hash));
            } catch (\Exception $e) {
                $failed++;
                $this->error('  failed: ' . $hash . ' – ' . $e->getMessage());
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->info($found . ' file(s) would be moved to quarantine.');
        } else {
            $this->info($moved . ' of ' . $found . ' file(s) moved to quarantine.');
            if ($failed) $this->error($failed . ' hash(es) failed.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
