<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\AdminPlayer;
use App\Models\Advert;
use App\Models\Player;
use App\Models\PlayerRanking;
use App\Models\ReportedAvatar;
use App\Models\ReportedGamename;
use App\Models\User;

class AdminController extends Controller
{
  public function __construct()
  {
    $this->middleware('auth', ['except' => ['login']]);
  }

  public function login(Request $request)
  {
    $player = Player::selectRaw('player_id, username, CAST(AES_DECRYPT(password, "' . env('APP_SALT') . '") AS CHAR ) as password')
      ->where('username', $request->input('username', ''))
      ->first();
    if ($player && $player->password === $request->input('password', '') && AdminPlayer::find($player->player_id)) {
      $user = User::where('name', $player->username)->first();
      if (!$user) {
        $user = new User();
        $user->name = $player->username;
        $user->email = 'admin_' . $player->player_id . '@pokerth.net';
        $user->password = Hash::make('never_used');
        $user->save();
      }
      Auth::login($user, true);
      return ['success' => true, 'msg' => 'Login succesfull.'];
    } else {
      return ['success' => false, 'msg' => 'Login failed.'];
    }
  }

  public function logout(Request $request)
  {
    Auth::logout();
    return ['success' => true, 'msg' => 'Logged out.'];
  }

  public function reports(Request $request, $type = null)
  {
    if ($request->isMethod('GET')) {
      $list = [];
      if (!is_null($type) && $type === 'avatar') {
        $list = ReportedAvatar::orderBy('timestamp', 'DESC')->get()->map(function ($avatar) {
          $avatar->creator = Player::where('player_id', $avatar->idplayer)->first();
          $avatar->reporter = Player::where('player_id', $avatar->by_idplayer)->first();
          return $avatar;
        });
      } else if (!is_null($type) && $type === 'gamename') {
        $list = ReportedGamename::orderBy('timestamp', 'DESC')->get()->map(function ($gamename) {
          $gamename->creator = Player::where('player_id', $gamename->game_creator_idplayer)->first();
          $gamename->reporter = Player::where('player_id', $gamename->by_idplayer)->first();
          return $gamename;
        });
      } else {
        return ['success' => false, 'msg' => 'Unknown Type.'];
      }
      return ['success' => true, 'list' => $list];
    }
  }

  public function adverts(Request $request)
  {
    if ($request->isMethod('GET')) return ['success' => true, 'list' => Advert::selectRaw('`id`, `position`, `content`, `order`, `start`, `end`')->orderBy('created_at', 'DESC')->get()];
  }

  public function banlist(Request $request, Player $player)
  {
    if ($request->isMethod('GET')) return ['success' => true, 'list' => Player::selectRaw('`player_id`, `username`, `last_login`, `created`')->where('active', 4)->orderBy('username', 'ASC')->get()];
    $action = $request->input('action', null);
    if (is_null($action)) return ['success' => false, 'msg' => 'Action not found.'];
    if ($action === 'delete') {
      return $this->delete($player);
    } else if ($action === 'unban') {
      $player->active = 1;
      $player->save();
      return ['success' => true, 'msg' => 'Player unbanned.'];
    } else if ($action === 'ban') {
      $player->active = 4;
      $player->save();
      return ['success' => true, 'msg' => 'Player banned.'];
    }
  }

