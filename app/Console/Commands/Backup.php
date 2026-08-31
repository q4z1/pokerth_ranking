<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Loest die frueheren ~/.local/bin/backup.sh + ~/.local/bin/automysqlbackup ab,
 * die bis dahin von ausserhalb des Containers alle 4 h angestossen wurden und
 * bei jedem Lauf XHR-Timeouts im Forum verursacht haben. Drei Ursachen, hier
 * alle adressiert:
 *
 *   1. Table-Locks. automysqlbackup dumpte ohne --single-transaction, also mit
 *      --lock-tables aus --opt: jede DB war fuer die gesamte Dump-Dauer
 *      schreibgesperrt (ph ~30 s). Hier laeuft mysqldump mit
 *      --single-transaction --skip-lock-tables, InnoDB bekommt einen
 *      konsistenten Snapshot ohne Lock. Die MyISAM-Tabellen in ph/monthlycup
 *      (ryae_*, player_betaNstats*, award20xx, player20xx) sind kalte Altdaten
 *      und damit nicht mehr snapshot-konsistent - bewusst in Kauf genommen.
 *
 *   2. gzip-CPU. Nicht mehr bei jedem Lauf: die 4h-Laeufe dumpen nur
 *      (unkomprimiert), gepackt wird gebuendelt im 04:00-Lauf (--compress) mit
 *      pigz auf mehreren Kernen, nice 19 / ionice idle. Notventil: sinkt der
 *      freie Platz unter backup.min_free_gb oder liegen zu viele
 *      unkomprimierte Saetze herum, packt auch ein 4h-Lauf sofort.
 *
 *   3. rsync-IO. Die Web-Trees werden nur noch 1x taeglich gespiegelt
 *      (--files, im 04:00-Lauf), nicht mehr 6x.
 *
 * Ergebnis-Layout und Retention bleiben identisch zu automysqlbackup:
 *   <backup.dir>/db/{daily,weekly,monthly,latest,fullschema,status}
 *   daily_<db>_<Y-m-d>_<HHhMMm>_<Weekday>.sql[.gz]   (Retention 6 Tage)
 *   weekly_<db>_..._<isoweek>.sql[.gz]               (35 Tage, Fr)
 *   monthly_<db>_..._<Monat>.sql[.gz]               (150 Tage, 1.)
 * Web-Trees weiter unter <backup.dir><quellpfad>/ (rsync-Spiegel, in-place).
 *
 * Scheduler siehe bootstrap/app.php: `backup:run` alle 4 h,
 * `backup:run --compress --files` um 04:00 CEST.
 */
class Backup extends Command
{
    protected $signature = 'backup:run
                            {--compress : Nach dem Dump alle offenen Dumps gebuendelt mit pigz packen (04:00-Lauf)}
                            {--files : Web-Trees rsyncen (nur im 04:00-Lauf sinnvoll)}
                            {--skip-dump : Nicht dumpen, nur Kompression/latest/Rotation nachholen}
                            {--only= : Nur diese DBs dumpen (Komma-Liste); rotiert dann nicht}
                            {--dry-run : Nichts schreiben, nur anzeigen}';

    protected $description = 'DB-Dump (ohne Table-Lock) + gebuendelte pigz-Kompression + rsync der Web-Trees nach /var/www/backup';

    private string $dir;
    private string $dbDir;
    private bool $dry = false;
    private CarbonImmutable $now;
    private string $stamp;
    private string $weekday;
    private string $cnf = '';
    /** @var resource|null */
    private $lock = null;
    /** @var string[] */
    private array $errors = [];

    private const CATEGORIES = ['daily', 'weekly', 'monthly'];

