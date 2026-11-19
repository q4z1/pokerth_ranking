<?php

namespace App\Http\Controllers\Admin;

use App\Models\Player;
use App\Services\AvatarBlacklistService;
use App\Services\GameServerAdminClient;
use App\Services\GeoIpService;
use App\Services\ReportStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spielerverwaltung: Suche, Online-Liste, Detailansicht, Nicks je IP und die
 * Live-Lobby für die Tooltips. Nur lesend – Eingriffe laufen über den
 * ModerationController.
 */
class PlayersController extends AdminBaseController
{
  /** Sortierbare Spalten der Spielersuche → SQL. */
  private const PLAYER_SORTS = [
    'player_id'  => ['p.player_id'],
    'username'   => ['p.username'],
    'last_login' => ['p.last_login'],
    'created'    => ['p.created'],
    // Numerisch statt als Text: erst IPv4 vor IPv6, dann nach Adresse.
    'last_ip'    => ['LENGTH(INET6_ATON(p.last_ip))', 'INET6_ATON(p.last_ip)'],
    'online'     => ['o.connected_at'],
  ];

  /**
   * Spielersuche fürs Backend, serverseitig gefiltert, sortiert und geblättert.
   *
   * Filter: q (Nick-Teilstring oder exakte ID), ip (Präfix, volle Adresse oder
   * IPv4-CIDR), ip_scope (last = player.last_ip, any = jede IP aus
   * server_session), status (all|active|banned), online (1 = nur online).
   * Online = offene Session im aktuellen Server-Run.
   */
  public function players(Request $request)
  {
    $perPage = max(10, min(250, (int) $request->query('per_page', 50)));
    $page = max(1, (int) $request->query('page', 1));
    $sort = $request->query('sort', 'last_login');
    $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
    if (!isset(self::PLAYER_SORTS[$sort])) $sort = 'last_login';

    $query = DB::table('player as p')
      ->leftJoinSub($this->onlineSessionsQuery(), 'o', 'o.player_id', '=', 'p.player_id');
    $query->whereNotIn('p.player_id', $this->botIds());

    $q = trim((string) $request->query('q', ''));
    if ($q !== '') {
      $like = '%' . addcslashes($q, '%_\\') . '%';
      $query->where(function ($w) use ($q, $like) {
        $w->where('p.username', 'LIKE', $like);
        if (ctype_digit($q)) $w->orWhere('p.player_id', (int) $q);
      });
    }

    $ip = trim((string) $request->query('ip', ''));
    if ($ip !== '') {
      if (!preg_match('#^[0-9a-fA-F:.]+(/\d{1,2})?$#', $ip)) {
        return ['success' => false, 'msg' => 'Invalid IP filter (use a prefix, full address or IPv4 CIDR).'];
      }
      if ($request->query('ip_scope', 'last') === 'any') {
        $query->whereIn('p.player_id', $this->sessionIpFilter($ip)->select('player_id'));
      } else {
        $this->applyLastIpFilter($query, $ip);
      }
    }

    $status = $request->query('status', 'all');
    if ($status === 'banned') $query->where('p.active', ReportStats::PLAYER_BANNED);
    else if ($status === 'active') $query->where('p.active', '<>', ReportStats::PLAYER_BANNED);
    if ($request->boolean('online')) $query->whereNotNull('o.player_id');

    $total = (clone $query)->count();
    foreach (self::PLAYER_SORTS[$sort] as $expr) {
      // NULLs immer ans Ende, sonst stehen bei DESC/ASC leere Werte vorn.
      $query->orderByRaw("{$expr} IS NULL")->orderByRaw("{$expr} {$dir}");
    }
    $query->orderBy('p.player_id', 'DESC');

    $rows = $query->forPage($page, $perPage)->get([
      'p.player_id', 'p.username', 'p.created', 'p.last_login', 'p.last_ip', 'p.active', 'p.country_iso',
      'o.connected_at as online_since', 'o.ip as online_ip',
      DB::raw('p.shadow_mute_until > NOW() AS muted'),
    ]);

    $ids = $rows->pluck('player_id')->all();
    $totals = $this->stats()->reportTotals($ids);
    $recent = $this->stats()->recentReports(array_keys($totals));
    $shared = $this->sharedIpCounts($rows->pluck('last_ip')->filter()->unique()->values()->all());
    $geo = app(GeoIpService::class)->lookupMany($rows->pluck('last_ip')->merge($rows->pluck('online_ip')));

    return [
      'success' => true,
      'geoip'   => app(GeoIpService::class)->available(),
      'total'   => $total,
      'page'    => $page,
      'per_page' => $perPage,
      'online_total' => DB::query()->fromSub($this->onlineSessionsQuery(), 'o')->count(),
      'list' => $rows->map(function ($row) use ($totals, $recent, $shared, $geo) {
        return [
          'player_id'     => (int) $row->player_id,
          'username'      => $row->username,
          'created'       => $row->created,
          'last_login'    => $row->last_login,
          'last_ip'       => $row->last_ip ?: null,
          'country'       => $row->country_iso ?: null,
          'banned'        => (int) $row->active === ReportStats::PLAYER_BANNED,
          'muted'         => (bool) $row->muted,
          'online'        => !is_null($row->online_since),
          'online_since'  => $row->online_since,
          'online_ip'     => $row->online_ip,
          'geo'           => $geo[$row->last_ip ?? ''] ?? null,
          'online_geo'    => $geo[$row->online_ip ?? ''] ?? null,
          'total_reports' => $totals[$row->player_id]['total'] ?? 0,
          'reports'       => $totals[$row->player_id] ?? null,
          'recent_reports' => $recent[$row->player_id] ?? [],
          'ip_accounts'   => $row->last_ip ? ($shared[$row->last_ip] ?? 0) : 0,
        ];
      })->values(),
    ];
  }

