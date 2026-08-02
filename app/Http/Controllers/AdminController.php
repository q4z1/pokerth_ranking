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
  /**
   * Bedeutung der `state`-Spalte in reported_gamename / reported_avatar.
   * (siehe Spaltenkommentar in der DB)
   */
  public const STATE_NEW            = 0;
  public const STATE_IGNORED        = 1;
  public const STATE_CREATOR_WARNED = 2;
  public const STATE_CREATOR_BANNED = 3;
  public const STATE_REPORTER_SURE  = 4;
  public const STATE_REPORTER_SPAM  = 5;

  public const STATES = [
    self::STATE_NEW,
    self::STATE_IGNORED,
    self::STATE_CREATOR_WARNED,
    self::STATE_CREATOR_BANNED,
    self::STATE_REPORTER_SURE,
    self::STATE_REPORTER_SPAM,
  ];

  /** Player.active-Wert eines gebannten Accounts. */
  public const PLAYER_BANNED = 4;
  public const PLAYER_ACTIVE = 1;

  /**
   * Beide Report-Arten teilen sich dieselbe Logik, unterscheiden sich aber in
   * Tabelle und Spaltennamen.
   */
  private const REPORT_TYPES = [
    'gamename' => ['model' => ReportedGamename::class, 'table' => 'reported_gamename', 'creator' => 'game_creator_idplayer'],
    'avatar'   => ['model' => ReportedAvatar::class,   'table' => 'reported_avatar',   'creator' => 'idplayer'],
  ];

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

  /**
   * Liste aller Reports eines Typs – inklusive Spieler-Daten und der Anzahl
   * bisheriger Meldungen des gemeldeten Spielers (Grundlage für Wiederholungs-Bans).
   */
  public function reports(Request $request, $type = null)
  {
    if (!isset(self::REPORT_TYPES[$type])) return ['success' => false, 'msg' => 'Unknown Type.'];
    $cfg = self::REPORT_TYPES[$type];
    $creatorColumn = $cfg['creator'];

    $rows = $cfg['model']::orderBy('timestamp', 'DESC')->get();

    $creatorIds = $rows->pluck($creatorColumn)->filter()->unique()->values();
    $reporterIds = $rows->pluck('by_idplayer')->filter()->unique()->values();
    $players = $this->playerLookup($creatorIds->merge($reporterIds)->unique()->all());
    $offences = $this->reportTotals($creatorIds->all());

    $list = $rows->map(function ($row) use ($type, $creatorColumn, $players, $offences) {
      $creatorId = $row->{$creatorColumn};
      $counts = $offences[$creatorId] ?? ['gamename' => 0, 'avatar' => 0, 'total' => 0, 'open' => 0];
      return [
        'id'          => (int) $row->id,
        'type'        => $type,
        'state'       => (int) $row->state,
        'timestamp'   => $row->timestamp,
        'game_name'   => $row->game_name ?? null,
        'game_idgame' => $row->game_idgame ?? null,
        'avatar_hash' => $row->avatar_hash ?? null,
        'avatar_type' => $row->avatar_type ?? null,
        'creator'     => $this->playerPayload($creatorId, $players),
        'reporter'    => $this->playerPayload($row->by_idplayer, $players),
        'offences'    => $counts,
      ];
    });

    return ['success' => true, 'list' => $list, 'states' => $this->stateLabels()];
  }

  /**
   * Aggregierte Sicht: pro gemeldetem Spieler die Summe aller Meldungen über
   * beide Report-Arten hinweg. Das ist die Arbeitsgrundlage, um Wiederholungs-
   * täter zu bannen.
   */
  public function offenders(Request $request)
  {
    $min = max(1, (int) $request->input('min', 1));

    $rows = collect(DB::select($this->offenderSql()));
    $players = $this->playerLookup($rows->pluck('pid')->all());

    $list = $rows->filter(function ($row) use ($min) {
      return (int) $row->total_reports >= $min;
    })->map(function ($row) use ($players) {
      $player = $players[$row->pid] ?? null;
      return [
        'player_id'          => (int) $row->pid,
        'username'           => $player->username ?? null,
        'banned'             => $player ? ((int) $player->active === self::PLAYER_BANNED) : false,
        'exists'             => (bool) $player,
        'last_login'         => $player->last_login ?? null,
        'created'            => $player->created ?? null,
        'gamename_reports'   => (int) $row->gamename_reports,
        'avatar_reports'     => (int) $row->avatar_reports,
        'total_reports'      => (int) $row->total_reports,
        'open_reports'       => (int) $row->open_reports,
        'distinct_reporters' => (int) $row->distinct_reporters,
        'last_report'        => $row->last_report,
      ];
    })->sortByDesc('total_reports')->values();

    return ['success' => true, 'list' => $list];
  }

  /**
   * Aktionen auf Reports: Status setzen oder Einträge löschen.
   */
  public function reportAction(Request $request, $type = null)
  {
    if (!isset(self::REPORT_TYPES[$type])) return ['success' => false, 'msg' => 'Unknown Type.'];
    $cfg = self::REPORT_TYPES[$type];

    $ids = $request->input('ids', []);
    if (is_string($ids)) $ids = array_filter(explode(',', $ids), 'strlen');
    $ids = array_values(array_unique(array_map('intval', (array) $ids)));
    if (!count($ids)) return ['success' => false, 'msg' => 'No reports selected.'];

    $action = $request->input('action');
    if ($action === 'delete') {
      $affected = $cfg['model']::whereIn('id', $ids)->delete();
      return ['success' => true, 'msg' => $affected . ' report(s) deleted.', 'affected' => $affected];
    }

    if ($action === 'state') {
      $state = (int) $request->input('state', self::STATE_NEW);
      if (!in_array($state, self::STATES, true)) return ['success' => false, 'msg' => 'Unknown state.'];
      $affected = $cfg['model']::whereIn('id', $ids)->update(['state' => $state]);
      return ['success' => true, 'msg' => $affected . ' report(s) updated.', 'affected' => $affected];
    }

    return ['success' => false, 'msg' => 'Unknown action.'];
  }

  public function adverts(Request $request)
  {
    if ($request->isMethod('GET')) {
      return ['success' => true, 'list' => Advert::orderBy('position', 'ASC')->orderBy('order', 'ASC')->get(['id', 'position', 'content', 'order', 'start', 'end'])];
    }

    $action = $request->input('action');
    if ($action === 'delete') {
      $advert = Advert::find((int) $request->input('id'));
      if (!$advert) return ['success' => false, 'msg' => 'Advert not found.'];
      $advert->delete();
      return ['success' => true, 'msg' => 'Advert deleted.'];
    }

    if ($action === 'create' || $action === 'update') {
      $data = $request->validate([
        'position' => 'required|string|max:32',
        'content'  => 'required|string',
        'order'    => 'required|integer|min:0|max:127',
        'start'    => 'required|date',
        'end'      => 'required|date|after_or_equal:start',
      ]);
      if ($action === 'create') {
        $advert = Advert::create($data);
        return ['success' => true, 'msg' => 'Advert created.', 'advert' => $advert];
      }
      $advert = Advert::find((int) $request->input('id'));
      if (!$advert) return ['success' => false, 'msg' => 'Advert not found.'];
      $advert->update($data);
      return ['success' => true, 'msg' => 'Advert updated.', 'advert' => $advert];
    }

    return ['success' => false, 'msg' => 'Unknown action.'];
  }

  public function banlist(Request $request, Player $player)
  {
    if ($request->isMethod('GET')) {
      $banned = DB::table('player')->where('active', self::PLAYER_BANNED)
        ->orderBy('username', 'ASC')
        ->get(['player_id', 'username', 'last_login', 'created']);
      $totals = $this->reportTotals($banned->pluck('player_id')->all());
      return [
        'success' => true,
        'list' => $banned->map(function ($row) use ($totals) {
          $counts = $totals[$row->player_id] ?? ['gamename' => 0, 'avatar' => 0, 'total' => 0, 'open' => 0];
          return [
            'player_id'        => (int) $row->player_id,
            'username'         => $row->username,
            'last_login'       => $row->last_login,
            'created'          => $row->created,
            'gamename_reports' => $counts['gamename'],
            'avatar_reports'   => $counts['avatar'],
            'total_reports'    => $counts['total'],
          ];
        })->values(),
      ];
    }

    $action = $request->input('action', null);
    if (is_null($action)) return ['success' => false, 'msg' => 'Action not found.'];
    if (!$player->exists) return ['success' => false, 'msg' => 'Player not found.'];

    if ($action === 'delete') {
      return $this->delete($player);
    } else if ($action === 'unban') {
      $player->active = self::PLAYER_ACTIVE;
      $player->save();
      return ['success' => true, 'msg' => 'Player unbanned.'];
    } else if ($action === 'ban') {
      $player->active = self::PLAYER_BANNED;
      $player->save();
      // Offene Meldungen desselben Spielers gleich mit abschließen, damit die
      // Report-Listen nicht dauerhaft mit erledigten Fällen zuwachsen.
      $resolved = $request->boolean('resolve_reports', true)
        ? $this->resolveOpenReports($player->player_id, self::STATE_CREATOR_BANNED)
        : 0;
      return ['success' => true, 'msg' => 'Player banned.', 'resolved_reports' => $resolved];
    }

    return ['success' => false, 'msg' => 'Unknown action.'];
  }

  /**
   * Setzt alle noch offenen Meldungen gegen einen Spieler auf den übergebenen Status.
   */
  private function resolveOpenReports($playerId, $state)
  {
    $affected = 0;
    foreach (self::REPORT_TYPES as $cfg) {
      $affected += $cfg['model']::where($cfg['creator'], $playerId)
        ->where('state', self::STATE_NEW)
        ->update(['state' => $state]);
    }
    return $affected;
  }

  /**
   * Spieler-Stammdaten für eine Menge von IDs – bewusst über den Query Builder,
   * damit `active` (im Model versteckt) verfügbar bleibt.
   */
  private function playerLookup(array $ids)
  {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!count($ids)) return collect();
    return DB::table('player')->whereIn('player_id', $ids)
      ->get(['player_id', 'username', 'active', 'created', 'last_login'])
      ->keyBy('player_id');
  }

  private function playerPayload($playerId, $players)
  {
    if (!$playerId) return null;
    $player = $players[$playerId] ?? null;
    return [
      'player_id' => (int) $playerId,
      'username'  => $player->username ?? null,
      'banned'    => $player ? ((int) $player->active === self::PLAYER_BANNED) : false,
      'exists'    => (bool) $player,
    ];
  }

  /**
   * Meldungen pro Spieler über beide Report-Arten hinweg.
   *
   * @return array<int, array{gamename:int, avatar:int, total:int, open:int}>
   */
  private function reportTotals(array $ids)
  {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!count($ids)) return [];
    $rows = DB::select($this->offenderSql($ids));
    $out = [];
    foreach ($rows as $row) {
      $out[(int) $row->pid] = [
        'gamename' => (int) $row->gamename_reports,
        'avatar'   => (int) $row->avatar_reports,
        'total'    => (int) $row->total_reports,
        'open'     => (int) $row->open_reports,
      ];
    }
    return $out;
  }

  /**
   * Vereinigt beide Report-Tabellen und aggregiert je gemeldetem Spieler.
   * Optional auf eine Liste von Spieler-IDs eingeschränkt; die IDs werden als
   * Integer inlined, da PDO keine Array-Bindings für IN() kennt.
   */
  private function offenderSql(?array $ids = null)
  {
    $gameFilter = $avatarFilter = '';
    if (!is_null($ids)) {
      $inList = implode(',', array_map('intval', $ids));
      $gameFilter = " AND game_creator_idplayer IN ({$inList})";
      $avatarFilter = " AND idplayer IN ({$inList})";
    }
    return "SELECT pid,
                   SUM(kind = 'gamename')      AS gamename_reports,
                   SUM(kind = 'avatar')        AS avatar_reports,
                   COUNT(*)                    AS total_reports,
                   SUM(state = 0)              AS open_reports,
                   COUNT(DISTINCT by_idplayer) AS distinct_reporters,
                   MAX(ts)                     AS last_report
            FROM (
              SELECT game_creator_idplayer AS pid, by_idplayer, state, timestamp AS ts, 'gamename' AS kind
                FROM reported_gamename WHERE game_creator_idplayer IS NOT NULL{$gameFilter}
              UNION ALL
              SELECT idplayer AS pid, by_idplayer, state, timestamp AS ts, 'avatar' AS kind
                FROM reported_avatar WHERE idplayer IS NOT NULL{$avatarFilter}
            ) r
            GROUP BY pid";
  }

  private function stateLabels()
  {
    return [
      self::STATE_NEW            => 'new',
      self::STATE_IGNORED        => 'ignored',
      self::STATE_CREATOR_WARNED => 'creator warned',
      self::STATE_CREATOR_BANNED => 'creator banned',
      self::STATE_REPORTER_SURE  => 'reporter confirmed',
      self::STATE_REPORTER_SPAM  => 'reporter spam',
    ];
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
