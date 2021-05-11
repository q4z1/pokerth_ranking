<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Game;
use App\Models\GameHasPlayer;
use App\Models\Player;
use App\Models\PlayerRanking;
use stdClass;

class GameController extends Controller
{
    public function get(Request $request)
    {
        $g = $request->input('g', '');
        if($g == '') return ['status' => false, 'msg' => 'Missing Parameter'];

        $game = "Game loded";

        $game = Game::where('idgame', $g)->with('players')->get();

        return ['status' => true, 'msg' => $game];
    }

    public function log(Request $request){
        $pdb = $request->input('pdb', false);
        if(!$pdb){
            return ["status" => false, "msg" => 'Missing Parameter!'];
        }
        $id = $request->input('id', 1);
        $log = new LogFileController();
        $pdb .= ".pdb";
        $game = $log->process_log_file($pdb, $id);
        return ["status" => true, "msg" => $game];
    }    

    public function games(Request $request)
    {
        return GameHasPlayer::offset($request->l)->where('player_idplayer', $request->p)->whereNotNull('end_time')->orderBy('end_time', 'DESC')->with('game')->limit(5)->get();

    }

    public function show_table(Request $request){
        $table = [];
        for($i=1;$i<=10;$i++)
        {
            $p = PlayerRanking::where('username', $request->input('u' . $i))->first();
            if($p) $table[] = $p;
        }
        return ['status' => true, 'msg' => $table];
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
        if($p) return ['status' => false, 'msg' => 'email already used'];

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
        ->where('email', $phpbb_user->user_email)
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
        ->where('email', $phpbb_user->user_email)
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

        DB::statement('UPDATE player SET active = ? where email = ?', [ 1, $phpbb_user->user_email]);

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
        $p = Player::selectRaw('player_id, username, CAST(AES_DECRYPT(password, "'.env('APP_SALT').'") AS CHAR ) as password')
        ->where('email', $request->email)
        ->first();
        if(!$p) return ['status' => false, 'msg' => 'Player not found.'];
        else if($p->password != $request->cur_password) return ['status' => false, 'msg' => 'Password mismatch.'];
        else if($request->new_password != $request->password_confirm) return ['status' => false, 'msg' => 'Password repeat mismatch.'];
        else if(!DB::statement('UPDATE player SET password = AES_ENCRYPT(?, ?) WHERE email = ?', [ $request->new_password,env('APP_SALT'), $request->email ])) return ['status' => false, 'msg' => 'PW update failed.'];
        return ['status' => 'success'];
    }

    public function getLeaderboard(Request $request){
        $filters = $request->input('filters');
        $page = $request->input('page', 1);
        $pagesize = $request->input('pageSize', 50);
        $sort = $request->input('sort');
        
        $all = PlayerRanking::where('player_ranking.username', 'NOT LIKE', 'deleted_%')->get();

        $ppos = 1;

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