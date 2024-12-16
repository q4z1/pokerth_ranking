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

        $game = Game::where('idgame', $g)->with('players.player.ranking')->get()->map(function($g){
            foreach($g->players as $i => $p){
                $g->players[$i]->player->rank = PlayerRanking::where('final_score', '>=', $p->player->ranking->final_score)->orderBy('final_score', 'DESC')->count();
            }
            return $g;
        });

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
            $p = PlayerRanking::where(DB::raw('BINARY `username`'), $request->input('u' . $i))->first();
            if($p){
                $p->rank_pos = PlayerRanking::where('final_score', '>=', $p->final_score)->orderBy('final_score', 'DESC')->count();
                $table[] = $p;
            }
        }
        return ['status' => true, 'msg' => $table];
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