  /**
   * Gerade verbundene Sessions inklusive Gäste – für den Kickban von Spielern
   * ohne Account bzw. als Live-Übersicht.
   */
  public function onlinePlayers(Request $request)
  {
    $rows = DB::table('server_session as s')
      ->leftJoin('player as p', 'p.player_id', '=', 's.player_id')
      ->where('s.run_id', DB::raw('(SELECT MAX(run_id) FROM server_run)'))
      ->whereNull('s.disconnected_at')
      ->where(fn ($w) => $w->whereNull('s.player_id')->orWhereNotIn('s.player_id', $this->botIds()))
      ->orderBy('s.connected_at', 'DESC')
      ->get([
        's.session_id', 's.player_id', 's.nick', 's.is_guest', 's.client_platform', 's.client_build_id',
        's.connected_at', 's.last_game_id', DB::raw('INET6_NTOA(s.ip) AS ip'),
        'p.active', 'p.last_login',
      ]);
    $shared = $this->sharedIpCounts($rows->pluck('ip')->filter()->unique()->values()->all());
    $geo = app(GeoIpService::class)->lookupMany($rows->pluck('ip'));

    return [
      'success' => true,
      'geoip'   => app(GeoIpService::class)->available(),
      'list' => $rows->map(function ($row) use ($shared, $geo) {
        return [
          'session_id'   => (int) $row->session_id,
          'player_id'    => $row->player_id ? (int) $row->player_id : null,
          'username'     => $row->nick,
          'guest'        => (bool) $row->is_guest,
          'platform'     => (int) $row->client_platform,
          'build_id'     => (int) $row->client_build_id,
          'online_since' => $row->connected_at,
          'game_id'      => $row->last_game_id ? (int) $row->last_game_id : null,
          'ip'           => $row->ip,
          'geo'          => $geo[$row->ip ?? ''] ?? null,
          'banned'       => (int) $row->active === ReportStats::PLAYER_BANNED,
          'ip_accounts'  => $row->ip ? ($shared[$row->ip] ?? 0) : 0,
        ];
      })->values(),
    ];
  }

