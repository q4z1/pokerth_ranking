<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Kopie von server_messages.log des Game-Servers in der Tabelle server_log
 * (eine Zeile pro Logeintrag, seit Server-Run #23). Enthält Nicks und IPs,
 * daher nur mit Login.
 *
 * logged_at ist die lokale Uhrzeit des Game-Servers wie in der Datei, nicht
 * UTC – wird deshalb unverändert durchgereicht.
 */
class ServerMessagesController extends Controller
{
  private const LEVELS = ['ERR', 'MSG', 'OUT'];

  /** Download-Fenster höchstens so lang, begrenzt damit auch die Dateigröße. */
  public const DOWNLOAD_HOURS = 48;

  /** Zeitzone der Game-Server-Uhr, in der logged_at geschrieben wird. */
  private const SERVER_TZ = 'Europe/Berlin';

  public function __construct()
  {
    $this->middleware(['auth', 'admin']);
  }

  /**
   * Die letzten `limit` passenden Zeilen, chronologisch. Mit `before`
   * (log_id) werden ältere Zeilen nachgeladen.
   */
  public function index(Request $request)
  {
    if (!Auth::check()) abort(403);

    $limit = max(100, min(5000, (int) $request->query('limit', 1000)));
    $query = $this->filtered($request);
    if ($before = (int) $request->query('before', 0)) {
      $query->where('log_id', '<', $before);
    }

    $this->statementTimeout();
    $rows = $query->orderByDesc('log_id')->limit($limit + 1)->get();
    $more = $rows->count() > $limit;

    $list = $rows->take($limit)->reverse()->values()->map(fn ($r) => [
      'id'      => (int) $r->log_id,
      'run'     => $r->run_id !== null ? (int) $r->run_id : null,
      'time'    => $r->logged_at,
      'level'   => $r->level,
      'session' => $r->session_no !== null ? (int) $r->session_no : null,
      'game'    => $r->game_id !== null ? (int) $r->game_id : null,
      'pid'     => $r->player_id !== null ? (int) $r->player_id : null,
      'name'    => $r->player_name,
      'ip'      => $r->ip_text,
      'msg'     => $r->message,
    ]);

    return [
      'success' => true,
      'list'    => $list,
      'more'    => $more,
      'limit'   => $limit,
      'runs'    => DB::table('server_run')->orderByDesc('run_id')->limit(200)
        ->get(['run_id', 'started_at', 'stopped_at']),
      'latest'  => DB::table('server_log')->max('log_id'),
      'download_hours' => self::DOWNLOAD_HOURS,
      'server_now' => (new \DateTimeImmutable('now', new \DateTimeZone(self::SERVER_TZ)))->format('Y-m-d H:i:s'),
    ];
  }

  /**
   * Download im Format der Originaldatei ("2026-Nov-18 13:11:47 MSG: …")
   * für ein Zeitfenster [start, start + hours), hours höchstens
   * DOWNLOAD_HOURS – das begrenzt zugleich die Dateigröße. Das Fenster darf
   * beliebig weit zurückliegen; ohne start sind es die letzten `hours`
   * Stunden. Level/Run/Spieler/IP/Text-Filter der Ansicht gelten zusätzlich,
   * from/to der Ansicht nicht. Gestreamt, damit nichts im Speicher landet.
   */
  public function download(Request $request)
  {
    if (!Auth::check()) abort(403);

    $hours = max(1, min(self::DOWNLOAD_HOURS, (int) $request->query('hours', self::DOWNLOAD_HOURS)));
    // logged_at ist Wanduhrzeit des Game-Servers; die Fensterarithmetik läuft
    // zonenlos (UTC), damit Sommer-/Winterzeit nichts verschiebt.
    $utc = new \DateTimeZone('UTC');
    $start = $this->dateTime($request->query('start'));
    if ($start) {
      $from = new \DateTimeImmutable($start, $utc);
    } else {
      $now = (new \DateTimeImmutable('now', new \DateTimeZone(self::SERVER_TZ)))->format('Y-m-d H:i:s');
      $from = (new \DateTimeImmutable($now, $utc))->modify("-{$hours} hours");
    }
    $to = $from->modify("+{$hours} hours");

    $query = $this->filtered($request, false)
      ->select(['log_id', 'logged_at', 'level', 'message'])
      ->where('logged_at', '>=', $from->format('Y-m-d H:i:s'))
      ->where('logged_at', '<', $to->format('Y-m-d H:i:s'));

    $name = 'server_messages_' . $from->format('Y-m-d_Hi') . '_' . $to->format('Y-m-d_Hi') . '.log';

    \Log::info('Admin server_messages download', ['admin' => Auth::user()->name ?? null, 'query' => $request->query()]);

    return response()->streamDownload(function () use ($query) {
      $query->lazyById(5000, 'log_id')->each(function ($r) {
        echo $this->boostTime($r->logged_at), ' ', $r->level, ': ', $r->message, "\n";
      });
    }, $name, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
  }

  /** Gemeinsame Filter für Ansicht und Download. */
  private function filtered(Request $request, bool $withRange = true)
  {
    $query = DB::table('server_log')->select([
      'log_id', 'run_id', 'logged_at', 'level', 'session_no', 'game_id',
      'player_id', 'player_name', DB::raw('INET6_NTOA(ip) AS ip_text'), 'message',
    ]);

    $levels = array_values(array_intersect(self::LEVELS, (array) $request->query('level', [])));
    if ($levels && count($levels) < count(self::LEVELS)) {
      $query->whereIn('level', $levels);
    }

    if ($withRange && $from = $this->dateTime($request->query('from'))) {
      $query->where('logged_at', '>=', $from);
    }
    if ($withRange && $to = $this->dateTime($request->query('to'))) {
      // Ein reines Datum als "bis" schließt den ganzen Tag ein.
      if (strlen(trim((string) $request->query('to'))) <= 10) {
        $to = (new \DateTimeImmutable(substr($to, 0, 10), new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d 00:00:00');
      }
      $query->where('logged_at', '<', $to);
    }

    if ($run = (int) $request->query('run', 0)) {
      $query->where('run_id', $run);
      // game_id und session_no sind nur innerhalb eines Runs eindeutig.
      if (($game = $request->query('game')) !== null && $game !== '') $query->where('game_id', (int) $game);
      if (($session = $request->query('session')) !== null && $session !== '') $query->where('session_no', (int) $session);
    }

    if (($pid = $request->query('pid')) !== null && $pid !== '') {
      $query->where('player_id', (int) $pid);
    }

    $ip = trim((string) $request->query('ip', ''));
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
      $query->where('ip', inet_pton($ip));
    }

    $q = trim((string) $request->query('q', ''));
    if ($q !== '') {
      $like = '%' . addcslashes($q, '%_\\') . '%';
      $query->where(function ($w) use ($q, $like) {
        $w->where('message', 'like', $like)->orWhere('player_name', $q);
      });
    }

    return $query;
  }

  private function dateTime($value): ?string
  {
    // Wanduhrzeit des Servers, ohne Zeitzonen-Umrechnung durchreichen.
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(:\d{2})?)?$/', trim((string) $value), $m)) return null;
    return $m[1] . ' ' . ($m[2] ?? '00:00') . ($m[3] ?? ':00');
  }

  /** "2026-11-18 13:11:47" → "2026-Nov-18 13:11:47" (boost::posix_time-Ausgabe). */
  private function boostTime(string $value): string
  {
    $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
    return $d ? $d->format('Y-M-d H:i:s') : $value;
  }

  /** Freitextsuche ohne Zeitraum kann die ganze Tabelle scannen. */
  private function statementTimeout(): void
  {
    try {
      DB::statement('SET SESSION max_statement_time = 20');
    } catch (\Throwable $e) {
      // nicht kritisch
    }
  }
}
