<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAudit;
use App\Services\ReportStats;

/**
 * Basis aller Admin-Backend-Controller: Login + aktive Admin-Rechte Pflicht.
 *
 * serverLog()/webServerLog() waren bis 2026-11-18 öffentlich (nur Aggregate).
 * Beim WebSocket-Angriff in der Nacht 17./18.11. haben die Angreifer sie als
 * Rückkanal benutzt, um die Wirkung ihrer Tests auf den Game-Server live zu
 * sehen - seitdem ist das gesamte Backend nur noch mit Login erreichbar. Falls
 * der Webclient-Adminbereich (narmod) Zahlen braucht: eigenes Token statt
 * offenem Endpunkt.
 */
abstract class AdminBaseController extends Controller
{
  public function __construct()
  {
    $this->middleware(['auth', 'admin']);
  }

  protected function audit(): AdminAudit
  {
    return app(AdminAudit::class);
  }

  protected function stats(): ReportStats
  {
    return app(ReportStats::class);
  }
}