  /**
   * Details zu einem Spieler: letzte Sessions, benutzte IPs und andere
   * Accounts, die sich dieselben IPs geteilt haben (Zweitaccounts / Bann-Umgehung).
   */
  public function playerDetail(Request $request, Player $player)
  {
    $id = (int) $player->player_id;
    $sessions = DB::table('server_session')->where('player_id', $id)
      ->orderBy('connected_at', 'DESC')->limit(50)
      ->get([
        'session_id', 'nick', 'client_platform', 'client_build_id', 'connected_at', 'disconnected_at',
        'duration_s', 'close_reason', 'last_game_id', DB::raw('INET6_NTOA(ip) AS ip'),
      ]);

    $ips = DB::table('server_session')->where('player_id', $id)->whereNotNull('ip')
      ->groupBy('ip')->orderByRaw('MAX(connected_at) DESC')->limit(50)
      ->get([
        DB::raw('INET6_NTOA(ip) AS ip'), DB::raw('COUNT(*) AS sessions'),
        DB::raw('MIN(connected_at) AS first_seen'), DB::raw('MAX(connected_at) AS last_seen'),
      ]);
    $ipList = $ips->pluck('ip')->filter()->all();
    if ($player->last_ip && !in_array($player->last_ip, $ipList, true)) $ipList[] = $player->last_ip;

    $related = collect();
    if ($ipList) {
      $bin = implode(',', array_fill(0, count($ipList), 'INET6_ATON(?)'));
      $related = collect(DB::select(
        "SELECT p.player_id, p.username, p.active, p.last_login,
                GROUP_CONCAT(DISTINCT x.ip ORDER BY x.ip SEPARATOR ', ') AS ips
           FROM (
             SELECT s.player_id, INET6_NTOA(s.ip) AS ip FROM server_session s
              WHERE s.ip IN ({$bin}) AND s.player_id IS NOT NULL AND s.player_id <> ?
             UNION
             SELECT player_id, last_ip FROM player WHERE last_ip IN (" . implode(',', array_fill(0, count($ipList), '?')) . ") AND player_id <> ?
           ) x JOIN player p ON p.player_id = x.player_id
          WHERE p.player_id NOT IN (" . $this->botPlaceholders() . ")
          GROUP BY p.player_id, p.username, p.active, p.last_login
          ORDER BY p.last_login DESC LIMIT 100",
        array_merge($ipList, [$id], $ipList, [$id], $this->botIds())
      ));
    }

    $row = DB::table('player as p')
      ->leftJoinSub($this->onlineSessionsQuery(), 'o', 'o.player_id', '=', 'p.player_id')
      ->where('p.player_id', $id)
      ->first(['p.*', 'o.connected_at as online_since', 'o.ip as online_ip', DB::raw('p.shadow_mute_until > NOW() AS muted')]);
    $totals = $this->stats()->reportTotals([$id])[$id] ?? ['gamename' => 0, 'avatar' => 0, 'total' => 0, 'open' => 0];
    $geo = app(GeoIpService::class)->lookupMany(
      $sessions->pluck('ip')->merge($ips->pluck('ip'))->push($row->last_ip)->push($row->online_ip)
    );
    $shared = $this->sharedIpCounts(array_values(array_unique(array_filter($ipList))));

    return [
      'success' => true,
      'player' => [
        'player_id'    => $id,
        'username'     => $row->username,
        'created'      => $row->created,
        'last_login'   => $row->last_login,
        'last_ip'      => $row->last_ip ?: null,
        'geo'          => $geo[$row->last_ip ?? ''] ?? null,
        'country'      => $row->country_iso ?: null,
        'banned'       => (int) $row->active === ReportStats::PLAYER_BANNED,
        'muted'        => (bool) $row->muted,
        'online'       => !is_null($row->online_since),
        'online_since' => $row->online_since,
        'online_ip'    => $row->online_ip,
        'online_geo'   => $geo[$row->online_ip ?? ''] ?? null,
        'ip_accounts'  => $row->online_ip ? ($shared[$row->online_ip] ?? 0) : ($row->last_ip ? ($shared[$row->last_ip] ?? 0) : 0),
        'reports'      => $totals,
        'avatar_hash'  => $row->avatar_hash ?: null,
        'avatar_blacklisted' => app(AvatarBlacklistService::class)->isBlacklisted($row->avatar_hash ?: null),
        'recent_reports' => $totals['total'] ? ($this->stats()->recentReports([$id])[$id] ?? []) : [],
      ],
      'sessions' => $sessions->map(fn ($s) => [
        'session_id'      => (int) $s->session_id,
        'nick'            => $s->nick,
        'platform'        => (int) $s->client_platform,
        'build_id'        => (int) $s->client_build_id,
        'connected_at'    => $s->connected_at,
        'disconnected_at' => $s->disconnected_at,
        'duration_s'      => is_null($s->duration_s) ? null : (int) $s->duration_s,
        'close_reason'    => $s->close_reason,
        'game_id'         => $s->last_game_id ? (int) $s->last_game_id : null,
        'ip'              => $s->ip,
        'geo'             => $geo[$s->ip ?? ''] ?? null,
      ])->values(),
      'ips' => $ips->map(fn ($r) => [
        'ip' => $r->ip, 'sessions' => (int) $r->sessions, 'first_seen' => $r->first_seen, 'last_seen' => $r->last_seen,
        'geo' => $geo[$r->ip ?? ''] ?? null, 'accounts_14d' => $shared[$r->ip] ?? 0,
      ])->values(),
      'related' => $related->map(fn ($r) => [
        'player_id'  => (int) $r->player_id,
        'username'   => $r->username,
        'banned'     => (int) $r->active === ReportStats::PLAYER_BANNED,
        'last_login' => $r->last_login,
        'ips'        => $r->ips,
      ])->values(),
    ];
  }

