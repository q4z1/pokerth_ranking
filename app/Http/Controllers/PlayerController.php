<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\GameHasPlayer;
use App\Models\Player;
use App\Models\PlayerRanking;
use App\Models\SuspendedNickname;

class PlayerController extends Controller
{
  private $seasons = [];

  public function __construct()
  {
    $seasonsDb = config('database.connections.seasons.database');
    $seasons = Cache::rememberForever('player_seasons_' . $seasonsDb, function () use ($seasonsDb) {
      return DB::table('information_schema.tables')
        ->selectRaw('table_name')
        ->where('table_schema', $seasonsDb)
        ->where('table_name', 'LIKE', '%_player_ranking')
        ->get();
    });
    $seasons->map(function ($row) {
      $name = str_replace('_player_ranking', '', $row->table_name); // e.g. 2021_1
      if (!in_array($name, $this->seasons)) $this->seasons[] = $name;
    });
    rsort($this->seasons);
  }

  public function show(Request $request)
  {
    $u = $request->input('username', '');
    if ($u == '') {
      $res = Player::where("player_id", $request->player_id)
        ->with('ranking')
        ->first();
    } else {
      $res = Player::where("username", $u)
        ->with('ranking')
        ->first();
    }
    if (!$res) {
      return ['status' => false, 'msg' => 'Player not found!'];
    }
    $mainDb = DB::getDatabaseName();
    $last5 = DB::table($mainDb . '.game_has_player')
      ->selectRaw('place')
      ->where('player_idplayer', $res->player_id)
      ->orderBy('start_time', 'DESC')
      ->limit(5)
      ->get()->map(function ($game) {
        return $game->place;
      });
    $pr = PlayerRanking::find($res->player_id);

    $pos = PlayerRanking::where([
      ['username', 'NOT LIKE', 'deleted_%'],
      ['final_score', '>=', $pr->final_score]
    ])->orderBy('final_score', 'DESC')->count();
    $games = GameHasPlayer::where('player_idplayer', $res->player_id)->whereNotNull('end_time')->orderBy('end_time', 'DESC')->with('game')->limit(40)->get();
    $aGames = GameHasPlayer::where('player_idplayer', $res->player_id)->whereNotNull('end_time')->orderBy('end_time', 'DESC')->get();
    $stats = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
    foreach ($aGames as $g) {
      $stats[$g->place] += 1;
    }
    $bar_stats = [];
    foreach ($stats as $stat) {
      $bar_stats[] = $stat;
    }
    // percentage value num places
    $ag = $aGames->count();
    $p_stats = [];
    foreach ($stats as $place => $stat) {
      $p_stats[$place] = ($ag > 0) ? round($stat / ($ag / 100), 1) . "%" : '';
    }

    return ['status' => true, 'player' => $res, 'last5' => $last5, 'pos' => $pos, 'games' => $games, 'stats' => [$stats, $p_stats], 'bar_stats' => $bar_stats, 'seasons' => $this->seasons];
  }

  public function getSeason(Request $request, Player $player, $season)
  {
    $season = str_replace("-", "_", $season);
    // $season goes into a table name - strict format only (SQL injection attempts, 2026-11-18).
    if (!preg_match('/^\d{4}_\d{1,2}$/', $season)) {
      abort(404);
    }
    $seasonsDb = config('database.connections.seasons.database');
    // Kein Cache::flush() mehr: die Tabellen in pokerth_seasons werden beim
    // Season-Wechsel einmal befüllt und danach nicht mehr angefasst – neue
    // Spiele laufen in pokerth_ranking und tauchen hier nie auf. Der Flush
    // hat also nichts invalidiert, aber bei jedem Abruf den kompletten
    // Datei-Cache gelöscht; parallele Requests sind dabei über das
    // verschwindende Verzeichnis gestolpert und mit 500 abgebrochen.
    // Was tatsächlich veraltet, ist die Season-Liste – die räumt jetzt
    // SeasonSwitch ab.
    try {
      $player->ranking = Cache::rememberForever("{$player->player_id}_{$season}_ranking", function () use ($season, $player, $seasonsDb) {
        return DB::table("{$seasonsDb}.{$season}_player_ranking")
          ->selectRaw('*')
          ->where('player_id', $player->player_id)->first();
      });
    } catch (\Exception $e) {
      return ['status' => false, 'player' => $player, 'msg' => 'Season data unavailable'];
    }
    if(!is_null($player->ranking) && $player->ranking->season_games > 0){
      try {
        $pos = Cache::rememberForever("{$player->player_id}_{$season}_pos", function () use ($season, $player, $seasonsDb) {
            return DB::table("{$seasonsDb}.{$season}_player_ranking")
              ->selectRaw('*')
              ->where([
                ['username', 'NOT LIKE', 'deleted_%'],
                ['final_score', '>=', $player->ranking->final_score],
              ])->orderBy('final_score', 'DESC')->count();
        });
        $stats = Cache::rememberForever("{$player->player_id}_{$season}_stats", function () use ($season, $player, $seasonsDb) {
          $aGames = DB::table("{$seasonsDb}.{$season}_game_has_player")
            ->selectRaw('*')
            ->where('player_idplayer', $player->player_id)
            ->whereNotNull('end_time')->orderBy('end_time', 'DESC')->get();
          $stats = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
          foreach ($aGames as $g) {
            $stats[$g->place] += 1;
          }
          $bar_stats = [];
          foreach ($stats as $stat) {
            $bar_stats[] = $stat;
          }
          // percentage value num places
          $ag = $aGames->count();
          $p_stats = [];
          foreach ($stats as $place => $stat) {
            $p_stats[$place] = ($ag > 0) ? round($stat / ($ag / 100), 1) . "%" : '';
          }
          return [$stats, $p_stats, $bar_stats];
        });
        return ['status' => true, 'player' => $player, 'pos' => $pos, 'stats' => [$stats[0], $stats[1]], 'bar_stats' => $stats[2]];
      } catch (\Exception $e) {
        return ['status' => false, 'player' => $player, 'msg' => 'Season stats unavailable'];
      }
    }
 
    return ['status' => false, 'player' => $player];
  }

