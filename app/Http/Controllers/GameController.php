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
        $id = $request->input('game_id', 1);
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

    public function getCOD(){
        $points = [
            1 => 15,
            2 => 9,
            3 => 6,
            4 => 4,
            5 => 3,
            6 => 2,
            7 => 1,
            8 => 0,
            9 => 0,
            10 => 0
        ];

        //@TODO: cache
        $games = Game::whereBetween('end_time', [date('Y-m-d 00:00:00', strtotime('-1 day')), date('Y-m-d 23:59:59', strtotime('-1 day'))])
        ->with('players')->get();
        $players = [];
        foreach($games as $game){
            $pls = $game->players;
            foreach($pls as $pl){
                if(!array_key_exists($pl->player->player_id, $players)){
                    $players[$pl->player->player_id] = [
                        'username' => $pl->player->username,
                        'url' => '/player?u=' . $pl->player->username,
                        'score' => 0,
                        'games' => 0
                    ];
                }
                $players[$pl->player->player_id]['games'] += 1;
                $players[$pl->player->player_id]['score'] += $points[$pl->place];
            }
            
        }
        foreach($players as $id => $pl){
            $players[$id]['score'] = $players[$id]['score']/$players[$id]['games'];
            if($pl['games'] < 6) unset($players[$id]);
        }

        usort($players, function($a, $b) {
            return $a['score'] <=> $b['score'];
        });

        return array_reverse($players);
    }
    
}
