<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ist ein (Laravel-)User noch Admin? Der Laravel-User ist nur ein Schatten-
 * Konto zum Spieler gleichen Namens (siehe Admin\AuthController::login());
 * maßgeblich ist allein, ob der Spieler noch in `admin_player` steht.
 *
 * Kurz gecacht, damit nicht jeder Poll zwei Queries kostet. Wer aus
 * admin_player fliegt, ist also spätestens nach CACHE_TTL Sekunden draußen,
 * auch mit Remember-Cookie.
 */
class AdminAccess
{
  private const CACHE_TTL = 60;

  public function isAdmin(?User $user): bool
  {
    if (!$user || !$user->name) return false;
    return Cache::remember('admin_access.' . $user->id, self::CACHE_TTL, function () use ($user) {
      $playerId = DB::table('player')->where('username', $user->name)->value('player_id');
      if (!$playerId) return false;
      return DB::table('admin_player')->where('admin_idplayer', $playerId)->exists();
    });
  }

  /** player_id des Admins (für das Audit-Log), null wenn unbekannt. */
  public function playerId(?User $user): ?int
  {
    if (!$user || !$user->name) return null;
    $id = DB::table('player')->where('username', $user->name)->value('player_id');
    return $id ? (int) $id : null;
  }

  public function forget(User $user): void
  {
    Cache::forget('admin_access.' . $user->id);
  }
}
