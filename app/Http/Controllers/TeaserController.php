<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class TeaserController extends Controller
{
    public function weekly(Request $request)
    {
        $now = Carbon::now();
        $weekKey = $now->format('oW');
        $files = Storage::disk('public')->files('teaser');

        $nextWeekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->addWeek();
        $seconds = $nextWeekStart->diffInSeconds($now);

        $cacheKey = "random_weekly_teaser_{$weekKey}";
        $random_list = Cache::remember($cacheKey, $seconds, function () use ($files) {
            $list = $files;
            shuffle($list);
            return $list;
        });

        $index = intval($now->format('W')) % max(1, count($random_list));
        $file = $random_list[$index] ?? ($files[0] ?? null);
        if (! $file) {
            abort(404);
        }

        $path = storage_path('app/public/' . $file);

        // Die Vorlagen sind 1536 px breit und wurden bisher auch an ein 412 px
        // schmales Display ausgeliefert – rund vierzehnmal so viele Pixel wie
        // gebraucht. Das Template hängt die passende Stufe als ?w= an.
        $width = $this->requestedWidth($request);

        // Das Bild ist auf jeder Seite das LCP-Element. Als JPEG sind das je
        // nach Motiv 170–320 KB; als WebP bleibt rund ein Viertel davon übrig.
        if ($this->acceptsWebp($request) && ($webp = $this->variant($path, $width, 'webp'))) {
            return $this->send($webp, 'image/webp', $seconds);
        }

        if ($width && ($jpeg = $this->variant($path, $width, 'jpg'))) {
            return $this->send($jpeg, 'image/jpeg', $seconds);
        }

        // Bisher stand hier fest 'image/png', obwohl in storage JPEGs liegen.
        // Browser haben das per Sniffing überspielt, Caches nicht immer.
        $mime = @mime_content_type($path) ?: 'image/jpeg';

        return $this->send($path, $mime, $seconds);
    }

    protected function send(string $path, string $mime, int $seconds)
    {
        return response()->file($path, [
            'Content-Type'  => $mime,
            // Das Motiv wechselt wöchentlich, also genau bis dahin cachen.
            'Cache-Control' => 'public, max-age=' . max(60, $seconds),
            'Vary'          => 'Accept',
        ]);
    }

    protected function acceptsWebp(Request $request): bool
    {
        return str_contains((string) $request->header('Accept', ''), 'image/webp');
    }

    /** Erlaubte Stufen – alles andere wird ignoriert, damit ?w= keine
     *  beliebig grosse Bildergalerie auf der Platte erzeugen kann. */
    protected const WIDTHS = [640, 1024, 1536];

    protected function requestedWidth(Request $request): ?int
    {
        $w = (int) $request->query('w', 0);

        return in_array($w, self::WIDTHS, true) ? $w : null;
    }

    /**
     * Liefert den Pfad zur abgeleiteten Fassung und legt sie beim ersten
     * Abruf an. Schlägt irgendetwas fehl, gibt es null zurück und der
     * Aufrufer bleibt beim Original – ein fehlendes Derivat darf die Seite
     * nicht kosten.
     */
    protected function variant(string $path, ?int $width, string $format): ?string
    {
        if ($format === 'webp' && ! function_exists('imagewebp')) {
            return null;
        }

        $name = pathinfo($path, PATHINFO_FILENAME) . ($width ? '-' . $width : '') . '.' . $format;
        $target = storage_path('app/teaser-webp/' . $name);

        if (is_file($target) && filemtime($target) >= filemtime($path)) {
            return $target;
        }

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0775, true) && ! is_dir(dirname($target))) {
            return null;
        }

        $image = @imagecreatefromstring((string) @file_get_contents($path));
        if ($image === false) {
            return null;
        }

        // Nur verkleinern, nie hochrechnen.
        if ($width && imagesx($image) > $width) {
            $scaled = @imagescale($image, $width);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        // Erst in eine temporäre Datei, dann umbenennen: bei parallelen
        // Aufrufen sieht sonst einer eine halb geschriebene Datei.
        // Qualität 68: über dem Bild liegt im Style ein abdunkelndes Overlay
        // (headerbar_overlay_darken), Feinzeichnung ist dort nicht sichtbar.
        // Gegenüber 82 sind das noch einmal gut ein Drittel weniger Bytes.
        $tmp = $target . '.' . getmypid() . '.tmp';
        $ok = $format === 'webp'
            ? @imagewebp($image, $tmp, 68)
            : @imagejpeg($image, $tmp, 68);
        imagedestroy($image);

        if (! $ok || ! @rename($tmp, $target)) {
            @unlink($tmp);
            return null;
        }

        return $target;
    }
}
