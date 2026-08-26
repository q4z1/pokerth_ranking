<?php

namespace App\Services;

use App\Console\Commands\AttackCheck;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Webserver-Traffic und Cloudflare-"Under Attack"-Filterhistorie aus den
 * Zustandsdateien von App\Console\Commands\AttackCheck: visitor_log.txt
 * (5-Minuten-Trefferzahlen je Lauf, inkl. eindeutiger IPs und Hits je Vhost)
 * und attack_events.log (Filter-Schaltungen mit Grund), siehe dort
 * logEvent(). Reine Aggregate, keine rohen IPs - genau wie ServerLogAnalyzer
 * ist dieser Endpunkt bewusst öffentlich (siehe AdminController).
 *
 * attack_events.log existiert erst seit seiner Einführung - für die Zeit
 * davor werden hit_spike/sustained_rate-Episoden aus visitor_log.txt
 * rekonstruiert (derivedEpisodes()), da dessen Rohzahlen schon länger
 * mitgeschrieben werden. unique_ip_burst-Angriffe lassen sich davor NICHT
 * rekonstruieren: unique_ips.txt hielt vor derselben Einführung nur den
 * jeweils letzten Wert, keine Historie - diese Angriffe sind für die Zeit
 * davor unwiederbringlich, siehe events_log_started im Ergebnis.
 */
class WebServerLogAnalyzer
{
    private const LOG_FILE = 'visitor_log.txt';
    private const EVENTS_FILE = 'attack_events.log';
    private const ENABLED_SINCE_FILE = 'filter_enabled_since.txt';
    private const WINDOW_FILE = 'filter_window_until.txt';

    /** Lücke zwischen zwei auslösenden Samples, die noch als eine durchgehende Episode gilt. */
    private const EPISODE_GAP_SECONDS = 900;

    public function analyze(int $hours): array
    {
        $since = Carbon::now('UTC')->subHours($hours);

        $series = $this->readSeries($since);
        $loggedEvents = $this->readEvents($since);
        $derived = $this->derivedEpisodes($series, $loggedEvents);

        $events = array_merge($loggedEvents, $derived);
        usort($events, fn ($a, $b) => strcmp($b['ts'], $a['ts']));
        $events = array_map(fn ($e) => $e + ['classification' => $this->classify(
            $e['reason_type'] ?? null,
            $e['total'] ?? null,
            $e['unique_ips'] ?? null
        )], $events);

        $vhosts = $this->vhostTotals($series);

        $peakHits = null;
        $peakUnique = null;
        foreach ($series as $point) {
            if ($peakHits === null || $point['total'] > $peakHits) {
                $peakHits = $point['total'];
            }
            if ($point['unique_ips'] !== null && ($peakUnique === null || $point['unique_ips'] > $peakUnique)) {
                $peakUnique = $point['unique_ips'];
            }
        }

        $triggerEvents = array_filter($events, fn ($e) => $e['action'] === 'detected'
            || ($e['action'] === 'enable' && $e['trigger'] === 'auto'));

        return [
            'success' => true,
            'window' => [
                'hours' => $hours,
                'from' => $since->toDateTimeString(),
                'to' => Carbon::now('UTC')->toDateTimeString(),
            ],
            'current' => $this->currentStatus(),
            'series' => $series,
            'has_vhost_data' => collect($series)->contains(fn ($p) => $p['hits'] !== null),
            'vhosts' => $vhosts,
            'events' => array_values($events),
            'events_log_started' => $this->eventsLogStartedAt(),
            'stats' => [
                'triggers' => count($triggerEvents),
                'botnet_triggers' => count(array_filter($triggerEvents, fn ($e) => $e['classification']['key'] === 'botnet')),
                'scanner_triggers' => count(array_filter($triggerEvents, fn ($e) => $e['classification']['key'] === 'scanner')),
                // Only sums confirmed enable/disable pairs (duration_minutes is
                // null on 'detected' rows, see derivedEpisodes()) - this is a
                // real measured total, not an estimate padded out with guesses.
                'minutes_filtered' => round((float) collect($events)->sum('duration_minutes'), 1),
                'busiest_vhost' => $vhosts[0]['name'] ?? null,
                'peak_hits' => $peakHits,
                'peak_unique_ips' => $peakUnique,
            ],
        ];
    }

