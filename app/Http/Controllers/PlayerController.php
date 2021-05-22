<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Game;
use App\Models\GameHasPlayer;
use App\Models\Player;
use App\Models\PlayerRanking;
use stdClass;

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

        $pos_array = PlayerRanking::orderBy('final_score', 'DESC')->get();
        $pos = 1;
        foreach($pos_array as $player){
            if($player->player_id == $res->player_id) break;
            $pos++;
        }

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

        return ['status' => true, 'msg' => ['player' => $res, 'last5' => $last5, 'pos' => $pos, 'games' => $games, 'stats' => $stats, 'bar_stats' => $bar_stats]];
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

        if(!$phpbb_confirm) return ['status' => false, 'msg' => 'code wrong'];
        else if($request->new_password != $request->password_confirm) return ['status' => false, 'msg' => 'Password repeat mismatch.'];

        $p = Player::where('email', $request->email)->first();
        if($p) return ['status' => false, 'msg' => 'email already used in ranking db'];

        $p2 = DB::table('pokerth.phpbb_users')
        ->selectRaw('*')
        ->where('user_email', $request->email)
        ->first();
        if($p2) return ['status' => false, 'msg' => 'email already used in forum db'];


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

    public function set_country(Request $request){
        $phpbb_user = DB::table('pokerth.phpbb_users')
        ->where('username', $request->username)
        ->first();
        $p = Player::selectRaw('player_id, username')
        ->where('username', $request->username)
        ->first();
        if(!$phpbb_user) return ['status' => false, 'msg' => 'Forum User not found.'];
        else if(!$p) return ['status' => false, 'msg' => 'Player not found.'];
        else if(sha1($request->creation_time . $phpbb_user->user_form_salt . 'ucp_profile_info') != $request->form_token) return ['status' => false, 'msg' => 'Token mismatch.'];
        else if(!DB::statement('UPDATE player SET country_iso = ? WHERE username = ?', [ strtoupper($request->country_iso), $request->username ])) return ['status' => false, 'msg' => 'Setting Country ID failed.'];
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
        
        $all = PlayerRanking::where('player_ranking.username', 'NOT LIKE', 'deleted_%')->get();

        $total = $all->count();

        $query = DB::table('player_ranking')
        ->join('player', 'player.player_id', '=', 'player_ranking.player_id')
        ->select('player_ranking.*', 'player.country_iso', 'player.gender')
        ->where('player_ranking.username', 'NOT LIKE', 'deleted_%')
        ->orderBy($sort['prop'], (($sort['order'] == 'descending') ? 'DESC' : 'ASC'))
        ->offset(($page-1)*$pagesize)->limit($pagesize);
        if(!empty($filters)){
            $query->where('player_ranking.username', 'LIKE', $filters['value'] . '%');
        }

        $leaderboard = $query->get()->map(function($player){
            $player->final_score = number_format((float)($player->final_score / 100), 2, '.', '');
            $player->average_score = number_format((float)($player->average_score / 100), 2, '.', '');
            return $player;
        });
        $lp = [];
        foreach($leaderboard as $index => $pos){
            $page = $request->input('page', 1);
            $pagesize = $request->input('pageSize', 50);
            $pos->rank_pos = ($page-1)*$pagesize + $index + 1;
            $lp[] = $pos;
        }
        return ['total' => $total, 'data' => $lp];

    }
    
}
