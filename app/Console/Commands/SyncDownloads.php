<?php

namespace App\Console\Commands;

use App\Services\GithubReleases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Holt das neueste PokerTH-Release von GitHub und legt einen Snapshot unter
 * storage/app/downloads-latest.json ab. Die Download-Seite (/pthranking, Route
 * /downloads/all) rendert ausschliesslich diesen Snapshot – es gibt keine
 * selbst gehosteten Client-Dateien mehr und im Seitenaufruf keinen API-Call.
 *
 * Nach jedem Upstream-Release einmal aufrufen:  php artisan downloads:sync
 * (läuft zusätzlich täglich über den Scheduler, siehe bootstrap/app.php).
 */
class SyncDownloads extends Command
{
    protected $signature = 'downloads:sync {--dry-run : Nur anzeigen, was geholt würde – nichts schreiben}';

    protected $description = 'Fetch the latest PokerTH release from GitHub and store the downloads snapshot';

    public function handle(GithubReleases $github): int
    {
        $snapshot = $github->latest();

        if ($snapshot === null) {
            $this->error('GitHub-API nicht erreichbar oder Release ohne Assets – Snapshot bleibt unverändert.');
            return self::FAILURE;
        }

        $this->info("Neuestes Release: {$snapshot['tag']}  (veröffentlicht {$snapshot['published_at']})");
        $this->table(
            ['Datei', 'Größe', 'SHA256'],
            collect($snapshot['files'])->map(fn ($f) => [
                $f['filename'],
                $f['size'] ? number_format($f['size'] / 1048576, 1) . ' MB' : '?',
                $f['sha256'] ? substr($f['sha256'], 0, 16) . '…' : '—',
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->comment('dry-run: nichts geschrieben.');
            return self::SUCCESS;
        }

        // Erst löschen, dann neu schreiben: der Scheduler läuft als root, ein
        // manueller Aufruf als devuser. storage/app ist für beide beschreibbar,
        // eine bereits vom anderen Nutzer angelegte Datei sonst nicht.
        Storage::disk('local')->delete(GithubReleases::SNAPSHOT);
        Storage::disk('local')->put(
            GithubReleases::SNAPSHOT,
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Fallback-Cache des Controllers verwerfen, damit die Seite sofort den
        // frischen Snapshot zeigt. Nur relevant, wenn zwischenzeitlich der
        // Live-Fallback griff; schlägt der Zugriff fehl, ist das unkritisch.
        try {
            cache()->forget('downloads.latest_fallback');
        } catch (\Throwable $e) {
            // ignorieren
        }

        $this->info('Snapshot geschrieben: storage/app/' . GithubReleases::SNAPSHOT);
        return self::SUCCESS;
    }
}