  private function delete(Player $player)
  {
    if (!$player) return ['success' => false, 'msg' => 'Player not found.'];
    $phpbb_user = DB::table('pokerth.phpbb_users')
      ->where('username', $player->username)
      ->first();
    if (!$phpbb_user) return ['success' => false, 'msg' => 'Forum User not found.'];
    // *** @INFO: 1. phpbb stuff - taken from /includes/functions_user.php => function user_delete()
    // delete reports
    $rp = DB::table('pokerth.phpbb_reports')->join('pokerth.phpbb_posts', 'pokerth.phpbb_reports.post_id', '=', 'pokerth.phpbb_posts.post_id')
      ->where('pokerth.phpbb_reports.user_id', $phpbb_user->user_id)
      ->get();
    $report_posts = $report_topics = array();
    foreach ($rp as $report_post) {
      $report_posts[] = $report_post->post_id;
      $report_topics[] = $report_post->topic_id;
    }
    if (count($report_posts)) {
      $report_posts = array_unique($report_posts);
      $report_topics = array_unique($report_topics);
      $krt = DB::table('pokerth.phpbb_posts')->selectRaw('DISTINCT topic_id')
        ->whereIn('pokerth.phpbb_posts.topic_id', $report_topics)
        ->where('pokerth.phpbb_posts.post_reported', 1)
        ->whereIn('pokerth.phpbb_posts.post_id', $report_posts)
        ->get();
      $keep_report_topics = array();
      foreach ($krt as $rt) {
        $keep_report_topics[] = $rt->topic_id;
      }
      if (count($keep_report_topics)) {
        $report_topics = array_diff($report_topics, $keep_report_topics);
      }
      unset($keep_report_topics);
      DB::statement('UPDATE pokerth.phpbb_posts SET post_reported = 0 WHERE post_id IN(' . implode(',', $report_posts) . ')');
      if (count($report_topics)) {
        DB::statement('UPDATE pokerth.phpbb_topics SET topic_reported = 0 WHERE topic_id IN(' . implode(',', $report_topics) . ')');
      }
    }
    DB::statement('DELETE FROM pokerth.phpbb_reports WHERE user_id = ' . $phpbb_user->user_id);
    // delete avatar
    $avatar_path = DB::table('pokerth.phpbb_config')->where('config_name', 'avatar_path')->pluck('config_value')->toArray()[0];
    if ($phpbb_user->user_avatar && $phpbb_user->user_avatar_type == 'avatar.driver.upload') {
      if (substr($phpbb_user->user_avatar, 0, 1) !== 'g' && $phpbb_user->user_avatar !== '' && !is_numeric($phpbb_user->user_avatar)) {
        if (file_exists(base_path() . $avatar_path . '/' . $phpbb_user->user_avatar)) {
          @unlink(base_path() . $avatar_path . '/' . $phpbb_user->user_avatar);
        }
      }
    }
    // Unlink accounts from auth providers if it's not db
    $auth_provider = DB::table('pokerth.phpbb_config')->where('config_name', 'auth_method')->pluck('config_value')->toArray()[0];
    if ($auth_provider !== 'db') {
      // @TODO: if oauth is used for example
    }
    // update num_users
    DB::statement('UPDATE pokerth.phpbb_config SET config_value=(config_value-1) WHERE config_name = ?', ['num_users']);
    // When we delete these users and retain the posts, we must assign all the data to the guest user
    DB::statement(
      'UPDATE pokerth.phpbb_forums SET forum_last_poster_id = 1, forum_last_poster_name = ?, forum_last_poster_colour = ? WHERE forum_last_poster_id = ?',
      ['Deleted', '', $phpbb_user->user_id]
    );
    DB::statement('UPDATE pokerth.phpbb_posts SET poster_id = 1, post_username = ? WHERE poster_id = ?', ['Deleted', '', $phpbb_user->user_id]);
    DB::statement(
      'UPDATE pokerth.phpbb_topics SET topic_poster = 1, topic_first_poster_name = ?, topic_first_poster_colour = ? WHERE topic_poster = ?',
      ['Deleted', '', $phpbb_user->user_id]
    );
    DB::statement(
      'UPDATE pokerth.phpbb_topics SET topic_last_poster_id = 1, topic_last_poster_name = ?, topic_last_poster_colour = ? WHERE topic_last_poster_id = ?',
      ['Deleted', '', $phpbb_user->user_id]
    );
    // Since we change every post by this author, we need to count this amount towards the anonymous user
    $added_guest_posts = ($phpbb_user->user_posts) ? $phpbb_user->user_posts : 0;
    // Assign more data to the Anonymous user
    DB::statement('UPDATE pokerth.phpbb_attachments SET poster_id = 1 WHERE poster_id = ' . $phpbb_user->user_id);
    DB::statement('UPDATE pokerth.phpbb_users SET user_posts = user_posts + ' . $added_guest_posts . ' WHERE user_id = 1');
    // delete any entry with user_id from the following tables:
    $table_ary = [
      'phpbb_users',
      'phpbb_user_group',
      'phpbb_topics_watch',
      'phpbb_forums_watch',
      'phpbb_acl_users',
      'phpbb_topics_track',
      'phpbb_topics_posted',
      'phpbb_forums_track',
      'phpbb_profile_fields_data',
      'phpbb_moderator_cache',
      'phpbb_drafts',
      'phpbb_bookmarks',
      'phpbb_sessions_keys',
      'phpbb_privmsgs_folder',
      'phpbb_privmsgs_rules',
      'phpbb_oauth_tokens',
      'phpbb_oauth_states',
      'phpbb_oauth_accounts',
      'phpbb_user_notifications'
    ];
    foreach ($table_ary as $table) {
      try {
        DB::statement('DELETE FROM pokerth.' . $table . ' WHERE user_id = ' . $phpbb_user->user_id);
      } catch (\Throwable $e) {
        throw $e;
      }
    }
    PlayerRanking::where('username', $player->username)->delete();
    $player->delete();
    return ['success' => true, 'msg' => "Player deleted."];
  }
}
