<?php

namespace App\Http\Controllers\Admin;

use App\Models\Player;
use App\Services\GameServerAdminClient;
use App\Services\PlayerDeletionService;
use App\Services\ReportStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Eingriffe gegen Spieler: Ban/Unban/Delete, Shadow Mute und die Live-Aktionen
 * über den Admin-Bot (Kickban, private Nachricht, Global Notice).
 * Jede Aktion landet im Audit-Log.
 */
class ModerationController extends AdminBaseController
{
  /** Offenes Ende laut Server-Doku; alles ab hier gilt als dauerhaft. */
  private const SHADOW_MUTE_PERMANENT = '9999-12-31 23:59:59';

  /** Erlaubte Mute-Dauern → MySQL-INTERVAL-Ausdruck. */
  private const SHADOW_MUTE_DURATIONS = [
    '1h'  => '1 HOUR',
    '1d'  => '1 DAY',
    '3d'  => '3 DAY',
    '7d'  => '7 DAY',
    '30d' => '30 DAY',
    '90d' => '90 DAY',
  ];

  public function banlist(Request $request, Player $player)
  {
    if ($request->isMethod('GET')) {
      $banned = DB::table('player')->where('active', ReportStats::PLAYER_BANNED)
        ->orderBy('username', 'ASC')
        ->get(['player_id', 'username', 'last_login', 'created']);
      $totals = $this->stats()->reportTotals($banned->pluck('player_id')->all());
      return [
        'success' => true,
        'list' => $banned->map(function ($row) use ($totals) {
          $counts = $totals[$row->player_id] ?? ['gamename' => 0, 'avatar' => 0, 'total' => 0, 'open' => 0];
          return [
            'player_id'        => (int) $row->player_id,
            'username'         => $row->username,
            'last_login'       => $row->last_login,
            'created'          => $row->created,
            'gamename_reports' => $counts['gamename'],
            'avatar_reports'   => $counts['avatar'],
            'total_reports'    => $counts['total'],
          ];
        })->values(),
      ];
    }

    $action = $request->input('action', null);
    if (is_null($action)) return ['success' => false, 'msg' => 'Action not found.'];
    if (!$player->exists) return ['success' => false, 'msg' => 'Player not found.'];
    $target = ['player_id' => $player->player_id, 'name' => $player->username];

    if ($action === 'delete') {
      $result = $this->delete($player);
      $this->audit()->log('player_delete', $target, ['msg' => $result['msg']], $result['success']);
      return $result;
    } else if ($action === 'unban') {
      $player->active = ReportStats::PLAYER_ACTIVE;
      $player->save();
      $this->audit()->log('unban', $target);
      return ['success' => true, 'msg' => 'Player unbanned.'];
    } else if ($action === 'ban') {
      $player->active = ReportStats::PLAYER_BANNED;
      $player->save();
      // Offene Meldungen desselben Spielers gleich mit abschließen, damit die
      // Report-Listen nicht dauerhaft mit erledigten Fällen zuwachsen.
      $resolved = $request->boolean('resolve_reports', true)
        ? $this->stats()->resolveOpenReports($player->player_id, ReportStats::STATE_CREATOR_BANNED)
        : 0;
      $this->audit()->log('ban', $target, ['resolved_reports' => $resolved]);
      return ['success' => true, 'msg' => 'Player banned.', 'resolved_reports' => $resolved];
    }

    return ['success' => false, 'msg' => 'Unknown action.'];
  }

  /**
   * Shadow Mute: der Game-Server stellt Chatzeilen des Spielers niemandem
   * sonst zu, solange player.shadow_mute_until > NOW() ist (siehe
   * docs/server_shadow_mute_schema.sql im pokerth-Repo) und liest die Liste
   * alle paar Sekunden neu – kein Re-Login nötig.
   *
   * Alle Zeitrechnungen laufen über die DB-Uhr, weil der Server genau die
   * prüft; PHP läuft auf Europe/Berlin, MariaDB auf UTC.
   */
  public function shadowMute(Request $request, Player $player)
  {
    if ($request->isMethod('GET')) {
      $rows = DB::table('player')->whereNotNull('shadow_mute_until')
        ->orderBy('shadow_mute_until', 'DESC')
        ->get([
          'player_id', 'username', 'last_login', 'active', 'shadow_mute_until',
          DB::raw('shadow_mute_until > NOW() AS muted'),
          DB::raw('GREATEST(TIMESTAMPDIFF(SECOND, NOW(), shadow_mute_until), 0) AS remaining'),
        ]);
      $totals = $this->stats()->reportTotals($rows->pluck('player_id')->all());
      return [
        'success' => true,
        'list' => $rows->map(function ($row) use ($totals) {
          $permanent = $row->shadow_mute_until >= self::SHADOW_MUTE_PERMANENT;
          return [
            'player_id'     => (int) $row->player_id,
            'username'      => $row->username,
            'last_login'    => $row->last_login,
            'banned'        => (int) $row->active === ReportStats::PLAYER_BANNED,
            'until'         => $permanent ? null : $row->shadow_mute_until,
            'permanent'     => $permanent,
            'muted'         => (bool) $row->muted,
            'remaining'     => $permanent ? null : (int) $row->remaining,
            'total_reports' => $totals[$row->player_id]['total'] ?? 0,
          ];
        })->values(),
      ];
    }

    $action = $request->input('action', null);
    if (is_null($action)) return ['success' => false, 'msg' => 'Action not found.'];
    if (!$player->exists) return ['success' => false, 'msg' => 'Player not found.'];
    $target = ['player_id' => $player->player_id, 'name' => $player->username];

    $query = DB::table('player')->where('player_id', $player->player_id);
    if ($action === 'unmute') {
      $query->update(['shadow_mute_until' => null]);
      $this->audit()->log('unmute', $target);
      return ['success' => true, 'msg' => 'Shadow mute lifted.'];
    } else if ($action === 'mute') {
      $duration = $request->input('duration', '');
      if ($duration === 'permanent') {
        $query->update(['shadow_mute_until' => self::SHADOW_MUTE_PERMANENT]);
      } else if (isset(self::SHADOW_MUTE_DURATIONS[$duration])) {
        // Intervall stammt aus der Whitelist, kein User-Input im SQL.
        $query->update(['shadow_mute_until' => DB::raw('NOW() + INTERVAL ' . self::SHADOW_MUTE_DURATIONS[$duration])]);
      } else {
        return ['success' => false, 'msg' => 'Unknown duration.'];
      }
      $this->audit()->log('mute', $target, ['duration' => $duration]);
      return ['success' => true, 'msg' => 'Player shadow muted.'];
    }

    return ['success' => false, 'msg' => 'Unknown action.'];
  }

