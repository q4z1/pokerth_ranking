<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "PokerTH right now" - Livezahlen fuer die Seitenleiste auf pokerth.net.
 *
 * Quelle ist die Ein-Zeilen-Heartbeat-Tabelle server_live_stats, die der
 * Gameserver einmal pro Minute schreibt (docs/live-stats-heartbeat.md).
 * "players online" laesst sich zusaetzlich aus server_session ableiten und
 * dient als Fallback, solange der Heartbeat (noch) nicht schreibt.
 */
class LiveStatsController extends Controller
{
    /** Aelter als das gilt die Heartbeat-Zeile als tot (Sekunden). */
    private const STALE_AFTER = 150;

    /**
     * Bei totem Heartbeat werden offene Sessions nur gezaehlt, wenn ihr
     * connected_at nicht laenger zurueckliegt - ein abgestuerzter, nicht
     * neugestarteter Server hinterlaesst sonst Phantomspieler.
     */
    private const FALLBACK_SESSION_WINDOW_HOURS = 6;

    public function now()
    {
        return Cache::remember('live_stats.now', 15, function () {
            $row = DB::table('server_live_stats')->where('id', 1)->first();

            $updatedAt = $row ? Carbon::parse($row->updated_at) : null;
            $stale = $updatedAt === null
                || $updatedAt->lt(now()->subSeconds(self::STALE_AFTER));

            $online = $stale
                ? $this->openSessions()
                : (int) $row->players_online;

            return [
                'online' => $online,
                'tables' => $stale ? null : (int) $row->tables_running,
                'waiting' => $stale ? null : (int) $row->players_waiting,
                'stale' => $stale,
                'updated' => $updatedAt?->toIso8601String(),
            ];
        });
    }

    /** Offene Sessions im juengsten Lauf, auf ein Zeitfenster begrenzt. */
    private function openSessions(): int
    {
        $runId = DB::table('server_run')->max('run_id');
        if ($runId === null) {
            return 0;
        }

        return DB::table('server_session')
            ->where('run_id', $runId)
            ->whereNull('disconnected_at')
            ->where('connected_at', '>=', now()->subHours(self::FALLBACK_SESSION_WINDOW_HOURS))
            ->count();
    }
}