  public function search(Request $request)
  {
    $nick = $request->input('username', null);
    if (is_null($nick)) {
      return ['status' => false, 'msg' => 'Missing Parameter!'];
    }
    return ['success' => true, 'players' => Player::where('username', 'LIKE', $nick . '%')->get()];
  }

  public function games(Request $request)
  {
    return GameHasPlayer::offset($request->l)->where('player_idplayer', $request->p)->whereNotNull('end_time')->orderBy('end_time', 'DESC')->with('game')->limit(40)->get();
  }

  public function set_gender_country(Request $request)
  {
    $phpbb_user = DB::table('pokerth.phpbb_users')
      ->where('username', $request->username)
      ->first();
    $p = Player::selectRaw('player_id, username')
      ->where('username', $request->username)
      ->first();
    if (!$phpbb_user) return ['status' => false, 'msg' => 'Forum User not found.'];
    else if (!$p) return ['status' => false, 'msg' => 'Player not found.'];
    else if (sha1($request->creation_time . $phpbb_user->user_form_salt . 'ucp_profile_info') != $request->form_token) return ['status' => false, 'msg' => 'Token mismatch.'];
    else if (!DB::statement('UPDATE player SET country_iso = ?, gender = ? WHERE username = ?', [strtoupper($request->pth_country), $request->pth_gender, $request->username])) return ['status' => false, 'msg' => 'Setting Country/Gender failed.'];
    Cache::forget('gender.prof.' . $request->username);
    return ['status' => 'success'];
  }

  public function get_gender_country(Request $request)
  {
    $p = Cache::rememberForever('gender.prof.' . $request->u, function () use ($request) {
      return Player::selectRaw('gender, country_iso')
        ->where('username', $request->u)
        ->first();
    });
    if (!$p) return ['status' => false, 'msg' => 'Player not found.'];
    return ['status' => 'success', 'gender' => $p->gender, 'country_iso' => strtolower($p->country_iso)];
  }

  private function searchForPlayerId($id, $array) {
    foreach ($array as $key => $val) {
        if(is_array($val)){
          if ($val['player_id'] === $id) {
            return $key;
          }
        }else{
          if ($val->player_id === $id) {
            return $key;
          }
        }

    }
    return null;
  }