    /**
     * Rohe 5-Minuten-Punkte seit $since, per awk gefiltert wie schon in
     * AttackCheck::updateGraph()/countHits() - die Datei ist über ein Jahr
     * Historie gewachsen und wird nicht rotiert, komplett einlesen lohnt
     * sich nicht. Feld 4 (eindeutige IPs) und 5 (Hits je Vhost als JSON)
     * fehlen bei älteren Zeilen; das wird unten toleriert.
     */
    private function readSeries(Carbon $since): array
    {
        $path = storage_path('app/' . self::LOG_FILE);
        if (!is_readable($path)) {
            return [];
        }

        $sinceStr = $since->toDateTimeString();
        $command = 'awk -F "|" \'$1 > "' . $sinceStr . '"\' ' . escapeshellarg($path);
        $output = trim((string) shell_exec($command));

        if ($output === '') {
            return [];
        }

        $series = [];
        foreach (explode("\n", $output) as $line) {
            // limit 5: the JSON hits field is the only part allowed to contain
            // more structure, vhost names never contain "|".
            $parts = explode('|', $line, 5);
            if (count($parts) < 3) {
                continue;
            }

            $hits = null;
            if (isset($parts[4]) && $parts[4] !== '') {
                $decoded = json_decode($parts[4], true);
                if (is_array($decoded)) {
                    $hits = $decoded;
                }
            }

            $series[] = [
                'ts' => $parts[0],
                'total' => (int) $parts[1],
                'diff' => (int) $parts[2],
                'unique_ips' => isset($parts[3]) && $parts[3] !== '' ? (int) $parts[3] : null,
                'hits' => $hits,
            ];
        }

        return $series;
    }

    /** Summierte Hits je Vhost über alle Punkte, die eine Aufschlüsselung tragen. */
    private function vhostTotals(array $series): array
    {
        $totals = [];
        foreach ($series as $point) {
            if (!is_array($point['hits'])) {
                continue;
            }
            foreach ($point['hits'] as $name => $count) {
                $totals[$name] = ($totals[$name] ?? 0) + (int) $count;
            }
        }

        // Drops to 0 and stays there once a vhost is removed from AttackCheck's
        // $access_logs (e.g. a site being decommissioned) - older rows in the
        // window still carry the key from before the removal, so without this
        // it would linger in the list, all-zero, until it ages out of the window.
        $totals = array_filter($totals, fn ($hits) => $hits > 0);

        arsort($totals);
        $sum = array_sum($totals);

        $out = [];
        foreach ($totals as $name => $hits) {
            $out[] = ['name' => $name, 'hits' => $hits, 'share' => $sum ? round($hits / $sum, 3) : 0.0];
        }

        return $out;
    }

