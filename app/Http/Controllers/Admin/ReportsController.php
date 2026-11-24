<?php

namespace App\Http\Controllers\Admin;

use App\Models\ReportedAvatar;
use App\Services\AvatarBlacklistService;
use App\Services\CollusionDetector;
use App\Services\ReportStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Gemeldete Spielnamen und Avatare, Offender-Liste, Unseen-Badge.
 */
class ReportsController extends AdminBaseController
{
  /**
   * Liste aller Reports eines Typs – inklusive Spieler-Daten und der Anzahl
   * bisheriger Meldungen des gemeldeten Spielers (Grundlage für Wiederholungs-Bans).
   * Archivierte Reports nur mit ?archived=1 (dann ausschließlich diese).
   */
  public function reports(Request $request, $type = null)
  {
    if (!isset(ReportStats::REPORT_TYPES[$type])) return ['success' => false, 'msg' => 'Unknown Type.'];
    $cfg = ReportStats::REPORT_TYPES[$type];
    $creatorColumn = $cfg['creator'];
    $stats = $this->stats();
    $showArchived = $request->boolean('archived');

    $archived = $stats->archivedIds($type);
    $rows = $cfg['model']::orderBy('timestamp', 'DESC')->get()
      ->filter(fn ($row) => isset($archived[(int) $row->id]) === $showArchived)
      ->values();

    $creatorIds = $rows->pluck($creatorColumn)->filter()->unique()->values();
    $reporterIds = $rows->pluck('by_idplayer')->filter()->unique()->values();
    $players = $stats->playerLookup($creatorIds->merge($reporterIds)->unique()->all());
    $offences = $stats->reportTotals($creatorIds->all());

    $blacklist = app(AvatarBlacklistService::class);

    $list = $rows->map(function ($row) use ($type, $creatorColumn, $players, $offences, $blacklist, $stats, $archived) {
      $creatorId = $row->{$creatorColumn};
      $counts = $offences[$creatorId] ?? ['gamename' => 0, 'avatar' => 0, 'total' => 0, 'open' => 0];
      return [
        'id'          => (int) $row->id,
        'type'        => $type,
        'state'       => (int) $row->state,
        'archived'    => isset($archived[(int) $row->id]),
        'timestamp'   => $row->timestamp,
        'game_name'   => $row->game_name ?? null,
        'game_idgame' => $row->game_idgame ?? null,
        'avatar_hash' => $row->avatar_hash ?? null,
        'avatar_type' => $row->avatar_type ?? null,
        'blacklisted' => $type === 'avatar' ? $blacklist->isBlacklisted($row->avatar_hash ?? null) : false,
        'creator'     => $stats->playerPayload($creatorId, $players),
        'reporter'    => $stats->playerPayload($row->by_idplayer, $players),
        'offences'    => $counts,
      ];
    });

    return [
      'success'        => true,
      'list'           => $list,
      'states'         => $stats->stateLabels(),
      'archived_count' => count($archived),
    ];
  }

  /**
   * Neue (noch nicht gesehene) offene Reports je Typ für den Admin – speist
   * das Badge im Menü. "Gesehen" wird pro Admin im Cache gemerkt (letzte
   * gesehene Report-ID je Typ); gilt also geräteübergreifend.
   */
  public function reportsUnseen(Request $request)
  {
    $out = [];
    foreach (ReportStats::REPORT_TYPES as $type => $cfg) {
      $seen = (int) Cache::get($this->reportsSeenKey($type), 0);
      $out[$type] = [
        'count'  => DB::table($cfg['table'] . ' as r')
          ->where('r.state', ReportStats::STATE_NEW)->where('r.id', '>', $seen)
          ->whereNotExists(fn ($q) => $q->from('report_archive as ra')
            ->where('ra.type', $type)->whereColumn('ra.report_id', 'r.id'))
          ->count(),
        'max_id' => (int) DB::table($cfg['table'])->max('id'),
      ];
    }
    return ['success' => true, 'types' => $out];
  }

