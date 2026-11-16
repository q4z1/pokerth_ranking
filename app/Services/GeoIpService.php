<?php

namespace App\Services;

use MaxMind\Db\Reader;

/**
 * IP → Land/Region/Stadt aus der lokalen DB-IP-City-Lite-Datenbank
 * (php artisan geoip:update). Fehlt die Datenbank, liefert lookup() null –
 * die Seiten funktionieren dann ohne Ortsangabe weiter.
 */
class GeoIpService
{
    private ?Reader $reader = null;
    private bool $opened = false;
    private array $cache = [];

    /** @return array{country:?string, country_name:?string, region:?string, city:?string}|null */
    public function lookup(?string $ip): ?array
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
        if (array_key_exists($ip, $this->cache)) return $this->cache[$ip];

        $reader = $this->reader();
        $geo = null;
        if ($reader) {
            try {
                $r = $reader->get($ip);
                if (is_array($r)) {
                    $geo = [
                        'country'      => $r['country']['iso_code'] ?? null,
                        'country_name' => $r['country']['names']['en'] ?? null,
                        'region'       => $r['subdivisions'][0]['names']['en'] ?? null,
                        'city'         => $r['city']['names']['en'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                $geo = null;
            }
        }
        return $this->cache[$ip] = $geo;
    }

    /** @return array<string, array|null> IP → Geo */
    public function lookupMany(iterable $ips): array
    {
        $out = [];
        foreach ($ips as $ip) {
            if ($ip && !array_key_exists($ip, $out)) $out[$ip] = $this->lookup($ip);
        }
        return $out;
    }

    public function available(): bool
    {
        return $this->reader() !== null;
    }

    private function reader(): ?Reader
    {
        if (!$this->opened) {
            $this->opened = true;
            $path = config('services.geoip.path');
            if ($path && is_readable($path)) {
                try {
                    $this->reader = new Reader($path);
                } catch (\Throwable $e) {
                    $this->reader = null;
                }
            }
        }
        return $this->reader;
    }
}
