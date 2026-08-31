<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Liest die Release-Infos für den PokerTH-Client aus der GitHub-API.
 *
 * Hintergrund: die Installer werden nicht mehr selbst gehostet, sondern von
 * GitHub Releases ausgeliefert (bessere Bandbreite, offizielle Binaries, die
 * Checksummen berechnet GitHub selbst). Die Download-Seite zeigt nur noch das
 * jeweils neueste Release. Den Snapshot dafür schreibt `php artisan
 * downloads:sync` nach storage/app, damit im Seitenaufruf kein API-Call nötig
 * ist – die GitHub-API begrenzt anonyme Zugriffe auf 60/h und IP.
 */
class GithubReleases
{
    /** Upstream-Repo, das die offiziellen Release-Binaries veröffentlicht. */
    public const REPO = 'pokerth/pokerth';

    /** Snapshot-Datei auf der lokalen Disk (storage/app). */
    public const SNAPSHOT = 'downloads-latest.json';

    /** Übersichtsseite mit allen (auch älteren) Releases. */
    public function releasesUrl(): string
    {
        return 'https://github.com/' . self::REPO . '/releases';
    }

    /**
     * Neuestes veröffentlichtes Release, normalisiert auf die Felder, die die
     * Download-Seite braucht. null bei Netz-/API-Fehler oder wenn das Release
     * keine herunterladbaren Assets hat.
     */
    public function latest(): ?array
    {
        try {
            // Der Hoster verliert gelegentlich TLS-Handshakes; ein paar Versuche
            // fangen das ab, ohne dass der Sync-Lauf gleich scheitert.
            $res = Http::timeout(8)
                ->retry(3, 500, throw: false)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/repos/' . self::REPO . '/releases/latest');

            if (!$res->successful()) {
                return null;
            }

            return $this->normalize($res->json() ?: []);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * GitHub-Release-JSON -> schlanker Snapshot.
     *
     * Die MD5SUMS-Datei fliegt raus; der SHA256 je Asset kommt direkt aus dem
     * von GitHub berechneten `digest`-Feld.
     */
    private function normalize(array $release): ?array
    {
        $tag = $release['tag_name'] ?? null;
        if (!$tag) {
            return null;
        }

        $files = [];
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? null;
            $url  = $asset['browser_download_url'] ?? null;
            if (!$name || !$url || stripos($name, 'MD5SUMS') !== false) {
                continue;
            }

            $digest = $asset['digest'] ?? '';
            $sha256 = (is_string($digest) && str_starts_with($digest, 'sha256:'))
                ? substr($digest, 7)
                : null;

            $files[] = [
                'filename' => $name,
                'url'      => $url,
                'size'     => $asset['size'] ?? null,
                'sha256'   => $sha256,
            ];
        }

        if (empty($files)) {
            return null;
        }

        return [
            'version'      => ltrim($tag, 'vV'),
            'tag'          => $tag,
            'html_url'     => $release['html_url'] ?? ('https://github.com/' . self::REPO . '/releases/tag/' . $tag),
            'published_at' => $release['published_at'] ?? null,
            'body'         => trim((string) ($release['body'] ?? '')),
            'synced_at'    => now()->toIso8601String(),
            'files'        => $files,
        ];
    }
}
