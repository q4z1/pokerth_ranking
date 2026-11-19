<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Audit-Trail aller Admin-Aktionen (Tabelle `admin_action`, Seite "Audit log"
 * im Backend). Schreibt zusätzlich eine Zeile ins Laravel-Log, damit die
 * Aktion auch dann nachvollziehbar bleibt, wenn der DB-Insert scheitert.
 *
 * Ein Fehler beim Protokollieren darf die eigentliche Aktion nie abbrechen.
 */
class AdminAudit
{
  /** Bekannte Aktionen → Anzeigename (auch Filterliste im Frontend). */
  public const ACTIONS = [
    'login'            => 'Login',
    'login_failed'     => 'Login failed',
    'logout'           => 'Logout',
    'ban'              => 'Ban',
    'unban'            => 'Unban',
    'player_delete'    => 'Delete player',
    'mute'             => 'Shadow mute',
    'unmute'           => 'Shadow unmute',
    'kickban'          => 'Kickban',
    'message'          => 'Private message',
    'notice'           => 'Global notice',
    'report_state'     => 'Report status',
    'report_archive'   => 'Report archived',
    'report_unarchive' => 'Report restored',
    'avatar_blacklist' => 'Avatar blacklisted',
    'advert_create'    => 'Advert created',
    'advert_update'    => 'Advert updated',
    'advert_delete'    => 'Advert deleted',
  ];

  /**
   * @param array $target ['player_id' => ?int, 'name' => ?string]
   * @param array $details beliebige Zusatzdaten (wird als JSON gespeichert)
   * @param string|null $adminName überschreibt den eingeloggten User (Login-Versuche)
   */
  public function log(string $action, array $target = [], array $details = [], bool $success = true, ?string $adminName = null): void
  {
    $admin = $adminName ?? (Auth::user()->name ?? null);
    $ip = request()?->ip();
    $row = [
      'created_at'       => now()->format('Y-m-d H:i:s'),
      'admin_name'       => $admin !== null ? mb_substr($admin, 0, 64) : null,
      'action'           => mb_substr($action, 0, 32),
      'target_player_id' => isset($target['player_id']) && $target['player_id'] ? (int) $target['player_id'] : null,
      'target_name'      => isset($target['name']) && $target['name'] !== '' ? mb_substr((string) $target['name'], 0, 64) : null,
      'success'          => $success,
      'details'          => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      'ip'               => $ip,
    ];

    Log::info('Admin action', $row);
    try {
      DB::table('admin_action')->insert($row);
    } catch (\Throwable $e) {
      report($e);
    }
  }
}
