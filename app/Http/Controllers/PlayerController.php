<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Game;
use App\Models\GameHasPlayer;
use App\Models\Player;
use App\Models\PlayerRanking;
use App\Models\SuspendedNickname;

class PlayerController extends Controller
{
    public function show(Request $request)
    {
        $u = $request->input('username', '');
        if($u == ''){
            $res = Player::where("player_id", $request->player_id)
            ->with('ranking')
            ->first();
        }else{
            $res = Player::where("username", $u)
            ->with('ranking')
            ->first();
        }
        if(!$res){
            return ['status' => false, 'msg' => 'Player not found!'];
        }
        $last5 = DB::table('pokerth_ranking.game_has_player')
        ->selectRaw('place')
        ->where('player_idplayer', $res->player_id)
        ->orderBy('start_time', 'DESC')
        ->limit(5)
        ->get()->map(function($game){
            return $game->place;
        });
        $pr = PlayerRanking::find($res->player_id);

        $pos = PlayerRanking::where('final_score', '>=', $pr->final_score)->orderBy('final_score', 'DESC')->count();
        $games = GameHasPlayer::where('player_idplayer', $res->player_id)->whereNotNull('end_time')->orderBy('end_time', 'DESC')->with('game')->limit(40)->get();
        $aGames = GameHasPlayer::where('player_idplayer', $res->player_id)->whereNotNull('end_time')->orderBy('end_time', 'DESC')->get();
        $stats = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
        foreach($aGames as $g){
            $stats[$g->place] += 1;
        }
        $bar_stats = [];
        foreach($stats as $stat){
            $bar_stats[] = $stat;
        }
        // percentage value num places
        $ag = $aGames->count();
        $p_stats = [];
        foreach($stats as $place => $stat){
            $p_stats[$place] = round($stat / ($ag/100), 1) . "%";
        }

        return ['status' => true, 'msg' => ['player' => $res, 'last5' => $last5, 'pos' => $pos, 'games' => $games, 'stats' => [$stats, $p_stats], 'bar_stats' => $bar_stats]];
    }

    public function search(Request $request)
    {
        $nick = $request->input('username', null);
        if(is_null($nick)){
            return ['status' => false, 'msg' => 'Missing Parameter!'];
        }
        return ['success' => true, 'players' => Player::where('username', 'LIKE', $nick . '%')->get()];
    }

    public function games(Request $request)
    {
        return GameHasPlayer::offset($request->l)->where('player_idplayer', $request->p)->whereNotNull('end_time')->orderBy('end_time', 'DESC')->with('game')->limit(40)->get();
    }

    public function account_create(Request $request)
    {
        $phpbb_confirm = DB::table('pokerth.phpbb_confirm')
        ->selectRaw('*')
        ->where('confirm_id', $request->confirm_id)
        ->where('code', $request->confirm_code)
        ->first();
        if(!$phpbb_confirm) return ['status' => 'success', 'msg' => 'code wrong'];
        else if($request->new_password != $request->password_confirm) return ['status' => 'success', 'msg' => 'Password repeat mismatch.'];
        else if(strlen($request->new_password) < 6) return ['status' => 'success', 'msg' => 'Password too short.'];
        $p = Player::where('email', $request->email)->first();
        if($p) return ['status' => false, 'msg' => 'The email address is already used in the ranking db - please contact a forum admin.'];
        $p2 = DB::table('pokerth.phpbb_users')
        ->selectRaw('*')
        ->where('user_email', $request->email)
        ->first();
        if($p2) return ['status' => 'success', 'msg' => 'email address is already used in the forum db'];
        $suspended = DB::table('pokerth_ranking.suspended_nicknames')
        ->selectRaw('*')
        ->where('nickname', $request->username)
        ->first();
        if($suspended) return ['status' => false, 'msg' => 'The username is suspended until next season.'];
        // create player
        $p = new Player();
        $p->username = $request->username;
        $p->email = $request->email;
        $p->created = date("Y-m-d H:i:s");
        $p->blocked = 0;
        $p->active = 0;
        $p->save();
        if(!DB::statement('UPDATE player SET password = AES_ENCRYPT(?, ?) WHERE email = ?', [ $request->new_password, env('APP_SALT'), $request->email ])) return ['status' => false, 'msg' => 'PW update during user creation failed.'];
        return ['status' => 'success'];
    }

