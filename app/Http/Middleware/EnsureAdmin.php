<?php

namespace App\Http\Middleware;

use App\Services\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Läuft nach `auth`: der eingeloggte User muss weiterhin in admin_player
 * stehen. Sonst wird die Session (inkl. Remember-Cookie) beendet und 401
 * geliefert – das Frontend zeigt dann wieder den Login.
 */
class EnsureAdmin
{
  public function handle(Request $request, Closure $next)
  {
    $user = Auth::user();
    if ($user && app(AdminAccess::class)->isAdmin($user)) {
      return $next($request);
    }

    if ($user) {
      Auth::logout();
      if ($request->hasSession()) {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
      }
    }
    return response()->json(['success' => false, 'msg' => 'Unauthenticated.'], 401);
  }
}
