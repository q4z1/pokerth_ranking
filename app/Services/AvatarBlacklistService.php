<?php

namespace App\Services;

use App\Models\AvatarBlacklist;

/**
 * Zentrale Stelle für gesperrte Avatare.
 *
 * Hintergrund: die Tabelle `avatar_blacklist` wurde bisher ausschließlich vom
 * Game-Server ausgewertet. Die Webseite kannte sie nicht und lieferte gesperrte
 * Avatare weiterhin aus – sowohl im Player-JSON als auch als statische Datei
 * unter /images/avatars/game/<hash>.<ext>, an PHP völlig vorbei.
 *
 * Deshalb zwei Ebenen:
 *  1. Ausblenden in der API  -> \App\Models\Player::getAvatarHashAttribute()
 *  2. Datei aus dem öffentlichen Verzeichnis in die Quarantäne verschieben
 *
 * Ohne (2) bliebe die Datei unter ihrer direkten URL erreichbar.
 *
 * Die Quarantäne liegt unter storage/ und damit weder im Web-Root (sonst wäre
 * sie über /images/… wieder öffentlich) noch im Syncthing-Ordner
 * images/avatars/game (dessen .stfolder-Marker die Wurzel markiert).
 */
class AvatarBlacklistService
{
    /** Öffentliches Avatar-Verzeichnis, relativ zum phpBB-Root (= base_path()/..). */
    private const GAME_DIR = '/../images/avatars/game';

    /** Quarantäne, relativ zu storage_path() – bewusst außerhalb des Web-Roots. */
    private const QUARANTINE_DIR = 'app/blacklisted-avatars';

    /**
     * Gesperrte Hashes als Lookup-Map (hash => true).
     *
     * Bewusst nur pro Request gemerkt und nicht über den Cache: PlayerController
     * ruft an anderer Stelle Cache::flush() auf, und die Tabelle ist mit ~30
     * Zeilen klein genug, dass ein SELECT pro Request nicht ins Gewicht fällt.
     *
     * @var array<string, bool>|null
     */
    private ?array $hashes = null;

    /** @return array<string, bool> */
    public function hashes(): array
    {
        if ($this->hashes === null) {
            $this->hashes = AvatarBlacklist::pluck('avatar_hash')
                ->filter()
                ->mapWithKeys(fn($hash) => [strtolower(trim($hash)) => true])
                ->all();
        }
        return $this->hashes;
    }

    public function isBlacklisted(?string $hash): bool
    {
        $hash = $this->normalize($hash);
        return $hash !== null && isset($this->hashes()[$hash]);
    }

    /**
     * Setzt einen Avatar auf die Blacklist *und* räumt die Datei weg.
     *
     * Der Eintrag in player.avatar_hash bleibt absichtlich stehen: diese Spalte
     * gehört dem Game-Server, der sie beim nächsten Login ohnehin wieder
     * schreiben würde. Das Ausblenden passiert im Model-Accessor, damit die
     * Sperre umkehrbar bleibt – Blacklist-Eintrag entfernen, Datei
     * zurückschieben, fertig.
     *
     * @return array{hash: string, added: bool, files: array<int, string>}
     */
    public function blacklist(string $hash): array
    {
        $normalized = $this->normalize($hash);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid avatar hash: ' . $hash);
        }

        $added = false;
        if (!$this->isBlacklisted($normalized)) {
            AvatarBlacklist::create(['avatar_hash' => $normalized]);
            $this->hashes = null;
            $added = true;
        }

        return [
            'hash'  => $normalized,
            'added' => $added,
            'files' => $this->quarantine($normalized),
        ];
    }

    /**
     * Verschiebt alle zum Hash gehörenden Dateien in die Quarantäne.
     *
     * @return array<int, string> tatsächlich verschobene Dateinamen
     */
    public function quarantine(string $hash): array
    {
        $files = $this->publicFiles($hash);
        if (!count($files)) return [];

        $target = $this->quarantineDir();
        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            throw new \RuntimeException('Could not create quarantine directory: ' . $target);
        }

        $moved = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ($this->move($file, $target . '/' . $name)) {
                $moved[] = $name;
            }
        }
        return $moved;
    }

    /**
     * Dateien, die aktuell noch öffentlich unter /images/avatars/game/ liegen.
     *
     * @return array<int, string> absolute Pfade
     */
    public function publicFiles(string $hash): array
    {
        $normalized = $this->normalize($hash);
        if ($normalized === null) return [];
        return glob($this->gameDir() . '/' . $normalized . '.*') ?: [];
    }

    /** Pfad einer bereits einquarantänten Datei, oder null. */
    public function quarantinedFile(string $hash): ?string
    {
        $normalized = $this->normalize($hash);
        if ($normalized === null) return null;
        $files = glob($this->quarantineDir() . '/' . $normalized . '.*') ?: [];
        return $files[0] ?? null;
    }

    public function gameDir(): string
    {
        return base_path() . self::GAME_DIR;
    }

    public function quarantineDir(): string
    {
        return storage_path(self::QUARANTINE_DIR);
    }

    /**
     * Hashes sind md5-Hex. Alles andere wird abgewiesen – sonst könnten
     * Fremdeingaben über glob()/rename() aus dem Avatar-Verzeichnis ausbrechen.
     */
    private function normalize(?string $hash): ?string
    {
        if ($hash === null) return null;
        $hash = strtolower(trim($hash));
        return preg_match('/^[0-9a-f]{8,128}$/', $hash) ? $hash : null;
    }

    /** rename() schlägt über Dateisystemgrenzen fehl, daher der Fallback. */
    private function move(string $from, string $to): bool
    {
        if (@rename($from, $to)) return true;
        if (@copy($from, $to)) return @unlink($from);
        return false;
    }
}