    public function account_reset(Request $request)
    {
        $phpbb_user = DB::table('pokerth.phpbb_users')
            ->selectRaw('user_email, username')
            ->where('user_id', $request->u)
            ->where('reset_token', $request->token)
            ->where('reset_token_expiration', '>=', time())
            ->first();
        if(!$phpbb_user) return ['status' => false, 'msg' => 'Player not found or token expired.'];
        else if($request->new_password != $request->new_password_confirm) return ['status' => false, 'msg' => 'Password repeat mismatch.'];
        $p = Player::selectRaw('player_id, username, CAST(AES_DECRYPT(password, "'.env('APP_SALT').'") AS CHAR ) as password')
        ->where('username', $phpbb_user->username)
        ->first();
        if($p){
            if(!DB::statement('UPDATE player SET password = AES_ENCRYPT(?, ?) WHERE email = ?', [ $request->new_password, env('APP_SALT'), $phpbb_user->user_email ])) return ['status' => false, 'msg' => 'PW update failed.'];
        }else {
            // create player
            $p = new Player();
            $p->username = $phpbb_user->username;
            $p->email = $phpbb_user->user_email;
            $p->created = date("Y-m-d H:i:s");
            $p->blocked = 0;
            $p->active = 1;
            $p->save();
            if(!DB::statement('UPDATE player SET password = AES_ENCRYPT(?, ?) WHERE email = ?', [ $request->new_password, env('APP_SALT'), $phpbb_user->user_email ])) return ['status' => false, 'msg' => 'PW update during user creation failed.'];
        }
        $p = Player::selectRaw('player_id, username')
        ->where('username', $phpbb_user->username)
        ->first();
        $pr = PlayerRanking::selectRaw('player_id, username')
        ->where('username', $phpbb_user->username)
        ->first();
        if(!$pr){
            $pr = new PlayerRanking();
            $pr->player_id = $p->player_id;
            $pr->final_score = 0;
            $pr->username = $phpbb_user->username;
            $pr->points_sum = 0;
            $pr->season_games = 0;
            $pr->average_score = 0;
            $pr->save();
        }
        return ['status' => 'success'];
    }

    public function account_validate(Request $request)
    {
        $p = explode("&", $request->href);
        $user_id = explode("=", $p[1])[1];
        $phpbb_user = DB::table('pokerth.phpbb_users')
            ->selectRaw('user_email, username')
            ->where('user_id', $user_id)
            ->first();
        if(!$phpbb_user) return ['status' => false, 'msg' => 'User not found.'];
        $p = Player::selectRaw('player_id, username')
        ->where('email', $phpbb_user->user_email)
        ->first();
        if(!$p) return ['status' => false, 'msg' => 'Player not found.'];
        DB::statement('UPDATE player SET active = ? where username = ?', [ 1, $phpbb_user->username]);
        $pr = PlayerRanking::selectRaw('player_id, username')
        ->where('player_id', $p->player_id)
        ->first();
        if($pr) return ['status' => false, 'msg' => 'Player Ranking already exists.'];
        $pr = new PlayerRanking();
        $pr->player_id = $p->player_id;
        $pr->final_score = 0;
        $pr->username = $p->username;
        $pr->points_sum = 0;
        $pr->season_games = 0;
        $pr->average_score = 0;
        $pr->save();
        return ['status' => 'success'];
    }

    public function account_change(Request $request)
    {
        $phpbb_user = DB::table('pokerth.phpbb_users')
            ->where('user_email', $request->email)
            ->first();
        $p = Player::selectRaw('player_id, username, CAST(AES_DECRYPT(password, "'.env('APP_SALT').'") AS CHAR ) as password')
        ->where('email', $request->email)
        ->first();
        if(!$phpbb_user) return ['status' => false, 'msg' => 'Forum User not found.'];
        else if(!$p) return ['status' => false, 'msg' => 'Player not found.'];
        else if($request->new_password != $request->password_confirm) return ['status' => false, 'msg' => 'Password repeat mismatch.'];
        else if(sha1($request->creation_time . $phpbb_user->user_form_salt . 'ucp_reg_details') != $request->form_token) return ['status' => false, 'msg' => 'Token mismatch.'];
        else if(!DB::statement('UPDATE player SET password = AES_ENCRYPT(?, ?) WHERE email = ?', [ $request->new_password,env('APP_SALT'), $request->email ])) return ['status' => false, 'msg' => 'PW update failed.'];
        return ['status' => 'success'];
    }