  public function getLeaderboard(Request $request, $season)
  {
    $seasonsDb = DB::connection('seasons')->getDatabaseName();
    $seasons = Cache::rememberForever('gseasons_' . $seasonsDb, function () use ($seasonsDb) {
      $seasons_raw = DB::connection('seasons')->select("SHOW TABLES LIKE '%_player_ranking';");
      $s = [];
      $key = 'Tables_in_' . $seasonsDb . ' (%_player_ranking)';
      foreach($seasons_raw as $row){
        $s[] = str_replace('_player_ranking', '', $row->$key);
      }
      rsort($s);
      return $s;
    });

    // $season goes into table names below - only allow known seasons (SQL injection, 2026-11-18).
    if ($season !== 'current' && !in_array($season, $seasons, true)) {
      abort(404);
    }

    $filters = $request->input('filters');
    $page = (int) $request->input('page', 1);
    $pagesize = (int) $request->input('pageSize', 25);
    $sort = $request->input('sort');
    if ($sort['prop'] === 'rank_pos') $sort['prop'] = 'final_score';

    if($season === "current"){
      $players = PlayerRanking::where([
        ['player_ranking.username', 'NOT LIKE', 'deleted_%'],
        ['player_ranking.season_games', '>', 3],
      ])->orderBy('final_score', 'DESC')->orderBy('points_sum', 'DESC')->get();
      $total = $players->count();
      $query = DB::table('player_ranking')
        ->join('player', 'player.player_id', '=', 'player_ranking.player_id')
        ->selectRaw('player_ranking.*, player.country_iso, player.gender');
      if (empty($filters) || is_null($filters['value'])) {
        if ($total === 0) return ['total' => $total, 'data' => [], 'seasons' => $seasons];
        $query->where([
          ['player_ranking.username', 'NOT LIKE', 'deleted_%'],
          ['player_ranking.season_games', '>', 3],
        ]);
      }
      $query->orderBy($sort['prop'], (($sort['order'] == 'descending') ? 'DESC' : 'ASC'))
        ->offset(($page - 1) * $pagesize)->limit($pagesize);
      if (!empty($filters)) {
        $query->where('player_ranking.username', 'LIKE', $filters['value'] . '%');
      }
      $leaderboard = $query->get()->map(function ($player, $index) use ($request, $filters, $players) {
        if (empty($filters)) {
          $page = $request->input('page', 1);
          $pagesize = $request->input('pageSize', 50);
          $player->rank_pos = ($page - 1) * $pagesize + $index + 1;
        } else {
          $player->rank_pos = $this->searchForPlayerId($player->player_id, $players) + 1;
        }
        $player->gender_country = ['gender' => $player->gender, 'country' => $player->country_iso];
        $player->final_score = number_format((float)($player->final_score / 100), 2, '.', '');
        $player->average_score = number_format((float)($player->average_score / 100), 2, '.', '');
        return $player;
      });
    }
    else{
      $players = DB::connection('seasons')->select("SELECT * from {$season}_player_ranking WHERE username NOT LIKE 'deleted_%' AND season_games > 3 ORDER BY final_score DESC, points_sum DESC;");

      $total = count($players);
      $seasonTable = "{$seasonsDb}.{$season}_player_ranking";
      $query = DB::table($seasonTable)
        ->join('player', 'player.player_id', '=', "{$seasonTable}.player_id")
        ->selectRaw("{$seasonTable}.*, player.country_iso, player.gender");
      if (empty($filters) || is_null($filters['value'])) {
        if ($total === 0) return ['total' => $total, 'data' => [], 'seasons' => $seasons];
        $query->where([
          ["{$seasonTable}.username", 'NOT LIKE', 'deleted_%'],
          ["{$seasonTable}.season_games", '>', 3],
        ]);
      }
      $query->orderBy($sort['prop'], (($sort['order'] == 'descending') ? 'DESC' : 'ASC'))
        ->offset(($page - 1) * $pagesize)->limit($pagesize);
      if (!empty($filters)) {
        $query->where("{$seasonTable}.username", 'LIKE', $filters['value'] . '%');
      }
      $leaderboard = $query->get();

      foreach($leaderboard as $key => $player){
        if (empty($filters)) {
          $player->rank_pos = ($page - 1) * $pagesize + $key + 1;
        } else {
          $player->rank_pos = $this->searchForPlayerId($player->player_id, $players) + 1;
        }
        $player->gender_country = ['gender' => $player->gender, 'country' => $player->country_iso];
        $player->final_score = number_format((float)($player->final_score / 100), 2, '.', '');
        $player->average_score = number_format((float)($player->average_score / 100), 2, '.', '');
        $leaderboard[$key] = $player;
      }
    }

    return ['total' => $total, 'data' => $leaderboard, 'seasons' => $seasons];
  }

  public function getSeasonLeaderboard(Request $request)
  {
    $filters = $request->input('filters');
    $page = $request->input('page', 1);
    $pagesize = $request->input('pageSize', 25);
    $sort = $request->input('sort');
    if ($sort['prop'] === 'rank_pos') $sort['prop'] = 'final_score';
    // @TODO: use the selected season for PlayerRanking
    $players = PlayerRanking::where([
      ['player_ranking.username', 'NOT LIKE', 'deleted_%'],
      ['player_ranking.season_games', '>', 3],
    ])->orderBy('final_score', 'DESC')->orderBy('points_sum', 'DESC')->get();
    $total = $players->count();
    $query = DB::table('player_ranking')
      ->join('player', 'player.player_id', '=', 'player_ranking.player_id')
      ->selectRaw('player_ranking.*, player.country_iso, player.gender');
    if (empty($filters) || is_null($filters['value'])) {
      if ($total === 0) return ['total' => $total, 'data' => []];
      $query->where([
        ['player_ranking.username', 'NOT LIKE', 'deleted_%'],
        ['player_ranking.season_games', '>', 3],
      ]);
    }
    $query->orderBy($sort['prop'], (($sort['order'] == 'descending') ? 'DESC' : 'ASC'))
      ->offset(($page - 1) * $pagesize)->limit($pagesize);
    if (!empty($filters)) {
      $query->where('player_ranking.username', 'LIKE', $filters['value'] . '%');
    }
    $leaderboard = $query->get()->map(function ($player, $index) use ($request, $filters, $players) {
      if (empty($filters)) {
        $page = $request->input('page', 1);
        $pagesize = $request->input('pageSize', 50);
        $player->rank_pos = ($page - 1) * $pagesize + $index + 1;
      } else {
        $player->rank_pos = $this->searchForPlayerId($player->player_id, $players) + 1;
      }
      $player->gender_country = ['gender' => $player->gender, 'country' => $player->country_iso];
      $player->final_score = number_format((float)($player->final_score / 100), 2, '.', '');
      $player->average_score = number_format((float)($player->average_score / 100), 2, '.', '');
      return $player;
    });
    return ['total' => $total, 'data' => $leaderboard];
  }
}
