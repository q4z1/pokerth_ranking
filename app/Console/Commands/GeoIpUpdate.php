<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Lädt die freie "IP to City Lite"-Datenbank von DB-IP (CC BY 4.0, monatlich
 * neu) als mmdb herunter. Lookups laufen danach komplett lokal, siehe
 * App\Services\GeoIpService – keine Spieler-IP verlässt den Server.
 */
class GeoIpUpdate extends Command
{
    protected $signature = 'geoip:update {--force : Download even if the current month is already installed}';
    protected $description = 'Download the DB-IP City Lite database used for IP geolocation in the admin backend';

    public function handle(): int
    {
        $target = config('services.geoip.path');
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            $this->error("Cannot create {$dir}.");
            return self::FAILURE;
        }

        // Die Datei des laufenden Monats erscheint erst am Monatsanfang;
        // bis dahin die vom Vormonat nehmen.
        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $tag = $month->format('Y-m');
            $stamp = $dir . '/.version';
            if (!$this->option('force') && is_file($target) && trim((string) @file_get_contents($stamp)) === $tag) {
                $this->info("DB-IP City Lite {$tag} is already installed.");
                return self::SUCCESS;
            }

            $url = "https://download.db-ip.com/free/dbip-city-lite-{$tag}.mmdb.gz";
            $gz = $target . '.download.gz';
            $res = Http::timeout(300)->withOptions(['sink' => $gz])->get($url);
            if (!$res->successful()) {
                @unlink($gz);
                $this->warn("{$url}: HTTP {$res->status()}");
                continue;
            }

            $tmp = $target . '.new';
            $in = gzopen($gz, 'rb');
            $out = fopen($tmp, 'wb');
            while (!gzeof($in)) fwrite($out, gzread($in, 1 << 20));
            gzclose($in);
            fclose($out);
            @unlink($gz);

            // Erst prüfen, dann atomar austauschen – eine kaputte Datei darf
            // die funktionierende nicht ersetzen.
            try {
                $reader = new \MaxMind\Db\Reader($tmp);
                $reader->get('8.8.8.8');
                $reader->close();
            } catch (\Throwable $e) {
                @unlink($tmp);
                $this->error('Downloaded database is invalid: ' . $e->getMessage());
                return self::FAILURE;
            }
            rename($tmp, $target);
            file_put_contents($stamp, $tag);
            $this->info("Installed DB-IP City Lite {$tag} (" . round(filesize($target) / 1048576) . ' MB).');
            return self::SUCCESS;
        }

        $this->error('No DB-IP City Lite download available.');
        return self::FAILURE;
    }
}