    /**
     * Filter-Schaltungen seit $since, neueste zuerst. duration_minutes wird
     * beim disable-Eintrag nachgetragen, sobald in der Chronologie das
     * passende vorangehende enable gefunden wurde - eine noch laufende
     * Sperre (kein disable danach) bleibt ohne Dauer, siehe currentStatus().
     * Jeder Eintrag bekommt source=logged, zur Abgrenzung von den unten
     * rekonstruierten Episoden.
     */
    private function readEvents(Carbon $since): array
    {
        if (!Storage::disk('local')->exists(self::EVENTS_FILE)) {
            return [];
        }

        $raw = trim(Storage::disk('local')->get(self::EVENTS_FILE));
        if ($raw === '') {
            return [];
        }

        $events = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['ts'], $entry['action'])) {
                $entry['source'] = 'logged';
                $events[] = $entry;
            }
        }

        usort($events, fn ($a, $b) => strcmp($a['ts'], $b['ts']));

        $pendingSince = null;
        foreach ($events as &$event) {
            if ($event['action'] === 'enable') {
                $pendingSince = $event['ts'];
            } elseif ($event['action'] === 'disable' && $pendingSince !== null) {
                $event['duration_minutes'] = round((strtotime($event['ts']) - strtotime($pendingSince)) / 60, 1);
                $pendingSince = null;
            }
        }
        unset($event);

        $sinceStr = $since->toDateTimeString();
        $events = array_values(array_filter($events, fn ($e) => $e['ts'] >= $sinceStr));

        usort($events, fn ($a, $b) => strcmp($b['ts'], $a['ts']));

        return array_slice($events, 0, 200);
    }

    /**
     * Reconstructs hit_spike/sustained_rate episodes from visitor_log.txt by
     * replaying AttackCheck::detectReason() over consecutive samples - the
     * same check the live command runs, just after the fact. Samples up to
     * self::EPISODE_GAP_SECONDS apart are folded into one episode, since a
     * real ongoing attack keeps re-triggering the check every run even
     * though logEvent() itself only fires once per activation. Episodes that
     * overlap a real logged 'enable' are dropped - the confirmed record wins.
     *
     * unique_ip_burst can only be found this way for samples where both this
     * point's and the previous point's unique_ips are known, i.e. only after
     * the fields were added to visitor_log.txt - see the class docblock.
     */
    private function derivedEpisodes(array $series, array $loggedEvents): array
    {
        if (count($series) < 2) {
            return [];
        }

        $checker = new AttackCheck();

        $triggers = [];
        for ($i = 1; $i < count($series); $i++) {
            $point = $series[$i];
            $prev = $series[$i - 1];
            [$reason, $reasonType] = $checker->detectReason(
                $point['total'],
                $point['diff'],
                $prev['total'],
                $point['unique_ips'],
                $prev['unique_ips']
            );
            if ($reason !== null) {
                $triggers[] = $point + ['reason' => $reason, 'reason_type' => $reasonType];
            }
        }

        if (empty($triggers)) {
            return [];
        }

        $episodes = [];
        $group = [array_shift($triggers)];
        foreach ($triggers as $t) {
            $gap = strtotime($t['ts']) - strtotime(end($group)['ts']);
            if ($gap <= self::EPISODE_GAP_SECONDS) {
                $group[] = $t;
            } else {
                $episodes[] = $group;
                $group = [$t];
            }
        }
        $episodes[] = $group;

        $loggedEnableTimes = array_map(
            fn ($e) => strtotime($e['ts']),
            array_values(array_filter($loggedEvents, fn ($e) => $e['action'] === 'enable'))
        );

        $out = [];
        foreach ($episodes as $group) {
            $startTs = strtotime($group[0]['ts']);
            $endTs = strtotime(end($group)['ts']);

            $covered = false;
            foreach ($loggedEnableTimes as $lt) {
                if ($lt >= $startTs - self::EPISODE_GAP_SECONDS && $lt <= $endTs + self::EPISODE_GAP_SECONDS) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }

            $peak = $group[0];
            foreach ($group as $t) {
                if ($t['total'] > $peak['total']) {
                    $peak = $t;
                }
            }

            $out[] = [
                'ts' => $group[0]['ts'],
                'action' => 'detected',
                'trigger' => 'auto',
                'source' => 'derived',
                'reason' => $peak['reason'] . ' (reconstructed from traffic - attack_events.log did not cover this period yet)',
                'reason_type' => $peak['reason_type'],
                'total' => $peak['total'],
                'unique_ips' => $peak['unique_ips'],
                'hits' => $peak['hits'],
                // No duration_minutes here on purpose: that field means "confirmed
                // filter-on time" elsewhere (measured from a real enable/disable
                // pair), and we have no such confirmation for this period - only
                // Cloudflare's own rule history would have it, which isn't queried
                // here. trigger_span_minutes is the one thing actually measured:
                // how long the raw traffic itself sat over the threshold. It is
                // very likely shorter than how long the filter stayed on, since
                // AttackCheck keeps it on for a fixed $enabled_limit once
                // triggered regardless of how fast traffic drops back down - but
                // stating that fixed duration here would be a guess dressed up as
                // data, so it is deliberately left out.
                'duration_minutes' => null,
                'trigger_span_minutes' => round(max($endTs - $startTs, 300) / 60, 1),
            ];
        }

        return $out;
    }

    /**
     * Grobe Einordnung Botnet vs. einzelner Scanner/Crawler anhand des
     * Verhältnisses Hits/eindeutige IP im auslösenden Sample:
     * unique_ip_burst ist per Definition schon ein Botnet-Fingerabdruck
     * (siehe AttackCheck-Kommentar zum residential-proxy-Botnet). Sonst: viele
     * Hits von wenigen IPs (hoher Quotient) ist ein einzelner, stumpf
     * hämmernder Scanner; viele Hits verteilt auf fast ebenso viele IPs
     * (Quotient nahe 1) ist eher ein verteiltes Botnet. Schwellenwerte sind
     * eine Faustregel, keine kalibrierte Messung - anders als bei
     * AttackCheck's eigenen Limits gibt es dafür (noch) keine Datenbasis.
     */
    private function classify(?string $reasonType, ?int $total, ?int $uniqueIps): array
    {
        if ($reasonType === 'unique_ip_burst') {
            return ['key' => 'botnet', 'label' => 'Botnet (many distinct sources)'];
        }
        if ($uniqueIps === null || $uniqueIps <= 0 || $total === null) {
            return ['key' => 'unknown', 'label' => 'Unclassified (no source-IP data)'];
        }

        $ratio = $total / $uniqueIps;
        if ($ratio <= 3) {
            return ['key' => 'botnet', 'label' => 'Botnet-like (many distinct sources)'];
        }
        if ($ratio >= 12) {
            return ['key' => 'scanner', 'label' => 'Single scanner/crawler (few sources, many hits)'];
        }

        return ['key' => 'mixed', 'label' => 'Mixed traffic pattern'];
    }

    /** Zeitpunkt der ersten Zeile in attack_events.log, oder null wenn die Datei (noch) leer ist. */
    private function eventsLogStartedAt(): ?string
    {
        if (!Storage::disk('local')->exists(self::EVENTS_FILE)) {
            return null;
        }

        $raw = trim(Storage::disk('local')->get(self::EVENTS_FILE));
        if ($raw === '') {
            return null;
        }

        $first = json_decode(strtok($raw, "\n"), true);

        return $first['ts'] ?? null;
    }

    private function currentStatus(): array
    {
        $enabled = Storage::disk('local')->exists(self::ENABLED_SINCE_FILE);
        $since = null;
        $minutes = null;

        if ($enabled) {
            $ts = (int) trim(Storage::disk('local')->get(self::ENABLED_SINCE_FILE));
            if ($ts > 0) {
                $since = date('Y-m-d H:i:s', $ts);
                $minutes = round((time() - $ts) / 60, 1);
            }
        }

        $graceUntil = null;
        if (Storage::disk('local')->exists(self::WINDOW_FILE)) {
            $until = (int) trim(Storage::disk('local')->get(self::WINDOW_FILE));
            if ($until > time()) {
                $graceUntil = date('Y-m-d H:i:s', $until);
            }
        }

        return [
            'filter_enabled' => $enabled,
            'enabled_since' => $since,
            'enabled_minutes' => $minutes,
            'grace_until' => $graceUntil,
        ];
    }
}
