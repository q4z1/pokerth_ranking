<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Rotiert die nginx-Logs, weil es dafuer sonst nichts gibt: in
 * /etc/logrotate.d/ liegt kein nginx-Eintrag, und im Container ist logrotate
 * weder installiert noch laeuft dort ein cron-Daemon. Gemessen am 23.08.2026
 * standen / und /var/www bei 95% (4,3 GB frei), waehrend allein
 * /var/log/nginx rund 2,5 GB belegte (pokerth_access.log 976 MB).
 *
 * Rotiert wird nach dem copytruncate-Prinzip: nginx laeuft in einem anderen
 * Container, seine PID ist von hier aus nicht erreichbar, ein USR1 zum
 * Neuoeffnen der Dateien also nicht moeglich. Truncate wirkt dagegen auf die
 * Inode, die beide Container sehen, und nginx schreibt mit O_APPEND weiter.
 *
 * Anders als logrotate wird nicht erst kopiert und dann gepackt, sondern
 * direkt gzip-gestreamt. Das spart den Moment, in dem eine unkomprimierte
 * Vollkopie neben dem Original liegt – bei diesem Fuellstand der eigentliche
 * Knackpunkt.
 */
class RotateNginxLogs extends Command
{
    protected $signature = 'logs:rotate-nginx
                            {--dir=/var/log/nginx : Directory holding the log files}
                            {--max-size=100 : Rotate files larger than this (MB)}
                            {--keep=7 : Number of compressed generations to keep per log}
                            {--dry-run : Only report what would happen}';

    protected $description = 'Compress and truncate oversized nginx log files (copytruncate style)';

    public function handle()
    {
        $dir     = rtrim((string) $this->option('dir'), '/');
        $maxSize = (int) $this->option('max-size') * 1024 * 1024;
        $keep    = max(0, (int) $this->option('keep'));
        $dryRun  = (bool) $this->option('dry-run');

        if (!is_dir($dir)) {
            $this->error('Directory not found: ' . $dir);
            return self::FAILURE;
        }

        // Der Mount kann read-only sein: im Dev-Container liegt
        // /var/log/nginx als ro,relatime, die 777-Rechte des Verzeichnisses
        // taeuschen darueber hinweg. Ohne diese Pruefung scheitert erst
        // jede einzelne Datei am gzopen, mit wenig aussagekraeftiger Meldung.
        if (!is_writable($dir)) {
            $this->error($dir . ' is not writable - read-only mount?');
            $this->line('Rotation has to run where the directory is mounted rw');
            $this->line('(nginx container or host), not from this one.');
            return self::FAILURE;
        }

        $logs = glob($dir . '/*.log') ?: [];
        if (!count($logs)) {
            $this->info('No log files in ' . $dir . '.');
            return self::SUCCESS;
        }

        $this->line('Directory: ' . $dir);
        $this->line('Threshold: ' . $this->human($maxSize) . ', keeping ' . $keep . ' generation(s)');
        $this->line('Free disk: ' . $this->human((int) disk_free_space($dir)));
        if ($dryRun) $this->warn('Dry run - nothing is written.');
        $this->newLine();

        $rotated = 0;
        $freed   = 0;
        $failed  = 0;

        foreach ($logs as $log) {
            clearstatcache(true, $log);
            $size = (int) @filesize($log);
            if ($size < $maxSize) continue;

            $this->line('  ' . basename($log) . ' (' . $this->human($size) . ')');

            // Der gzip-Strom braucht Platz. Zehnfache Kompression ist bei
            // Logzeilen ueblich; 30% als Reserve verhindert, dass ausgerechnet
            // die Rotation die Platte volllaufen laesst.
            $free = (int) disk_free_space($dir);
            if ($free < $size * 0.3) {
                $this->error('    skipped - only ' . $this->human($free) . ' free');
                $failed++;
                continue;
            }

            if ($dryRun) {
                $rotated++;
                $freed += $size;
                continue;
            }

            try {
                $written = $this->compress($log, $size);
                $freed  += $size;
                $rotated++;
                $this->info('    -> ' . basename($written) . ' (' . $this->human((int) @filesize($written)) . ')');
            } catch (\Exception $e) {
                $this->error('    failed: ' . $e->getMessage());
                $failed++;
                continue;
            }

            foreach ($this->expired($log, $keep) as $old) {
                @unlink($old);
                $this->line('    removed ' . basename($old));
            }
        }

        $this->newLine();
        if (!$rotated && !$failed) {
            $this->info('Nothing to rotate - all files below ' . $this->human($maxSize) . '.');
        } elseif ($dryRun) {
            $this->info($rotated . ' file(s) would be rotated, freeing about ' . $this->human($freed) . '.');
        } else {
            $this->info($rotated . ' file(s) rotated, about ' . $this->human($freed) . ' reclaimed.');
            $this->line('Free disk now: ' . $this->human((int) disk_free_space($dir)));
        }
        if ($failed) $this->error($failed . ' file(s) failed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Liest die Datei gzip-komprimiert weg und schneidet sie danach auf 0.
     * Gelesen wird nur bis zur Groesse, die beim Start galt - was nginx
     * waehrenddessen anhaengt, faellt beim Truncate weg. Dieses Fenster hat
     * copytruncate prinzipbedingt, es ist hier nur so kurz wie moeglich.
     */
    private function compress(string $log, int $size): string
    {
        $target = $log . '-' . date('Ymd-His') . '.gz';

        $in = @fopen($log, 'rb');
        if (!$in) throw new \RuntimeException('cannot read ' . basename($log));

        $out = @gzopen($target, 'wb6');
        if (!$out) {
            fclose($in);
            throw new \RuntimeException('cannot write ' . basename($target));
        }

        $read = 0;
        while ($read < $size && !feof($in)) {
            $chunk = fread($in, min(1048576, $size - $read));
            if ($chunk === false || $chunk === '') break;
            if (gzwrite($out, $chunk) === false) {
                gzclose($out);
                fclose($in);
                @unlink($target);
                throw new \RuntimeException('write error on ' . basename($target));
            }
            $read += strlen($chunk);
        }

        gzclose($out);
        fclose($in);

        // Erst jetzt kuerzen - schlaegt das Komprimieren fehl, bleibt das
        // Original unangetastet.
        $fh = @fopen($log, 'r+');
        if (!$fh) throw new \RuntimeException('cannot truncate ' . basename($log));
        ftruncate($fh, 0);
        fclose($fh);
        clearstatcache(true, $log);

        return $target;
    }

    /**
     * @return string[] Generationen, die ueber --keep hinausgehen (aelteste zuerst).
     */
    private function expired(string $log, int $keep): array
    {
        $old = glob($log . '-*.gz') ?: [];
        if (count($old) <= $keep) return [];
        sort($old); // Zeitstempel im Namen sortiert chronologisch
        return array_slice($old, 0, count($old) - $keep);
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
        return round($n, $n < 10 && $i > 0 ? 1 : 0) . ' ' . $units[$i];
    }
}