  /**
   * Tisch- und Spielernamen für die Tooltips der Live-Leiste. Quelle ist die
   * Lobby, die der Admin-Bot nach dem Login bekommt; ein Login je Minute
   * reicht, daher gecacht (Fehler nur kurz, damit der nächste Hover neu
   * versucht).
   */
  public function liveLobby()
  {

    $key = 'live_stats.lobby';
    if (($cached = Cache::get($key)) !== null) return $cached;

    $result = app(GameServerAdminClient::class)->lobbySnapshot();
    $games = collect($result['games'] ?? [])
      ->sortBy(fn ($g) => [$g['running'] ? 0 : 1, mb_strtolower($g['name'])])
      ->values();
    $gameNames = $games->pluck('name', 'id');
    $players = collect($result['players'] ?? [])
      ->map(fn ($p) => $p + ['game' => $p['game_id'] !== null ? ($gameNames[$p['game_id']] ?? null) : null])
      ->sortBy(fn ($p) => mb_strtolower($p['name']))
      ->values();

    $payload = [
      'success' => $result['ok'],
      'msg'     => $result['ok'] ? null : $result['msg'],
      'games'   => $games->all(),
      'players' => $players->all(),
      'fetched' => now()->toIso8601String(),
    ];
    Cache::put($key, $payload, $result['ok'] ? 60 : 15);
    return $payload;
  }

  /**
   * Alle registrierten Nicks (ohne Gäste), die unter einer IP gespielt haben – Grundlage
   * der "IP shared"-Zahl. Sessions gibt es seit Beginn des Activity-Logs
   * (2026-08-05); Accounts, deren last_ip passt, aber ohne Session, kommen dazu.
   */
  public function ipNicks(Request $request)
  {
    $ip = trim((string) $request->query('ip', ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['success' => false, 'msg' => 'Invalid IP.'];

    $rows = DB::select(
      "SELECT s.nick, s.player_id, MAX(s.is_guest) AS guest, COUNT(*) AS sessions,
              MIN(s.connected_at) AS first_seen, MAX(s.connected_at) AS last_seen,
              MAX(s.connected_at > NOW() - INTERVAL 14 DAY) AS recent,
              MAX(s.disconnected_at IS NULL AND s.run_id = (SELECT MAX(run_id) FROM server_run)) AS online,
              p.active
         FROM server_session s LEFT JOIN player p ON p.player_id = s.player_id
        WHERE s.ip = INET6_ATON(?) AND s.is_guest = 0 AND s.player_id NOT IN (" . $this->botPlaceholders() . ")
        GROUP BY s.nick, s.player_id, p.active
        ORDER BY last_seen DESC
        LIMIT 500",
      array_merge([$ip], $this->botIds())
    );
    $seen = array_filter(array_map(fn ($r) => $r->player_id, $rows));
    $lastIpOnly = DB::table('player')->where('last_ip', $ip)
      ->when($seen, fn ($q) => $q->whereNotIn('player_id', $seen))
      ->limit(200)->get(['player_id', 'username', 'last_login', 'active']);

    $list = array_map(fn ($r) => [
      'nick'       => $r->nick,
      'player_id'  => $r->player_id ? (int) $r->player_id : null,
      'guest'      => (bool) $r->guest,
      'sessions'   => (int) $r->sessions,
      'first_seen' => $r->first_seen,
      'last_seen'  => $r->last_seen,
      'recent'     => (bool) $r->recent,
      'online'     => (bool) $r->online,
      'banned'     => (int) $r->active === ReportStats::PLAYER_BANNED,
    ], $rows);
    foreach ($lastIpOnly as $p) {
      $list[] = [
        'nick' => $p->username, 'player_id' => (int) $p->player_id, 'guest' => false, 'sessions' => 0,
        'first_seen' => null, 'last_seen' => $p->last_login, 'recent' => false, 'online' => false,
        'banned' => (int) $p->active === ReportStats::PLAYER_BANNED,
      ];
    }

    return [
      'success' => true,
      'ip'      => $ip,
      'geo'     => app(GeoIpService::class)->lookup($ip),
      'list'    => $list,
    ];
  }

  /** Bot-Konten neben dem Admin-Bot (PTH_ADMIN_BOT_NICK), die ebenfalls ausgeblendet werden. */
  private const BOT_NICKS = ['bbcbot'];

  /**
   * player_ids der Bot-Konten (Admin-Bot + BOT_NICKS). Ihre Logins sind für
   * die Moderation irrelevant und bleiben daher aus Spielerliste,
   * Online-Liste, IP-Zählern und verwandten Accounts draußen. Nie leer
   * ([0] als Platzhalter), damit NOT IN (...) gültig bleibt.
   *
   * @return int[]
   */
  private function botIds(): array
  {
    static $ids = null;
    if ($ids !== null) return $ids;
    $nicks = array_values(array_unique(array_filter(
      array_merge([(string) config('services.game_server_admin.nick')], self::BOT_NICKS)
    )));
    $ids = Cache::remember('bot_player_ids.' . md5(implode("\n", $nicks)), 3600, fn () =>
      DB::table('player')->whereIn('username', $nicks)->pluck('player_id')->map(fn ($v) => (int) $v)->all()
    ) ?: [0];
    return $ids;
  }

  private function botPlaceholders(): string
  {
    return implode(',', array_fill(0, count($this->botIds()), '?'));
  }

  /** Offene Sessions des laufenden Server-Runs, je Account die jüngste. */
  private function onlineSessionsQuery()
  {
    return DB::table('server_session')
      ->where('run_id', DB::raw('(SELECT MAX(run_id) FROM server_run)'))
      ->whereNull('disconnected_at')
      ->whereNotNull('player_id')
      ->whereNotIn('player_id', $this->botIds())
      ->groupBy('player_id')
      ->select('player_id', DB::raw('MAX(connected_at) AS connected_at'), DB::raw('INET6_NTOA(MAX(ip)) AS ip'));
  }

  /** Grenzen eines IPv4-CIDR als [von, bis] in Punktnotation, sonst null. */
  private function cidrRange(string $ip): ?array
  {
    if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $ip, $m)) return null;
    $base = ip2long($m[1]);
    $bits = (int) $m[2];
    if ($base === false || $bits > 32) return null;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
    return [long2ip($base & $mask), long2ip(($base & $mask) | (~$mask & 0xFFFFFFFF))];
  }

