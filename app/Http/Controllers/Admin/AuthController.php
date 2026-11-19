<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPlayer;
use App\Models\Player;
use App\Models\User;
use App\Services\AdminAccess;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Login/Logout des Admin-Backends. Rate-Limit über die Route
 * (throttle:admin-login, siehe AppServiceProvider).
 */
class AuthController extends Controller
{
  public function __construct()
  {
    $this->middleware('auth', ['except' => ['login']]);
  }

  public function login(Request $request)
  {
    $username = (string) $request->input('username', '');
    $password = (string) $request->input('password', '');

    $player = Player::selectRaw('player_id, username, CAST(AES_DECRYPT(password, "' . env('APP_SALT') . '") AS CHAR ) as password')
      ->where('username', $username)
      ->first();
    $ok = $player
      && is_string($player->password)
      && hash_equals($player->password, $password)
      && AdminPlayer::find($player->player_id);

    if (!$ok) {
      // Nur der Grund, nie das Passwort.
      $reason = !$player ? 'unknown user' : (AdminPlayer::find($player->player_id) ? 'wrong password' : 'not an admin');
      app(AdminAudit::class)->log('login_failed', ['name' => $username], ['reason' => $reason], false, $username);
      return ['success' => false, 'msg' => 'Login failed.'];
    }

    $user = User::where('name', $player->username)->first();
    if (!$user) {
      $user = new User();
      $user->name = $player->username;
      $user->email = 'admin_' . $player->player_id . '@pokerth.net';
      $user->password = Hash::make('never_used');
      $user->save();
    }
    Auth::login($user, true);
    $request->session()->regenerate();
    app(AdminAccess::class)->forget($user);
    app(AdminAudit::class)->log('login', ['player_id' => $player->player_id, 'name' => $player->username]);
    return ['success' => true, 'msg' => 'Login succesfull.'];
  }

  public function logout(Request $request)
  {
    app(AdminAudit::class)->log('logout');
    Auth::logout();
    return ['success' => true, 'msg' => 'Logged out.'];
  }
}
