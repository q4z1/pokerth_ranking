<?php

namespace App\Http\Controllers;

use App\Console\Commands\GameServerAttackCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Funde von `gameserver:attack-check` (storage/app/gameserver_alerts.jsonl)
 * für Logs → Game-Server Alerts und das Badge im Menü. "Gesehen" wird wie bei
 * den Reports pro Admin im Cache gehalten (höchste gesehene Alert-ID).
 * Enthält IPs und Nicks, daher nur mit Login.
 */
class GameServerAlertsController extends Controller
{
  public function __construct()
  {
    $this->middleware('auth');
  }

  public function index(Request $request)
  {
    $limit = max(10, min(1000, (int) $request->query('limit', 200)));
    $list = GameServerAttackCheck::readAlerts($limit);
    return ['success' => true, 'list' => $list] + $this->unseenInfo($list);
  }

  /** Nur Zähler fürs Badge - wird minütlich gepollt. */
  public function unseen()
  {
    return ['success' => true] + $this->unseenInfo(GameServerAttackCheck::readAlerts(200));
  }

  public function seen(Request $request)
  {
    $list = GameServerAttackCheck::readAlerts(200);
    $max = (int) ($list[0]['id'] ?? 0);
    $upto = min(max(0, (int) $request->input('upto', $max)), $max);
    if ($upto > (int) Cache::get($this->seenKey(), 0)) Cache::forever($this->seenKey(), $upto);
    return ['success' => true] + $this->unseenInfo($list);
  }

  private function unseenInfo(array $list): array
  {
    $seen = (int) Cache::get($this->seenKey(), 0);
    $new = array_filter($list, fn ($a) => ($a['id'] ?? 0) > $seen);
    return [
      'unseen'          => count($new),
      'unseen_critical' => count(array_filter($new, fn ($a) => ($a['severity'] ?? '') === 'critical')),
      'max_id'          => (int) ($list[0]['id'] ?? 0),
      'seen_id'         => $seen,
    ];
  }

  private function seenKey(): string
  {
    return 'admin_gameserver_alerts_seen.' . Auth::id();
  }
}
