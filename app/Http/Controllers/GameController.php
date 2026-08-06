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
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pdb)){
            return ["status" => false, "msg" => 'Invalid id'];
        }
        // No game_id (or a bogus one) is not an error: process_log_file() falls
        // back to the first game the log actually contains.
        $id = $request->input('game_id', null);
        $log = new LogFileController();
        $pdb .= ".pdb";
        $game = $log->process_log_file($pdb, $id);
        if($game === false){
            return ["status" => false, "msg" => 'Log file not found or unreadable!'];
        }
        return ["status" => true, "msg" => $game];
    }    

    // ── Raw pdb access (bridge for the web client) ───────────────────────
    // Same directory the desktop log-analysis upload uses; see
    // LogFileController::process_log_file().
    private const PDB_DIR = '/var/www/pokerth/log_file_analysis/upload';

    public function pdbDownload($id){
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)){
            return response()->json(['status' => false, 'msg' => 'Invalid id'], 400);
        }
        $path = self::PDB_DIR . '/' . $id . '.pdb';
        if(!is_file($path)){
            return response()->json(['status' => false, 'msg' => 'Not found'], 404);
        }
        return response()->download($path, $id . '.pdb', [
            'Content-Type' => 'application/x-sqlite3',
        ]);
    }

    public function pdbUpload(Request $request){
        $file = $request->file('pdb');
        if(!$file || !$file->isValid()){
            return response()->json(['status' => false, 'msg' => 'Missing file (multipart field "pdb")'], 400);
        }
        if($file->getSize() > 10 * 1024 * 1024){
            return response()->json(['status' => false, 'msg' => 'File too large'], 413);
        }
        // Must be a real SQLite database.
        $fh = @fopen($file->getRealPath(), 'rb');
        $magic = $fh ? fread($fh, 16) : '';
        if($fh) fclose($fh);
        if($magic !== "SQLite format 3\0"){
            return response()->json(['status' => false, 'msg' => 'Not a pdb file'], 422);
        }
        // Must contain the PokerTH log tables.
        try {
            $pdo = new \PDO('sqlite:' . $file->getRealPath());
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")
                          ->fetchAll(\PDO::FETCH_COLUMN);
            $pdo = null;
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'msg' => 'Unreadable pdb'], 422);
        }
        $tables = array_map('strtolower', $tables);
        foreach(['session', 'game', 'hand', 'action'] as $t){
            if(!in_array($t, $tables)){
                return response()->json(['status' => false, 'msg' => 'Not a PokerTH log'], 422);
            }
        }
        // 'web_' prefix: cannot collide with desktop log-analysis IDs.
        $id = 'web_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        try {
            $file->move(self::PDB_DIR, $id . '.pdb');
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'msg' => 'Store failed'], 500);
        }
        return ['status' => true, 'msg' => ['id' => $id]];
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