  /**
   * Markiert Reports eines Typs bis einschließlich `upto` als gesehen. `upto`
   * kommt aus dem letzten reportsUnseen()-Poll, damit ein Report, der genau
   * währenddessen eingeht, nicht stillschweigend mit verschluckt wird.
   */
  public function reportsSeen(Request $request)
  {
    $type = $request->input('type');
    if (!isset(ReportStats::REPORT_TYPES[$type])) return ['success' => false, 'msg' => 'Unknown Type.'];
    $max = (int) DB::table(ReportStats::REPORT_TYPES[$type]['table'])->max('id');
    $upto = min(max(0, (int) $request->input('upto', $max)), $max);
    $key = $this->reportsSeenKey($type);
    if ($upto > (int) Cache::get($key, 0)) Cache::forever($key, $upto);
    return $this->reportsUnseen($request);
  }

  private function reportsSeenKey(string $type): string
  {
    return 'admin_reports_seen.' . Auth::id() . '.' . $type;
  }

  /**
   * Aggregierte Sicht: pro gemeldetem Spieler die Summe aller Meldungen über
   * beide Report-Arten hinweg. Das ist die Arbeitsgrundlage, um Wiederholungs-
   * täter zu bannen.
   */
  public function offenders(Request $request)
  {
    $min = max(1, (int) $request->input('min', 1));
    $stats = $this->stats();

    $rows = collect($stats->offenderRows());
    $players = $stats->playerLookup($rows->pluck('pid')->all());

    $list = $rows->filter(function ($row) use ($min) {
      return (int) $row->total_reports >= $min;
    })->map(function ($row) use ($players) {
      $player = $players[$row->pid] ?? null;
      return [
        'player_id'          => (int) $row->pid,
        'username'           => $player->username ?? null,
        'banned'             => $player ? ((int) $player->active === ReportStats::PLAYER_BANNED) : false,
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
   * Collusion / multi-seat detection: account rings of one person that sit
   * together at ranked tables. Logic in App\Services\CollusionDetector.
   */
  public function collusion(Request $request, CollusionDetector $detector)
  {
    $days = $request->input('days');
    $days = ($days === null || $days === '') ? null : max(1, (int) $days);

    $confidence = $request->input('confidence');
    $confidence = in_array($confidence, ['review', 'medium', 'high'], true) ? $confidence : null;

    $result = $detector->detect([
      'days'          => $days,
      'minTables'     => (int) $request->input('min_tables', 2),
      'ipMaxAccounts' => (int) $request->input('ip_max_accounts', 12),
      'limit'         => min(500, max(1, (int) $request->input('limit', 50))),
      'minConfidence' => $confidence,
      'player'        => $request->input('player'),
    ]);

    // PlayerRef expects an `exists` flag; every ring member is a real row.
    foreach ($result['rings'] as &$ring) {
      foreach ($ring['members'] as &$member) {
        $member['exists'] = true;
      }
      unset($member);
    }
    unset($ring);

    return [
      'success' => true,
      'rings'   => $result['rings'],
      'total'   => $result['total'],
      'params'  => $result['params'],
    ];
  }

  /**
   * Aktionen auf Reports: Status setzen, archivieren / wiederherstellen,
   * Avatar sperren. Hart gelöscht wird nicht mehr – die Wiederholungstäter-
   * Zählung beruht auf diesen Zeilen.
   */
  public function reportAction(Request $request, $type = null)
  {
    if (!isset(ReportStats::REPORT_TYPES[$type])) return ['success' => false, 'msg' => 'Unknown Type.'];
    $cfg = ReportStats::REPORT_TYPES[$type];

    $ids = $request->input('ids', []);
    if (is_string($ids)) $ids = array_filter(explode(',', $ids), 'strlen');
    $ids = array_values(array_unique(array_map('intval', (array) $ids)));
    // Nur IDs, die es wirklich gibt (sonst landen Phantome im Archiv/Audit).
    $ids = $cfg['model']::whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
    if (!count($ids)) return ['success' => false, 'msg' => 'No reports selected.'];

    $action = $request->input('action');
    $details = ['type' => $type, 'ids' => $ids];
    $target = $this->singleCreator($cfg, $ids);

    if ($action === 'archive') {
      $now = now()->format('Y-m-d H:i:s');
      $admin = Auth::user()->name ?? null;
      $affected = DB::table('report_archive')->insertOrIgnore(array_map(fn ($id) => [
        'type' => $type, 'report_id' => $id, 'archived_by' => $admin, 'archived_at' => $now,
      ], $ids));
      $this->audit()->log('report_archive', $target, $details + ['affected' => $affected]);
      return ['success' => true, 'msg' => $affected . ' report(s) archived.', 'affected' => $affected];
    }

    if ($action === 'unarchive') {
      $affected = DB::table('report_archive')->where('type', $type)->whereIn('report_id', $ids)->delete();
      $this->audit()->log('report_unarchive', $target, $details + ['affected' => $affected]);
      return ['success' => true, 'msg' => $affected . ' report(s) restored.', 'affected' => $affected];
    }

    if ($action === 'state') {
      $state = (int) $request->input('state', ReportStats::STATE_NEW);
      if (!in_array($state, ReportStats::STATES, true)) return ['success' => false, 'msg' => 'Unknown state.'];
      $affected = $cfg['model']::whereIn('id', $ids)->update(['state' => $state]);
      $this->audit()->log('report_state', $target, $details + [
        'state' => $this->stats()->stateLabels()[$state], 'affected' => $affected,
      ]);
      return ['success' => true, 'msg' => $affected . ' report(s) updated.', 'affected' => $affected];
    }

    if ($action === 'blacklist') {
      if ($type !== 'avatar') return ['success' => false, 'msg' => 'Only avatars can be blacklisted.'];
      $result = $this->blacklistAvatars($ids);
      $this->audit()->log('avatar_blacklist', $target, $details + [
        'hashes' => $result['hashes'] ?? [], 'msg' => $result['msg'],
      ], $result['success']);
      return $result;
    }

    return ['success' => false, 'msg' => 'Unknown action.'];
  }

  /** Ziel fürs Audit-Log, wenn alle gewählten Reports denselben Spieler betreffen. */
  private function singleCreator(array $cfg, array $ids): array
  {
    $creators = $cfg['model']::whereIn('id', $ids)->distinct()->pluck($cfg['creator'])->filter()->values();
    if ($creators->count() !== 1) return [];
    $pid = (int) $creators[0];
    return ['player_id' => $pid, 'name' => DB::table('player')->where('player_id', $pid)->value('username')];
  }

  /**
   * Sperrt die Avatare der angegebenen Reports: Eintrag in `avatar_blacklist`
   * (den auch der Game-Server auswertet) und Datei raus aus dem öffentlichen
   * Verzeichnis. Der Report-Status bleibt unberührt – Sperren und Abarbeiten
   * des Reports sind bewusst getrennte Entscheidungen.
   */
  private function blacklistAvatars(array $ids)
  {
    $service = app(AvatarBlacklistService::class);

    $hashes = ReportedAvatar::whereIn('id', $ids)->pluck('avatar_hash')
      ->filter()->map(fn($h) => strtolower(trim($h)))->unique()->values();
    if (!$hashes->count()) return ['success' => false, 'msg' => 'No avatar found for the selected report(s).'];

    $added = 0;
    $files = 0;
    $failed = [];
    foreach ($hashes as $hash) {
      try {
        $result = $service->blacklist($hash);
        if ($result['added']) $added++;
        $files += count($result['files']);
      } catch (\Exception $e) {
        $failed[] = $hash;
      }
    }

    $msg = $added . ' avatar(s) blacklisted, ' . $files . ' file(s) moved to quarantine.';
    if ($added < $hashes->count() - count($failed)) {
      $msg .= ' ' . ($hashes->count() - count($failed) - $added) . ' were already blacklisted.';
    }
    if (count($failed)) {
      $msg .= ' Failed: ' . implode(', ', $failed) . '.';
    }

    return [
      'success'     => count($failed) === 0,
      'msg'         => $msg,
      'blacklisted' => $added,
      'files'       => $files,
      'hashes'      => $hashes->all(),
    ];
  }

  /**
   * Liefert einen gemeldeten Avatar für die Admin-Oberfläche aus – auch dann
   * noch, wenn er bereits gesperrt und damit aus dem öffentlichen Verzeichnis
   * verschwunden ist. Ohne das wäre der Moderator nach dem Sperren blind.
   */
  public function avatarImage(Request $request, $hash)
  {
    $service = app(AvatarBlacklistService::class);

    $file = $service->publicFiles($hash)[0] ?? $service->quarantinedFile($hash);
    if (!$file || !is_file($file)) abort(404);

    return response()->file($file, ['Cache-Control' => 'private, max-age=300']);
  }
}
