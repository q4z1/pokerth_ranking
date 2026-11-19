<?php

namespace App\Http\Controllers\Admin;

use App\Services\AdminAudit;
use App\Services\GameServerAdminClient;
use App\Services\ServerLogAnalyzer;
use App\Services\WebServerLogAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lesende Log-Ansichten des Backends: Game-Server-Aktivität, Webserver,
 * Lobby-Chat und das Audit-Log der Admin-Aktionen.
 */
class LogsController extends AdminBaseController
{
  /**
   * Lobby-Aktivität aus server_run / server_session, siehe ServerLogAnalyzer.
   * Nur mit Login, siehe Kommentar im Konstruktor.
   */
  public function serverLog(Request $request)
  {
    $days = (int) $request->query('days', 30);
    $days = max(8, min(90, $days));
    return app(ServerLogAnalyzer::class)->analyze($days);
  }

  /**
   * Webserver-Traffic und Cloudflare-Filterhistorie aus AttackCheck's
   * Logdateien, siehe WebServerLogAnalyzer. Nur mit Login, siehe Kommentar
   * im Konstruktor.
   */
  public function webServerLog(Request $request)
  {
    $hours = (int) $request->query('hours', 24);
    $hours = max(1, min(168, $hours));
    return app(WebServerLogAnalyzer::class)->analyze($hours);
  }

  /**
   * Lobby-Chat-Mitschnitt des Game-Servers, eine JSON-Zeile pro Nachricht
   * ({ch, msg, name, pid, time, ts}). Anders als serverLog() mit rohen
   * Nicks und Chattext - daher nur mit Login.
   *
   * Gelesen werden nur die letzten `limit` Zeilen, damit die Datei beliebig
   * wachsen darf. Reicht die aktuelle Datei dafür nicht, wird mit der von
   * logrotate abgelegten Vorgängerdatei (<log>.1) aufgefüllt.
   */
  public function lobbyChat(Request $request)
  {
    // Doppelt zur auth-Middleware im Konstruktor: das Log ist absichtlich
    // nur über PHP erreichbar, ein versehentlicher Eintrag in deren
    // except-Liste darf es nicht öffentlich machen.
    if (!Auth::check()) abort(403);

    $limit = (int) $request->query('limit', 1000);
    $limit = max(100, min(20000, $limit));

    $path = config('services.lobby_chat_log');
    $rotated = $path . '.1';
    if (!is_readable($path) && !is_readable($rotated)) {
      return ['success' => false, 'msg' => 'Lobby chat log not found.'];
    }

    $lines = is_readable($path) ? $this->tailLines($path, $limit) : [];
    if (count($lines) < $limit && is_readable($rotated)) {
      $lines = array_merge($this->tailLines($rotated, $limit - count($lines)), $lines);
    }

    $list = $this->chatRows($lines);
    $modified = is_readable($path) ? filemtime($path) : filemtime($rotated);

    // Global Notices nur aus dem Zeitraum des Chat-Ausschnitts, sonst
    // stünden alte Notices vor dem ersten geladenen Chat-Eintrag.
    $noticeLog = config('services.global_notice_log');
    if (is_readable($noticeLog)) {
      $from = $list ? $list[0]['ts'] : 0;
      $notices = array_filter($this->chatRows($this->tailLines($noticeLog, 500)), fn ($r) => $r['ts'] >= $from);
      if ($notices) {
        $list = array_merge($list, $notices);
        // Stabil nach Zeit sortieren: gleiche Sekunde behält die Reihenfolge.
        $keyed = array_map(null, $list, array_keys($list));
        usort($keyed, fn ($a, $b) => [$a[0]['ts'], $a[1]] <=> [$b[0]['ts'], $b[1]]);
        $list = array_slice(array_column($keyed, 0), -$limit);
      }
      $modified = max($modified, filemtime($noticeLog));
    }

    return [
      'success'      => true,
      'list'         => $list,
      'limit'        => $limit,
      'modified'     => $modified,
      'notice_bytes' => GameServerAdminClient::NOTICE_MAX_BYTES,
    ];
  }

  /** JSON-Lines-Zeilen des Chat-Logs → Zeilen für das Backend. */
  private function chatRows(array $lines): array
  {
    $list = [];
    foreach ($lines as $line) {
      $row = json_decode($line, true);
      if (!is_array($row) || !isset($row['msg'])) continue;
      $list[] = [
        'ts'   => (int) ($row['ts'] ?? 0),
        'time' => $row['time'] ?? null,
        'name' => (string) ($row['name'] ?? ''),
        'pid'  => isset($row['pid']) ? (int) $row['pid'] : null,
        'ch'   => (string) ($row['ch'] ?? 'lobby'),
        'msg'  => (string) $row['msg'],
      ];
    }
    return $list;
  }

  /** Die letzten $count nicht-leeren Zeilen einer Datei, blockweise von hinten gelesen. */
  private function tailLines(string $path, int $count): array
  {
    $fh = fopen($path, 'rb');
    if (!$fh) return [];
    fseek($fh, 0, SEEK_END);
    $pos = ftell($fh);
    $buffer = '';
    while ($pos > 0 && substr_count($buffer, "\n") <= $count) {
      $read = min(65536, $pos);
      $pos -= $read;
      fseek($fh, $pos);
      $buffer = fread($fh, $read) . $buffer;
    }
    fclose($fh);

    $lines = array_values(array_filter(explode("\n", $buffer), 'strlen'));
    // Ohne Dateianfang ist die erste Zeile womöglich angeschnitten.
    if ($pos > 0) array_shift($lines);
    return array_slice($lines, -$count);
  }

  /**
   * Audit-Log der Admin-Aktionen (Tabelle admin_action), neueste zuerst.
   * Filter: action, admin, q (Ziel-Nick oder exakte player_id), page.
   */
  public function auditLog(Request $request)
  {
    $perPage = max(10, min(200, (int) $request->query('per_page', 50)));
    $page = max(1, (int) $request->query('page', 1));

    $query = DB::table('admin_action');
    $action = (string) $request->query('action', '');
    if ($action !== '') $query->where('action', $action);
    $admin = trim((string) $request->query('admin', ''));
    if ($admin !== '') $query->where('admin_name', $admin);
    $q = trim((string) $request->query('q', ''));
    if ($q !== '') {
      $like = '%' . addcslashes($q, '%_\\') . '%';
      $query->where(function ($w) use ($q, $like) {
        $w->where('target_name', 'LIKE', $like);
        if (ctype_digit($q)) $w->orWhere('target_player_id', (int) $q);
      });
    }

    $total = (clone $query)->count();
    $rows = $query->orderBy('id', 'DESC')->forPage($page, $perPage)->get();

    return [
      'success'  => true,
      'total'    => $total,
      'page'     => $page,
      'per_page' => $perPage,
      'actions'  => AdminAudit::ACTIONS,
      'admins'   => DB::table('admin_action')->whereNotNull('admin_name')
        ->where('action', '<>', 'login_failed')->distinct()->orderBy('admin_name')->pluck('admin_name'),
      'list'     => $rows->map(fn ($r) => [
        'id'               => (int) $r->id,
        'created_at'       => $r->created_at,
        'admin'            => $r->admin_name,
        'action'           => $r->action,
        'target_player_id' => $r->target_player_id ? (int) $r->target_player_id : null,
        'target_name'      => $r->target_name,
        'success'          => (bool) $r->success,
        'details'          => $r->details ? json_decode($r->details, true) : null,
        'ip'               => $r->ip,
      ])->values(),
    ];
  }
}