    public function account_delete(Request $request)
    {
        $phpbb_user = DB::table('pokerth.phpbb_users')
        ->where('username', $request->nickname)
        ->first();
        $p = Player::where('username', $request->nickname)->first();
        if(!$phpbb_user) return ['success' => false, 'msg' => 'Forum User not found.'];
        else if(!$p) return ['success' => false, 'msg' => 'Player not found.'];
        else if(sha1($request->creation_time . $phpbb_user->user_form_salt . 'ucp_profile_info') != $request->form_token) return ['status' => false, 'msg' => 'Token mismatch.'];
        // *** @INFO: 1. phpbb stuff - taken from /includes/functions_user.php => function user_delete()
        // delete reports
        $rp = DB::table('pokerth.phpbb_reports')->join('pokerth.phpbb_posts', 'pokerth.phpbb_reports.post_id', '=', 'pokerth.phpbb_posts.post_id')
        ->where('pokerth.phpbb_reports.user_id', $phpbb_user->user_id)
        ->get();
        $report_posts = $report_topics = array();
        foreach ($rp as $report_post)
        {
            $report_posts[] = $report_post->post_id;
            $report_topics[] = $report_post->topic_id;
        }
        if (count($report_posts))
        {
            $report_posts = array_unique($report_posts);
            $report_topics = array_unique($report_topics);
            $krt = DB::table('pokerth.phpbb_posts')->selectRaw('DISTINCT topic_id')
            ->whereIn('pokerth.phpbb_posts.topic_id', $report_topics)
            ->where('pokerth.phpbb_posts.post_reported', 1)
            ->whereIn('pokerth.phpbb_posts.post_id', $report_posts)
            ->get();
            $keep_report_topics = array();
            foreach ($krt as $rt)
            {
                $keep_report_topics[] = $rt->topic_id;
            }
            if (count($keep_report_topics))
            {
                $report_topics = array_diff($report_topics, $keep_report_topics);
            }
            unset($keep_report_topics);
            DB::statement('UPDATE pokerth.phpbb_posts SET post_reported = 0 WHERE post_id IN(' . implode(',', $report_posts) . ')');
            if (count($report_topics))
            {
                DB::statement('UPDATE pokerth.phpbb_topics SET topic_reported = 0 WHERE topic_id IN(' . implode(',', $report_topics) . ')');
            }
        }
        DB::statement('DELETE FROM pokerth.phpbb_reports WHERE user_id = ' . $phpbb_user->user_id);
        // delete avatar
        $avatar_path = DB::table('pokerth.phpbb_config')->where('config_name', 'avatar_path')->pluck('config_value')->toArray()[0];
        if ($phpbb_user->user_avatar && $phpbb_user->user_avatar_type == 'avatar.driver.upload')
		{
            if (substr($phpbb_user->user_avatar, 0, 1) !== 'g' && $phpbb_user->user_avatar !== '' && !is_numeric($phpbb_user->user_avatar))
            {
                if (file_exists(base_path() . $avatar_path . '/' . $phpbb_user->user_avatar))
                {
                    @unlink(base_path() . $avatar_path . '/' . $phpbb_user->user_avatar);
                }
            }
        }
        // Unlink accounts from auth providers if it's not db
        $auth_provider = DB::table('pokerth.phpbb_config')->where('config_name', 'auth_method')->pluck('config_value')->toArray()[0];
        if($auth_provider !== 'db'){
            // @TODO: if oauth is used for example
        }
        // update num_users
        DB::statement('UPDATE pokerth.phpbb_config SET config_value=(config_value-1) WHERE config_name = ?', ['num_users']);
        // When we delete these users and retain the posts, we must assign all the data to the guest user
        DB::statement('UPDATE pokerth.phpbb_forums SET forum_last_poster_id = 1, forum_last_poster_name = ?, forum_last_poster_colour = ? WHERE forum_last_poster_id = ?',
            ['Deleted', '', $phpbb_user->user_id]);
        DB::statement('UPDATE pokerth.phpbb_posts SET poster_id = 1, post_username = ? WHERE poster_id = ?', ['Deleted', '', $phpbb_user->user_id]);
        DB::statement('UPDATE pokerth.phpbb_topics SET topic_poster = 1, topic_first_poster_name = ?, topic_first_poster_colour = ? WHERE topic_poster = ?',
            ['Deleted', '', $phpbb_user->user_id]);
        DB::statement('UPDATE pokerth.phpbb_topics SET topic_last_poster_id = 1, topic_last_poster_name = ?, topic_last_poster_colour = ? WHERE topic_last_poster_id = ?',
            ['Deleted', '', $phpbb_user->user_id]);
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
        foreach ($table_ary as $table)
        {
            try {
                DB::statement('DELETE FROM pokerth.' . $table . ' WHERE user_id = ' . $phpbb_user->user_id);
            } catch (\Throwable $e) {
                throw $e;
            }
            
        }
        // *** @INFO: 2. ranking stuff
        $sus = new  SuspendedNickname();
        $sus->nickname = $p->username;
        $sus->player_id = $p->player_id;
        $sus->save();
        $pr = PlayerRanking::where('username', $request->nickname)->first();
        $pr->username = $p->username = $p->email = 'deleted_' . $p->player_id;
        $pr->save();
        $p->save();
        return ['succes' => true];
    }

