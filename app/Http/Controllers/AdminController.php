<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\AdminPlayer;
use App\Models\Player;
use App\Models\User;

class AdminController extends Controller
{
  public function login(Request $request)
  {
    $player = Player::selectRaw('player_id, username, CAST(AES_DECRYPT(password, "'.env('APP_SALT').'") AS CHAR ) as password')
    ->where('username', $request->input('username', ''))
    ->first();
    if($player && $player->password === $request->input('password', '') && AdminPlayer::find($player->player_id)){
      $user = User::where('name', $player->username)->first();
      if(!$user){
        $user = new User();
        $user->name = $player->username;
        $user->email = 'admin_' . $player->player_id . '@pokerth.net';
        $user->password = Hash::make('never_used');
        $user->save();
      }
      Auth::login($user, true);
      return ['success' => true, 'msg' => 'Login succesfull.'];
    }else{
      return ['success' => false, 'msg' => 'Login failed.'];
    }
  }

  public function logout(Request $request)
  {
    Auth::logout();
    return ['success' => true, 'msg' => 'Logged out.'];
  }
}
