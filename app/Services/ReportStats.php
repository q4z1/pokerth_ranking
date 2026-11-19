<?php

namespace App\Services;

use App\Models\ReportedAvatar;
use App\Models\ReportedGamename;
use Illuminate\Support\Facades\DB;

/**
 * Gemeinsame Report-Logik der Admin-Controller: Status-Konstanten, Zählungen
 * je Spieler (Wiederholungstäter), Spieler-Lookup und das Abschließen offener
 * Meldungen nach einem Ban.
 *
 * Archivierte Reports (Tabelle report_archive) zählen weiter mit – das
 * Archiv ist nur eine Ablage, keine Entlastung. Nur als "offen" gelten sie
 * nicht mehr.
 */
class ReportStats
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

  /** Diese Zustände gelten als Falschmeldung und zählen nicht als Vergehen. */
  public const UNCOUNTED_STATES = [self::STATE_IGNORED, self::STATE_REPORTER_SPAM];

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
  public const REPORT_TYPES = [
    'gamename' => ['model' => ReportedGamename::class, 'table' => 'reported_gamename', 'creator' => 'game_creator_idplayer'],
    'avatar'   => ['model' => ReportedAvatar::class,   'table' => 'reported_avatar',   'creator' => 'idplayer'],
  ];

  public function stateLabels(): array
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

  /** IDs archivierter Reports eines Typs als Set [id => true]. */
  public function archivedIds(string $type): array
  {
    return DB::table('report_archive')->where('type', $type)->pluck('report_id')
      ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
  }

  /** Setzt alle noch offenen Meldungen gegen einen Spieler auf den übergebenen Status. */
  public function resolveOpenReports($playerId, $state): int
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
  public function playerLookup(array $ids)
  {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!count($ids)) return collect();
    return DB::table('player')->whereIn('player_id', $ids)
      ->get(['player_id', 'username', 'active', 'created', 'last_login'])
      ->keyBy('player_id');
  }

  public function playerPayload($playerId, $players): ?array
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
  public function reportTotals(array $ids): array
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

  /** Aggregat je gemeldetem Spieler für die Offender-Liste. */
  public function offenderRows(): array
  {
    return DB::select($this->offenderSql());
  }

  /**
   * Die letzten Meldungen je Spieler (beide Report-Arten) – für den Tooltip
   * in der Spielerverwaltung.
   *
   * @return array<int, array<int, array{type:string, state:int, timestamp:string, game_name:?string, avatar_hash:?string}>>
   */
  public function recentReports(array $ids, int $limit = 5): array
  {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!count($ids)) return [];
    $inList = implode(',', $ids);
    $countable = implode(',', self::UNCOUNTED_STATES);
    $rows = DB::select(
      "SELECT pid, kind, state, ts, game_name, avatar_hash FROM (
         SELECT r.*, ROW_NUMBER() OVER (PARTITION BY pid ORDER BY ts DESC, id DESC) AS rn FROM (
           SELECT id, game_creator_idplayer AS pid, state, timestamp AS ts, 'gamename' AS kind,
                  game_name, NULL AS avatar_hash
             FROM reported_gamename WHERE game_creator_idplayer IN ({$inList}) AND state NOT IN ({$countable})
           UNION ALL
           SELECT id, idplayer AS pid, state, timestamp AS ts, 'avatar' AS kind,
                  NULL AS game_name, avatar_hash
             FROM reported_avatar WHERE idplayer IN ({$inList}) AND state NOT IN ({$countable})
         ) r
       ) x WHERE rn <= ? ORDER BY pid, ts DESC",
      [$limit]
    );
    $out = [];
    foreach ($rows as $row) {
      $out[(int) $row->pid][] = [
        'type'        => $row->kind,
        'state'       => (int) $row->state,
        'timestamp'   => $row->ts,
        'game_name'   => $row->game_name,
        'avatar_hash' => $row->avatar_hash,
      ];
    }
    return $out;
  }

  /**
   * Vereinigt beide Report-Tabellen und aggregiert je gemeldetem Spieler.
   * Optional auf eine Liste von Spieler-IDs eingeschränkt; die IDs werden als
   * Integer inlined, da PDO keine Array-Bindings für IN() kennt.
   */
  private function offenderSql(?array $ids = null): string
  {
    // Ignorierte Meldungen und Report-Spam waren Falschmeldungen und zählen
    // nicht gegen den Spieler (Badges, Offender-Liste, Wiederholungstäter).
    $countable = ' AND g.state NOT IN (' . implode(',', self::UNCOUNTED_STATES) . ')';
    $gameFilter = $countable;
    $avatarFilter = str_replace('g.state', 'a.state', $countable);
    if (!is_null($ids)) {
      $inList = implode(',', array_map('intval', $ids));
      $gameFilter .= " AND g.game_creator_idplayer IN ({$inList})";
      $avatarFilter .= " AND a.idplayer IN ({$inList})";
    }
    // Offen = Status "new" und nicht archiviert.
    return "SELECT pid,
                   SUM(kind = 'gamename')               AS gamename_reports,
                   SUM(kind = 'avatar')                 AS avatar_reports,
                   COUNT(*)                             AS total_reports,
                   SUM(state = 0 AND archived = 0)      AS open_reports,
                   COUNT(DISTINCT by_idplayer)          AS distinct_reporters,
                   MAX(ts)                              AS last_report
            FROM (
              SELECT g.game_creator_idplayer AS pid, g.by_idplayer, g.state, g.timestamp AS ts, 'gamename' AS kind,
                     (ra.report_id IS NOT NULL) AS archived
                FROM reported_gamename g
                LEFT JOIN report_archive ra ON ra.type = 'gamename' AND ra.report_id = g.id
               WHERE g.game_creator_idplayer IS NOT NULL{$gameFilter}
              UNION ALL
              SELECT a.idplayer AS pid, a.by_idplayer, a.state, a.timestamp AS ts, 'avatar' AS kind,
                     (ra.report_id IS NOT NULL) AS archived
                FROM reported_avatar a
                LEFT JOIN report_archive ra ON ra.type = 'avatar' AND ra.report_id = a.id
               WHERE a.idplayer IS NOT NULL{$avatarFilter}
            ) r
            GROUP BY pid";
  }
}