    public function handle(): int
    {
        $this->dry     = (bool) $this->option('dry-run');
        $this->dir     = rtrim((string) config('backup.dir'), '/');
        $this->dbDir   = $this->dir.'/db';
        $this->now     = CarbonImmutable::now('Europe/Berlin');
        $this->stamp   = $this->now->format('Y-m-d_H\hi\m');
        $this->weekday = $this->now->format('l');

        if (! is_dir($this->dir) || ! is_writable($this->dir)) {
            $this->error("Backup-Verzeichnis fehlt oder ist nicht beschreibbar: {$this->dir}");

            return self::FAILURE;
        }

        if (! $this->acquireLock()) {
            $this->warn('Ein anderer backup:run-Lauf haelt den Lock - abgebrochen.');

            return self::SUCCESS;
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $this->line('Backup-Verzeichnis: '.$this->dir);
        $this->line('Zeitstempel:        '.$this->stamp.' ('.$this->weekday.', Europe/Berlin)');
        $this->line('Freier Platz:       '.$this->human($this->freeBytes()));
        $this->line('Modus:              '.implode(' ', array_filter([
            $this->option('skip-dump') ? 'skip-dump' : 'dump',
            $this->option('compress') ? 'compress' : null,
            $this->option('files') ? 'files' : null,
            $only ? 'only='.implode(',', $only) : null,
            $this->dry ? 'DRY-RUN' : null,
        ])));
        $this->newLine();

        try {
            $this->writeDefaultsFile();

            $dbs = $this->databases($only);
            if (! $dbs) {
                $this->error('Keine Datenbanken zu sichern (SHOW DATABASES leer oder alle ausgeschlossen).');

                return self::FAILURE;
            }
            $this->line('Datenbanken: '.implode(', ', $dbs));

            if (! $this->option('skip-dump')) {
                $this->dumpDatabases($dbs);
                $this->dumpFullSchema();
                $this->dumpStatus();

                if ($this->option('compress')) {
                    $this->makePeriodicCopies($dbs);
                }
            }

            $wantCompress = $this->option('compress')
                || $this->freeBytes() < (int) config('backup.min_free_gb') * 1_000_000_000
                || $this->pendingSets() > (int) config('backup.max_pending_sets');

            if ($wantCompress) {
                $this->compressPending();
            } else {
                $this->line('Kompression uebersprungen (kein --compress, Platz reicht) - '
                    .$this->pendingSets().' unkomprimierte(r) Satz/Saetze offen.');
            }

            if ($only) {
                $this->line('latest/ und Rotation uebersprungen (--only).');
            } else {
                $this->rebuildLatest($dbs);
                $removed = $this->rotate($dbs);
                $this->line("Rotation: {$removed} Datei(en) entfernt.");
            }

            if ($this->option('files')) {
                $this->syncFiles();
            }
        } finally {
            $this->releaseLock();
        }

        $this->newLine();
        $this->line('Freier Platz jetzt: '.$this->human($this->freeBytes()));

        if ($this->errors) {
            $this->error(count($this->errors).' Schritt(e) fehlgeschlagen:');
            foreach ($this->errors as $e) {
                $this->line('  - '.$e);
            }

            return self::FAILURE;
        }

        $this->info('Backup ok.');

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------- Dump */

    /** @return string[] */
    private function databases(array $only): array
    {
        [$code, $out, $err] = $this->sh(
            $this->mysqlBin('mysql').' --defaults-extra-file='.escapeshellarg($this->cnf).' -N -B -e '.escapeshellarg('SHOW DATABASES')
        );
        if ($code !== 0) {
            $this->recordError('SHOW DATABASES', $err ?: "exit {$code}");

            return [];
        }

        $exclude = (array) config('backup.db.exclude');
        $dbs = array_values(array_filter(
            array_map('trim', explode("\n", trim($out))),
            fn ($d) => $d !== '' && ! in_array($d, $exclude, true) && ! str_ends_with($d, '_test')
        ));

        return $only ? array_values(array_intersect($dbs, $only)) : $dbs;
    }

    private function dumpDatabases(array $dbs): void
    {
        foreach ($dbs as $db) {
            $target = "{$this->dbDir}/daily/{$db}/daily_{$db}_{$this->stamp}_{$this->weekday}.sql";
            $cmd = $this->ioPrefix('io').$this->mysqlBin('mysqldump')
                .' --defaults-extra-file='.escapeshellarg($this->cnf)
                .' --quote-names --opt --single-transaction --skip-lock-tables '
                .escapeshellarg($db);

            $this->dumpTo($target, $cmd, "Dump {$db}");
        }
    }

    private function dumpFullSchema(): void
    {
        $target = "{$this->dbDir}/fullschema/fullschema_daily_{$this->stamp}_{$this->weekday}.sql";
        $cmd = $this->ioPrefix('io').$this->mysqlBin('mysqldump')
            .' --defaults-extra-file='.escapeshellarg($this->cnf)
            .' --all-databases --routines --no-data --single-transaction --skip-lock-tables';

        $this->dumpTo($target, $cmd, 'Full-Schema');
    }

    private function dumpStatus(): void
    {
        $target = "{$this->dbDir}/status/status_daily_{$this->stamp}_{$this->weekday}.txt";
        $show = (new ExecutableFinder)->find('mysqlshow') ?: (new ExecutableFinder)->find('mariadb-show');
        $cmd = $show
            ? escapeshellarg($show).' --defaults-extra-file='.escapeshellarg($this->cnf)
            : $this->mysqlBin('mysql').' --defaults-extra-file='.escapeshellarg($this->cnf).' -e '.escapeshellarg('SHOW DATABASES');

        $this->dumpTo($target, $cmd, 'DB-Status');
    }

    /**
     * Fuehrt $cmd aus und leitet stdout nach $target um. Schreibt erst nach
     * <target>.part und benennt bei Erfolg um, damit nie ein halber Dump in
     * latest/ oder der Rotation landet.
     */
    private function dumpTo(string $target, string $cmd, string $label): void
    {
        $this->line("  {$label} -> ".$this->rel($target));
        if ($this->dry) {
            return;
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->recordError($label, "kann {$dir} nicht anlegen");

            return;
        }

        $part = $target.'.part';
        [$code, , $err] = $this->sh($cmd.' > '.escapeshellarg($part), 3600);

        if ($code !== 0) {
            @unlink($part);
            $this->recordError($label, trim($err) ?: "exit {$code}");

            return;
        }

        // mysqldump beendet mit "-- Dump completed on ..."; mysqlshow-Status
        // ist eine kleine Tabelle. Nur echte Dumps auf Vollstaendigkeit pruefen.
        if (str_contains($cmd, 'mysqldump')) {
            $size = (int) @filesize($part);
            $tail = $size > 0 ? (string) file_get_contents($part, false, null, max(0, $size - 200)) : '';
            if ($size === 0 || ! str_contains($tail, 'Dump completed')) {
                @unlink($part);
                $this->recordError($label, 'Dump unvollstaendig (kein "Dump completed")');

                return;
            }
        }

        @rename($part, $target);
        $this->info('    '.$this->human((int) @filesize($target)));
    }

    /**
     * weekly-/monthly-Kopie aus dem frischen Daily ziehen (kein zweiter Dump).
     * Idempotent: existiert die Zieldatei fuer diese Woche / diesen Monat
     * schon, passiert nichts.
     */
    private function makePeriodicCopies(array $dbs): void
    {
        $jobs = [];
        if ((int) $this->now->dayOfWeekIso === (int) config('backup.weekly_on')) {
            $jobs['weekly'] = ltrim($this->now->format('W'), '0');
        }
        if ((int) $this->now->format('j') === (int) config('backup.monthly_on')) {
            $jobs['monthly'] = $this->now->format('F');
        }
        if (! $jobs) {
            return;
        }

        foreach ($jobs as $period => $midfix) {
            $this->line(ucfirst($period).'-Kopien ('.$midfix.'):');
            foreach ($dbs as $db) {
                $src = "{$this->dbDir}/daily/{$db}/daily_{$db}_{$this->stamp}_{$this->weekday}.sql";
                $dstDir = "{$this->dbDir}/{$period}/{$db}";
                $dst = "{$dstDir}/{$period}_{$db}_{$this->stamp}_{$midfix}.sql";

                $existing = glob("{$dstDir}/{$period}_{$db}_".$this->now->format('Y-m-d')."_*_{$midfix}.sql*") ?: [];
                if ($existing) {
                    continue;
                }
                $this->line('  '.$db.' -> '.$this->rel($dst));
                if ($this->dry) {
                    continue;
                }
                if (! is_file($src)) {
                    $this->recordError("{$period} {$db}", 'kein frisches Daily vorhanden');

                    continue;
                }
                if (! is_dir($dstDir)) {
                    @mkdir($dstDir, 0755, true);
                }
                if (! @copy($src, $dst)) {
                    $this->recordError("{$period} {$db}", 'copy fehlgeschlagen');
                }
            }
        }
    }

    /* --------------------------------------------------------- Kompression */

    private function compressPending(): void
    {
        $bin = (new ExecutableFinder)->find('pigz');
        $threads = max(1, (int) config('backup.compress.threads'));
        if ($bin) {
            $bin = escapeshellarg($bin)." -p{$threads}";
        } else {
            $bin = 'gzip';
            $this->warn('pigz nicht gefunden - Fallback auf single-threaded gzip. '
                .'Fuer die CPU-Entlastung pigz ins Container-Image aufnehmen.');
            logger()->notice('backup:run: pigz fehlt, gzip-Fallback aktiv.');
        }

        $level = (int) config('backup.compress.level');
        $prefix = $this->ioPrefix('compress');

        $files = $this->pendingFiles();
        if (! $files) {
            $this->line('Kompression: nichts offen.');

            return;
        }

        $this->line('Kompression ('.count($files).' Datei(en), '.($bin === 'gzip' ? 'gzip' : "pigz -p{$threads}").'):');
        foreach ($files as $f) {
            $this->line('  '.$this->rel($f).' ('.$this->human((int) @filesize($f)).')');
            if ($this->dry) {
                continue;
            }
            [$code, , $err] = $this->sh($prefix.$bin." -f -{$level} -- ".escapeshellarg($f), 3600);
            if ($code !== 0 || ! is_file($f.'.gz')) {
                $this->recordError('Kompression '.basename($f), trim($err) ?: "exit {$code}");

                continue;
            }
            $this->info('    -> '.$this->human((int) @filesize($f.'.gz')));
        }
    }

    /** @return string[] unkomprimierte Dump-/Status-Dateien unter db/ */
    private function pendingFiles(): array
    {
        $out = [];
        foreach (['daily', 'weekly', 'monthly'] as $p) {
            foreach (glob("{$this->dbDir}/{$p}/*", GLOB_ONLYDIR) ?: [] as $sub) {
                $out = array_merge($out, glob("{$sub}/{$p}_*.sql") ?: []);
            }
        }
        foreach (['fullschema', 'status'] as $flat) {
            $out = array_merge($out, glob("{$this->dbDir}/{$flat}/*.sql") ?: [], glob("{$this->dbDir}/{$flat}/*.txt") ?: []);
        }

        return array_values(array_filter($out, fn ($f) => is_file($f) && ! is_file($f.'.gz') && ! str_ends_with($f, '.part')));
    }

    /** Wieviele 4h-Dump-Saetze liegen unkomprimiert herum (nach daily-Zeitstempel gezaehlt). */
    private function pendingSets(): int
    {
        $stamps = [];
        foreach (glob("{$this->dbDir}/daily/*", GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob("{$sub}/daily_*.sql") ?: [] as $f) {
                if (preg_match('/_(\d{4}-\d{2}-\d{2}_\d{2}h\d{2}m)_/', basename($f), $m)) {
                    $stamps[$m[1]] = true;
                }
            }
        }

        return count($stamps);
    }

    /* ------------------------------------------------------------- latest/ */

    private function rebuildLatest(array $dbs): void
    {
        $latest = "{$this->dbDir}/latest";
        $this->line('latest/ neu aufbauen');
        if ($this->dry) {
            return;
        }
        if (! is_dir($latest)) {
            @mkdir($latest, 0755, true);
        }
        foreach (glob("{$latest}/*") ?: [] as $x) {
            @unlink($x);
        }

        $link = function (?string $src) use ($latest) {
            if ($src === null) {
                return;
            }
            $dst = $latest.'/'.basename($src);
            if (! @link($src, $dst)) {
                @copy($src, $dst); // andere Partition o. ae. - Fallback
            }
        };

        foreach ($dbs as $db) {
            $link($this->newest("{$this->dbDir}/daily/{$db}/daily_{$db}_*"));
        }
        $link($this->newest("{$this->dbDir}/fullschema/fullschema_daily_*"));
        $link($this->newest("{$this->dbDir}/status/status_daily_*"));
    }

    /* ------------------------------------------------------------ Rotation */

    /**
     * Rotiert wie automysqlbackup nach Alter (mtime), aber nur fuer die
     * aktuell gesicherten DBs plus die flachen fullschema-/status-Ordner.
     * Verzeichnisse ausgemusterter DBs (z. B. die alten *_test) bleiben
     * unangetastet - die raeumt man einmalig von Hand weg.
     */
    private function rotate(array $dbs): int
    {
        $keep = (array) config('backup.keep');
        $root = realpath($this->dbDir) ?: $this->dbDir;
        $removed = 0;

        $kill = function (array $files, string $re, int $days) use (&$removed, $root) {
            $cutoff = time() - $days * 86400;
            foreach ($files as $f) {
                if (! is_file($f) || ! preg_match($re, basename($f))) {
                    continue;
                }
                if (! str_starts_with(realpath($f) ?: '', $root)) {
                    continue;
                }
                if (filemtime($f) < $cutoff) {
                    if ($this->dry) {
                        $removed++;
                        if ($removed <= 8) {
                            $this->line('  wuerde entfernen: '.$this->rel($f));
                        }
                    } elseif (@unlink($f)) {
                        $removed++;
                    }
                }
            }
        };

        foreach (self::CATEGORIES as $p) {
            $days = (int) ($keep[$p] ?? 9999);
            foreach ($dbs as $db) {
                $kill(glob("{$this->dbDir}/{$p}/{$db}/{$p}_*") ?: [], '/^'.$p.'_.*\.(sql|txt)(\.gz)?$/', $days);
            }
        }
        foreach (['fullschema', 'status'] as $flat) {
            foreach (self::CATEGORIES as $p) {
                $days = (int) ($keep[$p] ?? 9999);
                $kill(glob("{$this->dbDir}/{$flat}/{$flat}_{$p}_*") ?: [], '/^'.$flat.'_'.$p.'_.*\.(sql|txt)(\.gz)?$/', $days);
            }
        }

        if ($this->dry && $removed > 8) {
            $this->line('  … und '.($removed - 8).' weitere');
        }

        return $removed;
    }

    /* --------------------------------------------------------------- Files */

    private function syncFiles(): void
    {
        $rsync = (new ExecutableFinder)->find('rsync');
        if (! $rsync) {
            $this->recordError('rsync', 'nicht installiert');

            return;
        }

        $exFile = $this->tmp('bkp-ex');
        file_put_contents($exFile, implode("\n", (array) config('backup.files.excludes'))."\n");

        try {
            foreach ((array) config('backup.files.sources') as $src) {
                $src = rtrim($src, '/');
                if (! is_dir($src)) {
                    $this->warn("  Quelle fehlt: {$src}");

                    continue;
                }
                $dst = $this->dir.$src;
                $this->line("  rsync {$src}/ -> ".$this->rel($dst).'/');
                if ($this->dry) {
                    continue;
                }
                if (! is_dir($dst)) {
                    @mkdir($dst, 0755, true);
                }
                $cmd = $this->ioPrefix('io').escapeshellarg($rsync)
                    .' -aH --delete --delete-excluded --exclude-from='.escapeshellarg($exFile)
                    .' '.escapeshellarg($src.'/').' '.escapeshellarg($dst.'/');
                [$code, , $err] = $this->sh($cmd, 3600);
                // 24 = "some source files vanished before transfer" - unkritisch
                if (! in_array($code, [0, 24], true)) {
                    $this->recordError("rsync {$src}", trim($err) ?: "exit {$code}");
                }
            }
        } finally {
            @unlink($exFile);
        }
    }

    /* --------------------------------------------------------------- Utils */

    private function writeDefaultsFile(): void
    {
        $this->cnf = $this->tmp('bkp-cnf');
        $c = config('backup.db');
        file_put_contents($this->cnf, implode("\n", [
            '[client]',
            'host='.$c['host'],
            'port='.$c['port'],
            'user='.$c['user'],
            'password="'.str_replace('"', '\"', (string) $c['pass']).'"',
            '',
        ]));
        @chmod($this->cnf, 0600);
    }

    private function tmp(string $prefix): string
    {
        $f = tempnam(sys_get_temp_dir(), $prefix);
        register_shutdown_function(fn () => @unlink($f));

        return $f;
    }

    private function mysqlBin(string $name): string
    {
        $p = (new ExecutableFinder)->find($name)
            ?: (new ExecutableFinder)->find(str_replace('mysql', 'mariadb', $name));

        return escapeshellarg($p ?: $name);
    }

    private function ioPrefix(string $which): string
    {
        $nice = (new ExecutableFinder)->find('nice');
        $ionice = (new ExecutableFinder)->find('ionice');
        $cfg = $which === 'compress'
            ? ['nice' => (int) config('backup.compress.nice'), 'io' => (array) config('backup.compress.ionice')]
            : ['nice' => (int) config('backup.io.nice'), 'io' => (array) config('backup.io.ionice')];

        $parts = [];
        if ($nice) {
            $parts[] = escapeshellarg($nice)." -n{$cfg['nice']}";
        }
        if ($ionice) {
            $io = escapeshellarg($ionice).' -c'.((int) $cfg['io']['class']);
            if (($cfg['io']['level'] ?? null) !== null) {
                $io .= ' -n'.((int) $cfg['io']['level']);
            }
            $parts[] = $io;
        }

        return $parts ? implode(' ', $parts).' ' : '';
    }

    /** @return array{0:int,1:string,2:string} */
    private function sh(string $cmd, ?int $timeout = 120): array
    {
        $p = Process::fromShellCommandline($cmd, $this->dir, null, null, $timeout);
        $p->run();

        return [(int) $p->getExitCode(), $p->getOutput(), $p->getErrorOutput()];
    }

    private function newest(string $glob): ?string
    {
        $files = array_filter(glob($glob) ?: [], fn ($f) => is_file($f) && ! str_ends_with($f, '.part'));
        if (! $files) {
            return null;
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    private function recordError(string $step, string $why): void
    {
        $msg = "{$step}: {$why}";
        $this->errors[] = $msg;
        $this->error('    '.$msg);
    }

    private function freeBytes(): int
    {
        return (int) (disk_free_space($this->dir) ?: 0);
    }

    private function rel(string $path): string
    {
        return str_starts_with($path, $this->dir.'/') ? substr($path, strlen($this->dir) + 1) : $path;
    }

    private function human(int $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($u) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, $n < 10 && $i > 0 ? 1 : 0).' '.$u[$i];
    }

    private function acquireLock(): bool
    {
        $path = storage_path('app/backup.lock');
        $this->lock = @fopen($path, 'c');
        if (! $this->lock) {
            $this->lock = @fopen(sys_get_temp_dir().'/pthranking-backup.lock', 'c');
        }
        if (! $this->lock) {
            return true; // ohne Lock-Datei lieber laufen als gar nicht
        }
        @chmod($path, 0666);

        return flock($this->lock, LOCK_EX | LOCK_NB);
    }

    private function releaseLock(): void
    {
        if ($this->lock) {
            @flock($this->lock, LOCK_UN);
            @fclose($this->lock);
            $this->lock = null;
        }
    }
}