    public function set_gender_country(Request $request){
        $phpbb_user = DB::table('pokerth.phpbb_users')
        ->where('username', $request->username)
        ->first();
        $p = Player::selectRaw('player_id, username')
        ->where('username', $request->username)
        ->first();
        if(!$phpbb_user) return ['status' => false, 'msg' => 'Forum User not found.'];
        else if(!$p) return ['status' => false, 'msg' => 'Player not found.'];
        else if(sha1($request->creation_time . $phpbb_user->user_form_salt . 'ucp_profile_info') != $request->form_token) return ['status' => false, 'msg' => 'Token mismatch.'];
        else if(!DB::statement('UPDATE player SET country_iso = ?, gender = ? WHERE username = ?', [ strtoupper($request->country_iso), $request->pth_gender, $request->username ])) return ['status' => false, 'msg' => 'Setting Country/Gender failed.'];
        Cache::forget('gender.prof.' . $request->username);
        return ['status' => 'success'];
    }

    public function get_gender_country(Request $request){
        $p = Cache::rememberForever('gender.prof.' . $request->u, function() use($request){
            return Player::selectRaw('gender, country_iso')
            ->where('username', $request->u)
            ->first();
        });
        if(!$p) return ['status' => false, 'msg' => 'Player not found.'];
        return ['status' => 'success', 'gender' => $p->gender, 'country_iso' => strtolower($p->country_iso)];
    }

    public function getLeaderboard(Request $request){
        $filters = $request->input('filters');
        $page = $request->input('page', 1);
        $pagesize = $request->input('pageSize', 50);
        $sort = $request->input('sort');
        if($sort['prop'] === 'rank_pos') $sort['prop'] = 'final_score';
        $total = PlayerRanking::where([
            ['player_ranking.username', 'NOT LIKE', 'deleted_%'],
            ['player_ranking.season_games', '>', 3],
        ])->count();
        $query = DB::table('player_ranking')
        ->join('player', 'player.player_id', '=', 'player_ranking.player_id')
        ->selectRaw('player_ranking.*, player.country_iso, player.gender')
        ->where([
            ['player_ranking.username', 'NOT LIKE', 'deleted_%'],
            ['player_ranking.season_games', '>', 3],
        ])
        ->orderBy($sort['prop'], (($sort['order'] == 'descending') ? 'DESC' : 'ASC'))
        ->offset(($page-1)*$pagesize)->limit($pagesize);
        if(!empty($filters)){
            $query->where('player_ranking.username', 'LIKE', $filters['value'] . '%');
        }
        $leaderboard = $query->get()->map(function($player, $index) use($request, $filters){
            if(empty($filters)){
                $page = $request->input('page', 1);
                $pagesize = $request->input('pageSize', 50);
                $player->rank_pos = ($page-1)*$pagesize + $index + 1;
            }else{
                $player->rank_pos = DB::table('player_ranking')->where([
                    ['player_ranking.username', 'NOT LIKE', 'deleted_%'],
                    ['player_ranking.season_games', '>', 3],
                    ['player_ranking.final_score', '>=', $player->final_score],
                ])->count();
            }
            $player->gender_country = ['gender' => $player->gender, 'country' => $player->country_iso];
            $player->final_score = number_format((float)($player->final_score / 100), 2, '.', '');
            $player->average_score = number_format((float)($player->average_score / 100), 2, '.', '');
            return $player;
        });
        return ['total' => $total, 'data' => $leaderboard];
    }
}
