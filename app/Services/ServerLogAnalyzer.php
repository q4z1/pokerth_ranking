<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lobby-Aktivität aus server_run / server_session auswerten.
 *
 * Zieht denselben Bericht wie tools/analyze_server_log.py im PokerTH-Repo
 * (Wachstum, Rückkehrrate, Tagesgang, ...), aber live aus der DB statt aus
 * server_messages.log geparst. Ein paar Kennzahlen sind bewusst einfacher:
 * Neuregistrierungen kommen direkt aus player.created (das Skript musste sie
 * noch aus dem Zuwachs der höchsten dbId schätzen), und der Lücken-/
 * Binomialtest-Teil ist auf die längste Lücke reduziert.
 */
class ServerLogAnalyzer
{
    /** Breite des gesuchten Ruhefensters in Stunden, wie im Python-Skript. */
    private const NIGHT_HOURS = 4;

    /** Deckel des "aktive Tage"-Histogramms; der letzte Bucket ist ein Überlauf-Bucket. */
    private const HIST_MAX = 8;

    public function analyze(int $days): array
    {
        $end = Carbon::yesterday()->endOfDay();
        $requestedStart = Carbon::yesterday()->subDays($days - 1)->startOfDay();

        // Erster jemals geloggte Session bestimmt den frühestmöglichen Start:
        // ohne diese Deckelung würde ein frisch aktiviertes Logging mit
        // Nullen für alle Tage vor dem eigentlichen Start aufgefüllt - das
        // sähe wie ein Absturz aus, wäre aber nur "Server gab es noch nicht".
        $earliest = DB::table('server_session')->min('connected_at');
        if ($earliest === null) {
            return [
                'success' => true,
                'enough_data' => false,
                'window' => ['from' => $requestedStart->toDateString(), 'to' => $end->toDateString(), 'days' => $days],
            ];
        }

        // Unabhängig vom Ganztages-Fenster unten: die letzten 48h stundenweise,
        // damit frisch aktiviertes Logging sofort sichtbar ist und nicht erst
        // nach dem ersten vollen Tag - siehe recentHourly().
        $recentHourly = $this->recentHourly();

        $start = $requestedStart->max(Carbon::parse($earliest)->startOfDay());

        if ($start->gt($end)) {
            // Es wird bereits geloggt, aber noch kein einziger voller Tag ist
            // vorbei (typischerweise: Logging wurde heute erst aktiv).
            $sessionsSoFar = DB::table('server_session')->where('connected_at', '>=', $earliest)->count();
            return [
                'success' => true,
                'enough_data' => false,
                'logging_since' => $earliest,
                'sessions_so_far' => $sessionsSoFar,
                'recent_hourly' => $recentHourly,
                'window' => ['from' => $requestedStart->toDateString(), 'to' => $end->toDateString(), 'days' => $days],
            ];
        }

        $totalLogins = DB::table('server_session')
            ->whereBetween('connected_at', [$start, $end])
            ->count();

        $dayList = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dayList[] = $d->toDateString();
        }

        $active = $this->activePerDay($start, $end, $dayList);
        $newReg = $this->newPerDay($start, $end, $dayList);
        $weeks = $this->weekBlocks($dayList, $newReg);

        $trend = $this->linearTrend(array_values($active));

        $newcomers = $this->newcomers($start, $end);
        $retention = $this->retention($newcomers, $end);
        $histogram = $this->histogram($newcomers, $end);

        $established = $this->establishedBase($start, $end);

        $hourly = $this->hourly($start, $end, count($dayList), $newcomers);

        $gap = $this->longestGap($start, $end, $hourly['night']);

        $newTotal = array_sum($newReg);
        $newSeen = count(array_filter($newcomers, fn ($n) => count($n['active_days']) > 0));

