<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Baut die sitemap.xml für das Forum und legt sie im Web-Root neben index.php ab.
 *
 * Aufgenommen wird nur, was ein Gast auch wirklich sehen kann: die Foren-Rechte
 * kommen aus phpbb_acl_groups (Gruppe GUESTS), damit z.B. das Admin-Forum nicht
 * in der Sitemap landet. Ungenehmigte oder gelöschte Themen (topic_visibility != 1)
 * bleiben ebenfalls draußen.
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate
                            {--output= : Zielpfad der XML-Datei (Default: Web-Root/sitemap.xml)}
                            {--dry-run : Nur zählen und Vorschau zeigen, nichts schreiben}';

    protected $description = 'Generate sitemap.xml for the phpBB forum';

    /** Datenbank des Forums – die Ranking-App liegt in einer eigenen, daher qualifiziert. */
    protected string $forumDb;

    /** Gecachte Werte aus phpbb_config, siehe forumConfig(). */
    protected ?Collection $forumConfig = null;

    /** phpBB-Gruppen-ID der Gäste (Standard-Installation: 1). */
    protected const GUEST_GROUP = 1;

    /** Custom-Pages, die nicht in die Sitemap gehören. */
    protected array $skipPages = ['test', 'home'];

    /** Suchmaschinen akzeptieren max. 50.000 URLs pro Datei. */
    protected const MAX_URLS = 50000;

    public function handle(): int
    {
        $this->forumDb = env('FORUM_DB', 'pokerth');

        $base = $this->baseUrl();
        $this->line('Base-URL: ' . $base);

        $forumIds = $this->guestReadableForumIds();
        if (!count($forumIds)) {
            $this->error('Keine für Gäste lesbaren Foren gefunden – Sitemap wird nicht geschrieben.');
            return self::FAILURE;
        }

        $urls = array_merge(
            $this->boardUrls($base, $forumIds),
            $this->pageUrls($base),
            $this->forumUrls($base, $forumIds),
            $this->topicUrls($base, $forumIds),
        );

        if (count($urls) > self::MAX_URLS) {
            // Themen stehen nach Aktualität sortiert hinten, abgeschnitten werden
            // also die ältesten – bis die Sitemap auf mehrere Dateien aufgeteilt ist.
            $this->warn(count($urls) . ' URLs – über dem Limit von ' . self::MAX_URLS . ', die Sitemap muss aufgeteilt werden.');
            $urls = array_slice($urls, 0, self::MAX_URLS);
        }

        $xml = $this->render($urls);
        $target = $this->option('output') ?: $this->defaultTarget();

        $this->info(count($urls) . ' URLs (' . number_format(strlen($xml) / 1024, 1) . ' KB)');

        if ($this->option('dry-run')) {
            $this->warn('Dry run – es wird nichts geschrieben. Ziel wäre: ' . $target);
            foreach (array_slice($urls, 0, 5) as $url) $this->line('  ' . $url['loc']);
            $this->line('  ...');
            return self::SUCCESS;
        }

        if (!$this->write($target, $xml)) {
            $this->error('Konnte ' . $target . ' nicht schreiben.');
            return self::FAILURE;
        }

        $this->info('Geschrieben: ' . $target);

        return self::SUCCESS;
    }

    /**
     * Basis-URL aus der phpBB-Konfiguration, damit ein Domainwechsel nicht
     * hier nachgepflegt werden muss.
     */
    protected function baseUrl(): string
    {
        if ($override = env('SITEMAP_BASE_URL')) return rtrim($override, '/');

        $config = $this->forumConfig();

        $protocol = $config['server_protocol'] ?? 'https://';
        $host = $config['server_name'] ?? 'www.pokerth.net';
        $path = rtrim($config['script_path'] ?? '/', '/');

        return rtrim($protocol . $host . $path, '/');
    }

    /** Die paar Konfigwerte, die hier gebraucht werden – ein Query statt drei. */
    protected function forumConfig(): Collection
    {
        return $this->forumConfig ??= DB::table($this->forumDb . '.phpbb_config')
            ->whereIn('config_name', ['server_protocol', 'server_name', 'script_path', 'enable_mod_rewrite'])
            ->pluck('config_value', 'config_name');
    }

    protected function defaultTarget(): string
    {
        return env('SITEMAP_PATH') ?: dirname(base_path()) . '/sitemap.xml';
    }

    /**
     * Foren, in denen die Gruppe GUESTS f_read hat – entweder direkt gesetzt
     * oder über eine Rolle (auth_role_id), wie es phpBB im ACP anlegt.
     */
    protected function guestReadableForumIds(): array
    {
        $db = $this->forumDb;

        return DB::table($db . '.phpbb_acl_groups as a')
            ->leftJoin($db . '.phpbb_acl_roles_data as rd', 'rd.role_id', '=', 'a.auth_role_id')
            ->join($db . '.phpbb_acl_options as o', 'o.auth_option_id', '=', DB::raw('COALESCE(NULLIF(a.auth_option_id, 0), rd.auth_option_id)'))
            ->where('a.group_id', self::GUEST_GROUP)
            ->where('o.auth_option', 'f_read')
            ->where(DB::raw('COALESCE(NULLIF(a.auth_setting, 0), rd.auth_setting)'), 1)
            ->where('a.forum_id', '>', 0)
            ->distinct()
            ->pluck('a.forum_id')
            ->all();
    }

    protected function boardUrls(string $base, array $forumIds): array
    {
        $lastPost = DB::table($this->forumDb . '.phpbb_forums')
            ->whereIn('forum_id', $forumIds)
            ->max('forum_last_post_time');

        return [[
            'loc' => $base . '/',
            'lastmod' => $this->timestamp($lastPost),
            'changefreq' => 'daily',
            'priority' => '1.0',
        ]];
    }

    /** Statische Seiten der phpbb/pages-Erweiterung (Download, Leaderboard, ...). */
    protected function pageUrls(string $base): array
    {
        $prefix = $base . $this->routePrefix();

        return DB::table($this->forumDb . '.phpbb_pages')
            ->where('page_display_to_guests', 1)
            ->whereNotIn('page_route', $this->skipPages)
            ->orderBy('page_id')
            ->pluck('page_route')
            ->map(fn($route) => [
                'loc' => $prefix . $route,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ])
            ->all();
    }

    /**
     * Präfix für Controller-Routen. phpBB hängt ohne aktiviertes URL-Rewriting
     * ein /app.php/ davor und verlinkt intern auch genau so – die Sitemap muss
     * dieselbe Form nennen, sonst stehen dort URLs, auf die nichts zeigt.
     */
    protected function routePrefix(): string
    {
        return ($this->forumConfig()['enable_mod_rewrite'] ?? '0') === '1' ? '/' : '/app.php/';
    }

    protected function forumUrls(string $base, array $forumIds): array
    {
        return DB::table($this->forumDb . '.phpbb_forums')
            ->whereIn('forum_id', $forumIds)
            ->where('forum_type', 1)               // 1 = FORUM_POST, Kategorien und Links raus
            ->where('forum_topics_approved', '>', 0)
            ->orderBy('left_id')
            ->get(['forum_id', 'forum_last_post_time'])
            ->map(fn($f) => [
                'loc' => $base . '/viewforum.php?f=' . $f->forum_id,
                'lastmod' => $this->timestamp($f->forum_last_post_time),
                'changefreq' => 'daily',
                'priority' => '0.7',
            ])
            ->all();
    }

    protected function topicUrls(string $base, array $forumIds): array
    {
        $urls = [];

        DB::table($this->forumDb . '.phpbb_topics')
            ->whereIn('forum_id', $forumIds)
            ->where('topic_visibility', 1)          // 1 = ITEM_APPROVED
            // Frisches zuerst: Crawler arbeiten große Sitemaps von oben ab, und
            // beim Kürzen auf MAX_URLS fallen so die ältesten Themen weg.
            // topic_id als Tiebreak, sonst ist die Reihenfolge über die Chunks
            // hinweg nicht stabil und es fehlen einzelne Themen.
            ->orderByDesc('topic_last_post_time')
            ->orderByDesc('topic_id')
            ->select(['topic_id', 'topic_last_post_time'])
            ->chunk(1000, function ($topics) use (&$urls, $base) {
                foreach ($topics as $t) {
                    $urls[] = [
                        'loc' => $base . '/viewtopic.php?t=' . $t->topic_id,
                        'lastmod' => $this->timestamp($t->topic_last_post_time),
                        'changefreq' => $this->changefreq($t->topic_last_post_time),
                        'priority' => '0.5',
                    ];
                }
            });

        return $urls;
    }

    /** phpBB speichert Unix-Timestamps in UTC. */
    protected function timestamp(?int $ts): ?string
    {
        return $ts ? Carbon::createFromTimestampUTC($ts)->toAtomString() : null;
    }

    /** Alte Themen ändern sich praktisch nie mehr – das spart Crawl-Budget. */
    protected function changefreq(?int $ts): string
    {
        if (!$ts) return 'yearly';

        $days = (time() - $ts) / 86400;

        if ($days < 30) return 'daily';
        if ($days < 365) return 'monthly';

        return 'yearly';
    }

    protected function render(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
            if (!empty($url['lastmod'])) $xml .= '    <lastmod>' . $url['lastmod'] . '</lastmod>' . "\n";
            if (!empty($url['changefreq'])) $xml .= '    <changefreq>' . $url['changefreq'] . '</changefreq>' . "\n";
            if (!empty($url['priority'])) $xml .= '    <priority>' . $url['priority'] . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    /**
     * Erst in eine temporäre Datei daneben, dann umbenennen: so liefert der
     * Webserver nie eine halb geschriebene Sitemap aus.
     */
    protected function write(string $target, string $xml): bool
    {
        $tmp = $target . '.tmp';

        if (file_put_contents($tmp, $xml) === false) return false;

        @chmod($tmp, 0644);

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}