  /**
   * Global Notice live über die WebSocket-Schnittstelle des Game-Servers:
   * geht als Broadcast-Chat an alle verbundenen Clients (Lobby und Tische).
   */
  public function globalNotice(Request $request)
  {
    $text = trim((string) $request->input('text', ''));
    $admin = Auth::user()->name ?? null;

    $result = app(GameServerAdminClient::class)->globalNotice($text);
    $this->audit()->log('notice', [], ['text' => $text, 'code' => $result['code'], 'msg' => $result['msg']], $result['ok']);

    if ($result['ok']) {
      $now = time();
      $line = json_encode([
        'ts' => $now, 'time' => gmdate('Y-m-d\TH:i:s\Z', $now), 'name' => (string) $admin, 'ch' => 'notice', 'msg' => $text,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      @file_put_contents(config('services.global_notice_log'), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    return ['success' => $result['ok'], 'code' => $result['code'], 'msg' => $result['msg']];
  }

  /**
   * Private Nachricht vom Admin-Bot an einen Online-Spieler. Einweg: Antworten
   * gehen an das Bot-Konto und werden nicht ausgewertet.
   */
  public function privateMessage(Request $request)
  {
    $playerId = (int) $request->input('player_id', 0);
    $nick = trim((string) $request->input('nick', ''));
    if ($playerId) {
      // Bei Accounts zählt der Name aus der DB, nicht der vom Browser.
      $nick = (string) (DB::table('player')->where('player_id', $playerId)->value('username') ?? '');
      if ($nick === '') return ['success' => false, 'msg' => 'Player not found.'];
    }
    if ($nick === '') return ['success' => false, 'msg' => 'No player given.'];
    $text = trim((string) $request->input('text', ''));

    $result = app(GameServerAdminClient::class)->privateMessage($nick, $text);
    $this->audit()->log('message', ['player_id' => $playerId ?: null, 'name' => $nick], [
      'text' => $text, 'code' => $result['code'], 'msg' => $result['msg'],
    ], $result['ok']);

    return ['success' => $result['ok'], 'code' => $result['code'], 'msg' => $result['msg']];
  }

  /**
   * Kickban live über die WebSocket-Schnittstelle des Game-Servers: Kick,
   * 24-h-IP-Sperre und Account-Bann (player.active = 4) in einem Schritt.
   * Funktioniert nur, solange der Spieler verbunden ist.
   */
  public function kickban(Request $request)
  {
    $playerId = (int) $request->input('player_id', 0);
    $nick = trim((string) $request->input('nick', ''));
    if ($playerId) {
      // Bei Accounts zählt der Name aus der DB, nicht der vom Browser.
      $nick = (string) (DB::table('player')->where('player_id', $playerId)->value('username') ?? '');
      if ($nick === '') return ['success' => false, 'msg' => 'Player not found.'];
    }
    if ($nick === '') return ['success' => false, 'msg' => 'No player given.'];

    $result = app(GameServerAdminClient::class)->kickban($nick);

    $resolved = 0;
    if ($result['ok'] && $playerId && $request->boolean('resolve_reports', true)) {
      $resolved = $this->stats()->resolveOpenReports($playerId, ReportStats::STATE_CREATOR_BANNED);
    }
    $this->audit()->log('kickban', ['player_id' => $playerId ?: null, 'name' => $nick], [
      'code' => $result['code'], 'msg' => $result['msg'], 'resolved_reports' => $resolved,
    ], $result['ok']);

    return [
      'success'          => $result['ok'],
      'code'             => $result['code'],
      'msg'              => $result['msg'],
      'resolved_reports' => $resolved,
    ];
  }

  /** Players-Seite → "Delete": Forum-User + Ranking-Account, siehe PlayerDeletionService. */
  private function delete(Player $player)
  {
    if (!$player->exists) return ['success' => false, 'msg' => 'Player not found.'];
    $svc = app(PlayerDeletionService::class);
    $found = $svc->find($player->username);
    if (!$found['forum']) return ['success' => false, 'msg' => 'Forum User not found.'];
    if ($found['ambiguous']) return ['success' => false, 'msg' => 'Ambiguous username – use php artisan player:delete.'];
    try {
      $svc->delete($found['forum'], $found['player']);
    } catch (\Throwable $e) {
      report($e);
      return ['success' => false, 'msg' => 'Delete failed, nothing was changed: ' . $e->getMessage()];
    }
    return ['success' => true, 'msg' => 'Player deleted.'];
  }
}