        return [
            'success' => true,
            'enough_data' => true,
            // Unter 8 vollen Tagen sind Trend/Wochenvergleich/Rückkehrrate
            // statistisch noch wackelig (siehe Python-Original, das unter 8
            // Tagen ganz abbricht) - wir zeigen es trotzdem, markieren es aber.
            'partial' => count($dayList) < 8,
            'recent_hourly' => $recentHourly,
            'window' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => count($dayList)],
            'total_logins' => $totalLogins,
            'active_per_day' => $this->pairs($dayList, $active),
            'active_mean' => round($this->mean(array_values($active)), 1),
            'active_sd' => round($this->stdDev(array_values($active)), 1),
            'trend' => $trend,
            'new_per_day' => $this->pairs($dayList, $newReg),
            'new_total' => $newTotal,
            'new_seen' => $newSeen,
            'weeks' => $weeks,
            'new_ratio' => ($weeks[0]['mean'] ?? 0) > 0
                ? round((end($weeks)['mean'] ?? 0) / $weeks[0]['mean'], 2)
                : 0,
            'retention' => $retention,
            'histogram' => $histogram,
            'established' => $established,
            'hourly' => $hourly,
            'longest_gap' => $gap,
            'client_types' => $this->clientTypeBreakdown($start, $end),
        ];
    }

    /**
     * Sessions/Spieler je client_type im Fenster. NULL steht für Sessions
     * ohne client_build_id - z. B. Backfill aus server_messages.log, das die
     * Build-ID nicht mitschreibt. client_type ist eine generierte Spalte
     * (client_build_id >> 24): 1 = Qt-Widget, 2 = QML, 3 = Web (Spectator-Tool
     * seit 2.1.8; narmods Webclient folgt, sobald er USE_CLIENT_TYPE_WEB setzt).
     */
    private function clientTypeBreakdown(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('server_session')
            ->selectRaw('client_type, COUNT(*) as sessions, COUNT(DISTINCT player_id) as players')
            ->whereBetween('connected_at', [$start, $end])
            ->groupBy('client_type')
            ->orderByDesc('sessions')
            ->get();

        return $rows->map(fn ($r) => [
            'type' => $r->client_type === null ? null : (int) $r->client_type,
            'sessions' => (int) $r->sessions,
            'players' => (int) $r->players,
        ])->all();
    }

    /**
     * Sessions je Stunde der letzten 48h, unabhängig vom Ganztages-Fenster
     * oben - damit frisch aktiviertes Logging sofort eine Reaktion zeigt,
     * statt erst nach dem ersten vollen Tag.
     */
    private function recentHourly(int $hours = 48): array
    {
        $end = Carbon::now()->minute(0)->second(0);
        $start = $end->copy()->subHours($hours - 1);

        $rows = DB::table('server_session')
            ->selectRaw("DATE_FORMAT(connected_at, '%Y-%m-%d %H:00:00') as bucket, COUNT(*) as c")
            ->where('connected_at', '>=', $start)
            ->groupBy('bucket')
            ->pluck('c', 'bucket');

        $out = [];
        for ($h = $start->copy(); $h->lte($end); $h->addHour()) {
            $key = $h->format('Y-m-d H:00:00');
            $out[] = ['hour' => $key, 'count' => (int) ($rows[$key] ?? 0)];
        }
        return $out;
    }

    private function activePerDay(Carbon $start, Carbon $end, array $dayList): array
    {
        $rows = DB::table('server_session')
            ->selectRaw('DATE(connected_at) as d, COUNT(DISTINCT player_id) as c')
            ->whereBetween('connected_at', [$start, $end])
            ->where('is_guest', 0)
            ->whereNotNull('player_id')
            ->groupBy('d')
            ->pluck('c', 'd');

        return $this->fillDays($dayList, $rows);
    }

    private function newPerDay(Carbon $start, Carbon $end, array $dayList): array
    {
        $rows = DB::table('player')
            ->selectRaw('DATE(created) as d, COUNT(*) as c')
            ->whereBetween('created', [$start, $end])
            ->groupBy('d')
            ->pluck('c', 'd');

        return $this->fillDays($dayList, $rows);
    }

    private function fillDays(array $dayList, $rows): array
    {
        $out = [];
        foreach ($dayList as $day) {
            $out[$day] = (int) ($rows[$day] ?? 0);
        }
        return $out;
    }

    private function pairs(array $dayList, array $values): array
    {
        $out = [];
        foreach ($dayList as $day) {
            $out[] = ['date' => $day, 'count' => $values[$day]];
        }
        return $out;
    }

    /** Tage in <=7-Tage-Blöcke aufteilen, kurzer Rest wird an den letzten Block gehängt. */
    private function weekBlocks(array $dayList, array $newReg): array
    {
        $n = count($dayList);
        $blocks = [];
        $i = 0;
        while ($i < $n) {
            $j = min($i + 6, $n - 1);
            if ($n - $j <= 3 && $blocks) {
                $j = $n - 1;
            }
            $slice = array_slice($newReg, $i, $j - $i + 1);
            $blocks[] = [
                'from' => $dayList[$i],
                'to' => $dayList[$j],
                'mean' => round($this->mean($slice), 1),
            ];
            $i = $j + 1;
        }
        return $blocks;
    }

    private function mean(array $values): float
    {
        return count($values) ? array_sum($values) / count($values) : 0.0;
    }

    private function stdDev(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $m = $this->mean($values);
        $variance = array_sum(array_map(fn ($v) => ($v - $m) ** 2, $values)) / $n;
        return sqrt($variance);
    }

    /** Lineare Regression über den Index (0..n-1); liefert Steigung/Tag, /Woche und r. */
    private function linearTrend(array $values): array
    {
        $n = count($values);
        if ($n < 2) {
            return ['slope_per_day' => 0.0, 'slope_per_week' => 0.0, 'r' => 0.0];
        }
        $xs = range(0, $n - 1);
        $mx = $this->mean($xs);
        $my = $this->mean($values);
        $num = 0.0;
        $denX = 0.0;
        foreach ($xs as $i => $x) {
            $num += ($x - $mx) * ($values[$i] - $my);
            $denX += ($x - $mx) ** 2;
        }
        $slope = $denX ? $num / $denX : 0.0;
        $sdY = $this->stdDev($values);
        $sdX = $this->stdDev($xs);
        $r = $sdY ? ($slope * $sdX / $sdY) : 0.0;
        return [
            'slope_per_day' => round($slope, 2),
            'slope_per_week' => round($slope * 7, 1),
            'r' => round($r, 2),
        ];
    }

    /**
     * Im Fenster registrierte Accounts mit ihren Session-Tagen/Erst-Login-Stunde.
     *
     * @return array<int, array{player_id:int, created:Carbon, active_days:array<string>, first_hour:?int}>
     */
    private function newcomers(Carbon $start, Carbon $end): array
    {
        $players = DB::table('player')
            ->select('player_id', 'created')
            ->whereBetween('created', [$start, $end])
            ->get();

        if ($players->isEmpty()) {
            return [];
        }

        $ids = $players->pluck('player_id')->all();
        $sessions = DB::table('server_session')
            ->select('player_id', 'connected_at')
            ->whereIn('player_id', $ids)
            ->where('connected_at', '<=', $end)
            ->orderBy('connected_at')
            ->get()
            ->groupBy('player_id');

        $out = [];
        foreach ($players as $p) {
            $rows = $sessions->get($p->player_id, collect());
            $activeDays = $rows->map(fn ($r) => substr($r->connected_at, 0, 10))->unique()->values()->all();
            $out[] = [
                'player_id' => $p->player_id,
                'created' => Carbon::parse($p->created),
                'active_days' => $activeDays,
                'first_hour' => $rows->isEmpty() ? null : (int) Carbon::parse($rows->first()->connected_at)->format('G'),
            ];
        }
        return $out;
    }

    private function retention(array $newcomers, Carbon $end): array
    {
        $out = [];
        foreach ([1, 3, 7] as $window) {
            $back = 0;
            $total = 0;
            foreach ($newcomers as $n) {
                $arrivalDate = $n['created']->copy()->startOfDay();
                if ($arrivalDate->copy()->addDays($window)->gt($end)) {
                    continue; // (noch) nicht lange genug beobachtet, um zurückkommen zu können
                }
                $total++;
                foreach ($n['active_days'] as $d) {
                    $delta = $arrivalDate->diffInDays(Carbon::parse($d), false);
                    if ($delta > 0 && $delta <= $window) {
                        $back++;
                        break;
                    }
                }
            }
            $out[$window] = ['back' => $back, 'total' => $total];
        }
        return $out;
    }

    private function histogram(array $newcomers, Carbon $end): array
    {
        $mature = array_filter($newcomers, fn ($n) => $n['created']->copy()->startOfDay()->addDays(7)->lte($end));
        $buckets = array_fill(1, self::HIST_MAX, 0);
        foreach ($mature as $n) {
            $count = min(count($n['active_days']), self::HIST_MAX);
            $count = max($count, 1); // wer registriert wurde, zählt als mindestens 1 aktiver Tag
            $buckets[$count]++;
        }
        $total = count($mature);
        $newcomerDays = $total ? $this->mean(array_map(fn ($n) => max(count($n['active_days']), 1), $mature)) : 0.0;
        return [
            'buckets' => collect($buckets)->map(fn ($c, $d) => ['days' => $d, 'label' => $d === self::HIST_MAX ? $d . '+' : (string) $d, 'count' => $c])->values()->all(),
            'total' => $total,
            'one_day_share' => $total ? round($buckets[1] / $total, 2) : 0.0,
            'newcomer_avg_days' => round($newcomerDays, 1),
        ];
    }

    private function establishedBase(Carbon $start, Carbon $end): array
    {
        $firstWeekEnd = $start->copy()->addDays(6);
        $lastWeekStart = $end->copy()->subDays(6)->startOfDay();

        $was = DB::table('server_session')
            ->join('player', 'player.player_id', '=', 'server_session.player_id')
            ->where('player.created', '<', $start)
            ->where('server_session.is_guest', 0)
            ->whereBetween('server_session.connected_at', [$start, $firstWeekEnd->endOfDay()])
            ->distinct('server_session.player_id')
            ->count('server_session.player_id');

        $now = DB::table('server_session')
            ->join('player', 'player.player_id', '=', 'server_session.player_id')
            ->where('player.created', '<', $start)
            ->where('server_session.is_guest', 0)
            ->whereBetween('server_session.connected_at', [$lastWeekStart, $end])
            ->distinct('server_session.player_id')
            ->count('server_session.player_id');

        $wasIds = DB::table('server_session')
            ->join('player', 'player.player_id', '=', 'server_session.player_id')
            ->where('player.created', '<', $start)
            ->where('server_session.is_guest', 0)
            ->whereBetween('server_session.connected_at', [$start, $firstWeekEnd->endOfDay()])
            ->distinct()
            ->pluck('server_session.player_id');

        $nowIds = DB::table('server_session')
            ->join('player', 'player.player_id', '=', 'server_session.player_id')
            ->where('player.created', '<', $start)
            ->where('server_session.is_guest', 0)
            ->whereBetween('server_session.connected_at', [$lastWeekStart, $end])
            ->distinct()
            ->pluck('server_session.player_id');

        $activeDayCounts = DB::table('server_session')
            ->join('player', 'player.player_id', '=', 'server_session.player_id')
            ->where('player.created', '<', $start)
            ->where('server_session.is_guest', 0)
            ->whereBetween('server_session.connected_at', [$start, $end])
            ->selectRaw('server_session.player_id, COUNT(DISTINCT DATE(server_session.connected_at)) as d')
            ->groupBy('server_session.player_id')
            ->pluck('d');

        return [
            'before' => $was,
            'after' => $now,
            'gone' => $wasIds->diff($nowIds)->count(),
            'back' => $nowIds->diff($wasIds)->count(),
            'avg_active_days' => round($this->mean($activeDayCounts->all()), 1),
        ];
    }

    private function hourly(Carbon $start, Carbon $end, int $days, array $newcomers): array
    {
        $perHourRaw = array_fill(0, 24, 0);
        $rows = DB::table('server_session')
            ->selectRaw('HOUR(connected_at) as h, COUNT(*) as c')
            ->whereBetween('connected_at', [$start, $end])
            ->groupBy('h')
            ->pluck('c', 'h');
        foreach ($rows as $h => $c) {
            $perHourRaw[(int) $h] = (int) $c;
        }

        $arrivals = array_fill(0, 24, 0);
        foreach ($newcomers as $n) {
            if ($n['first_hour'] !== null) {
                $arrivals[$n['first_hour']]++;
            }
        }

        $totalLogins = array_sum($perHourRaw);
        $totalNew = array_sum($arrivals);

        $loginsPerHour = array_map(fn ($c) => round($c / max($days, 1), 2), $perHourRaw);
        $hourIndex = [];
        for ($h = 0; $h < 24; $h++) {
            $hourIndex[$h] = ($perHourRaw[$h] && $totalNew)
                ? round(($arrivals[$h] / $totalNew) / ($perHourRaw[$h] / $totalLogins), 2)
                : 0.0;
        }

        $best = null;
        $bestSum = null;
        for ($h = 0; $h <= 24 - self::NIGHT_HOURS; $h++) {
            $sum = array_sum(array_slice($perHourRaw, $h, self::NIGHT_HOURS));
            if ($bestSum === null || $sum < $bestSum) {
                $bestSum = $sum;
                $best = $h;
            }
        }
        $night = [$best, $best + self::NIGHT_HOURS];

        $nightLogins = array_sum(array_slice($perHourRaw, $night[0], self::NIGHT_HOURS));
        $nightArrivals = array_sum(array_slice($arrivals, $night[0], self::NIGHT_HOURS));
        $nightLoginShare = $totalLogins ? $nightLogins / $totalLogins : 0.0;
        $nightNewShare = $totalNew ? $nightArrivals / $totalNew : 0.0;

        return [
            'logins_per_hour' => $loginsPerHour,
            'new_index_per_hour' => $hourIndex,
            'night' => $night,
            'night_login_share' => round($nightLoginShare, 3),
            'night_new_share' => round($nightNewShare, 3),
            'night_index' => $nightLoginShare ? round($nightNewShare / $nightLoginShare, 2) : 0.0,
        ];
    }

    private function longestGap(Carbon $start, Carbon $end, array $night): ?array
    {
        $rows = DB::select(
            'SELECT connected_at,
                    TIMESTAMPDIFF(SECOND, LAG(connected_at) OVER (ORDER BY connected_at), connected_at) AS gap_s
             FROM server_session
             WHERE connected_at BETWEEN ? AND ?
             ORDER BY gap_s DESC
             LIMIT 1',
            [$start, $end]
        );

        if (empty($rows) || $rows[0]->gap_s === null) {
            return null;
        }

        $to = Carbon::parse($rows[0]->connected_at);
        $from = $to->copy()->subSeconds((int) $rows[0]->gap_s);

        return [
            'minutes' => round($rows[0]->gap_s / 60, 0),
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'in_quiet_window' => $from->hour >= $night[0] && $from->hour < $night[1],
        ];
    }
}