  private function applyLastIpFilter($query, string $ip): void
  {
    if ($range = $this->cidrRange($ip)) {
      $query->whereRaw('INET_ATON(p.last_ip) BETWEEN INET_ATON(?) AND INET_ATON(?)', $range);
    } else if (filter_var($ip, FILTER_VALIDATE_IP)) {
      $query->where('p.last_ip', $ip);
    } else {
      $query->where('p.last_ip', 'LIKE', addcslashes($ip, '%_\\') . '%');
    }
  }

  /** Sessions, deren IP zum Filter passt – volle Adresse und CIDR nutzen den Index. */
  private function sessionIpFilter(string $ip)
  {
    $q = DB::table('server_session')->whereNotNull('player_id')
      ->whereNotIn('player_id', $this->botIds());
    if ($range = $this->cidrRange($ip)) {
      $q->whereRaw('ip BETWEEN INET6_ATON(?) AND INET6_ATON(?)', $range)->whereRaw('LENGTH(ip) = 4');
    } else if (filter_var($ip, FILTER_VALIDATE_IP)) {
      $q->whereRaw('ip = INET6_ATON(?)', [$ip]);
    } else {
      $q->whereRaw('INET6_NTOA(ip) LIKE ?', [addcslashes($ip, '%_\\') . '%']);
    }
    return $q->distinct();
  }

  /**
   * Wie viele verschiedene Accounts in den letzten 14 Tagen unter jeder IP
   * unterwegs waren. Gäste zählen nicht mit – für die Moderation irrelevant. Der Kickban sperrt die IP 24 h – bei geteilten IPs
   * (CGNAT, VPN, Uni) trifft das auch Unbeteiligte.
   *
   * @return array<string,int>
   */
  private function sharedIpCounts(array $ips): array
  {
    $ips = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP)));
    if (!$ips) return [];
    $in = implode(',', array_fill(0, count($ips), 'INET6_ATON(?)'));
    $rows = DB::select(
      "SELECT INET6_NTOA(ip) AS ip, COUNT(DISTINCT nick) AS n FROM server_session
        WHERE ip IN ({$in}) AND is_guest = 0 AND connected_at > NOW() - INTERVAL 14 DAY
          AND (player_id IS NULL OR player_id NOT IN (" . $this->botPlaceholders() . ")) GROUP BY ip",
      array_merge($ips, $this->botIds())
    );
    $out = [];
    foreach ($rows as $r) $out[$r->ip] = (int) $r->n;
    return $out;
  }
}
