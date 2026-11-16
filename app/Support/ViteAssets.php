<?php

namespace App\Support;

/**
 * Gehashte Asset-URLs aus public/.vite/manifest.json (siehe vite.config.js).
 *
 * Bewusst nicht Laravels @vite: das erwartet public/build/ und baut die URLs
 * über APP_URL, hier liegt der Build direkt in public/ unter /pthranking/.
 * Dieselbe Logik steckt fürs Forum in ext/inquies/pokerth/event/listener.php.
 */
class ViteAssets
{
    private const BASE_URL = '/pthranking/';

    private static ?array $manifest = null;

    /**
     * @return array{js: string, css: list<string>}
     */
    public static function entry(string $src, string $fallbackJs, array $fallbackCss = []): array
    {
        $entry = self::manifest()[$src] ?? null;
        if (!$entry || empty($entry['file'])) {
            return ['js' => self::BASE_URL . $fallbackJs, 'css' => array_map(fn ($c) => self::BASE_URL . $c, $fallbackCss)];
        }
        return [
            'js'  => self::BASE_URL . $entry['file'],
            'css' => array_map(fn ($c) => self::BASE_URL . $c, $entry['css'] ?? []),
        ];
    }

    private static function manifest(): array
    {
        if (self::$manifest === null) {
            $path = public_path('.vite/manifest.json');
            $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$manifest = is_array($data) ? $data : [];
        }
        return self::$manifest;
    }
}
