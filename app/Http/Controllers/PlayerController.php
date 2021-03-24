<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Player;
use App\Models\PlayerRanking;
use stdClass;

class PlayerController extends Controller
{
    public function show(Request $request)
    {
        $res = Player::where("player_id", $request->player_id)
            ->first();
        //dd($res->toArray());
        return ['status' => true, 'msg' => $res];
    }

    public function autocomplete(Request $request)
    {
        $res = Player::select("username")
            ->where("username","LIKE","%{$request->term}%")
            ->get();
        return $res;
    }

    public function getLeaderboard(Request $request){
        // $start= $request->input('start', 1);
        // $size= $request->input('size', 50);
        $username = $request->input('username', '');
        
        $rows = DB::table('player_ranking')
        ->join('player', 'player.player_id', '=', 'player_ranking.player_id')
        ->select('player_ranking.*', 'player.country_iso', 'player.gender')
        ->where('player_ranking.username', 'NOT LIKE', 'deleted_%')
        ->get();

        return ['data' => $rows];

    }
    
}
