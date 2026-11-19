<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/player/autocomplete', [App\Http\Controllers\PlayerController::class, 'autocomplete']);
Route::get('/player/show', [App\Http\Controllers\PlayerController::class, 'show']);
Route::post('/player/search', [App\Http\Controllers\PlayerController::class, 'search']);
Route::get('/player/games/get', [App\Http\Controllers\PlayerController::class, 'games']);
Route::get('/player/season/get/{player}/{season}', [App\Http\Controllers\PlayerController::class, 'getSeason']);
Route::get('/player/season/get/leaderboard/{season}', [App\Http\Controllers\PlayerController::class, 'getSeasonLeaderboard']);

Route::post('/login', [App\Http\Controllers\Admin\AuthController::class, 'login'])->middleware('throttle:admin-login');
Route::get('/logout', [App\Http\Controllers\Admin\AuthController::class, 'logout']);

Route::post('/banlist/{player}', [App\Http\Controllers\Admin\ModerationController::class, 'banlist']);
Route::get('/banlist', [App\Http\Controllers\Admin\ModerationController::class, 'banlist']);
Route::post('/shadowmute/{player}', [App\Http\Controllers\Admin\ModerationController::class, 'shadowMute']);
Route::get('/shadowmute', [App\Http\Controllers\Admin\ModerationController::class, 'shadowMute']);
Route::post('/adverts', [App\Http\Controllers\Admin\AdvertsController::class, 'adverts']);
Route::get('/adverts', [App\Http\Controllers\Admin\AdvertsController::class, 'adverts']);
Route::get('/serverlog', [App\Http\Controllers\Admin\LogsController::class, 'serverLog']);
Route::get('/webserverlog', [App\Http\Controllers\Admin\LogsController::class, 'webServerLog']);
Route::get('/servermessages', [App\Http\Controllers\ServerMessagesController::class, 'index']);
Route::get('/servermessages/download', [App\Http\Controllers\ServerMessagesController::class, 'download'])->middleware('throttle:10,1');
Route::get('/gameserver/alerts', [App\Http\Controllers\GameServerAlertsController::class, 'index']);
Route::get('/gameserver/alerts/unseen', [App\Http\Controllers\GameServerAlertsController::class, 'unseen']);
Route::post('/gameserver/alerts/seen', [App\Http\Controllers\GameServerAlertsController::class, 'seen']);
Route::get('/lobbychat', [App\Http\Controllers\Admin\LogsController::class, 'lobbyChat']);
Route::get('/audit', [App\Http\Controllers\Admin\LogsController::class, 'auditLog']);
Route::post('/lobbychat/notice', [App\Http\Controllers\Admin\ModerationController::class, 'globalNotice'])->middleware('throttle:10,1');
Route::get('/players', [App\Http\Controllers\Admin\PlayersController::class, 'players']);
Route::get('/players/online', [App\Http\Controllers\Admin\PlayersController::class, 'onlinePlayers']);
Route::get('/players/ip', [App\Http\Controllers\Admin\PlayersController::class, 'ipNicks']);
Route::get('/players/{player}', [App\Http\Controllers\Admin\PlayersController::class, 'playerDetail'])->where('player', '[0-9]+');
Route::post('/players/kickban', [App\Http\Controllers\Admin\ModerationController::class, 'kickban'])->middleware('throttle:20,1');
Route::post('/players/message', [App\Http\Controllers\Admin\ModerationController::class, 'privateMessage'])->middleware('throttle:20,1');
Route::get('/reports/unseen', [App\Http\Controllers\Admin\ReportsController::class, 'reportsUnseen']);
Route::post('/reports/seen', [App\Http\Controllers\Admin\ReportsController::class, 'reportsSeen']);
Route::get('/reports/offenders', [App\Http\Controllers\Admin\ReportsController::class, 'offenders']);
// Muss vor /reports/{type} stehen, sonst greift dort das type-Pattern nicht mehr.
Route::get('/reports/avatar/image/{hash}', [App\Http\Controllers\Admin\ReportsController::class, 'avatarImage'])->where('hash', '[0-9a-fA-F]{8,128}');
Route::get('/reports/{type}', [App\Http\Controllers\Admin\ReportsController::class, 'reports'])->where('type', 'avatar|gamename');
Route::post('/reports/{type}', [App\Http\Controllers\Admin\ReportsController::class, 'reportAction'])->where('type', 'avatar|gamename');

Route::get('/live', [App\Http\Controllers\LiveStatsController::class, 'now']);
Route::get('/live/lobby', [App\Http\Controllers\Admin\PlayersController::class, 'liveLobby']);

Route::get('/html/{title}', [App\Http\Controllers\HtmlBlockController::class, 'getBlock']);
Route::get('/a/{position}', [App\Http\Controllers\AdvertController::class, 'getAdverts']);


Route::post('/gametable/show', [App\Http\Controllers\GameController::class, 'show_table']);

Route::get('/game/get', [App\Http\Controllers\GameController::class, 'get']);
Route::get('/game/log', [App\Http\Controllers\GameController::class, 'log']);
Route::get('/game/pdb/{id}', [App\Http\Controllers\GameController::class, 'pdbDownload']);
Route::post('/game/pdb', [App\Http\Controllers\GameController::class, 'pdbUpload'])->middleware('throttle:10,1');

Route::post('/ranking/leaderboard/{season}', [App\Http\Controllers\PlayerController::class, 'getLeaderboard']);
Route::get('/ranking/cod', [App\Http\Controllers\GameController::class, 'getCOD']);

// /account/{reset,create,change,validate,delete} removed 2026-11-19: unauthenticated legacy endpoints
// (pre phpBB extension); change/delete were abused after the leaderboard SQL injection.

Route::get('/downloads/all', [App\Http\Controllers\DownloadsController::class, 'allversions']);
Route::get('/styles', [App\Http\Controllers\DownloadsController::class, 'styles']);

// Das Header-Bild ist auf jeder Seite das LCP-Element und für alle Besucher
// dasselbe. Im web-Stack hängt daran eine Session: die Antwort trug XSRF-TOKEN
// und laravel_session als Set-Cookie, rund ein Kilobyte Header pro Abruf, und
// war damit für gemeinsame Caches und Proxies unbrauchbar. Nichts davon wird
// hier gebraucht – die Route liest nur eine Datei.
// ValidateCsrfToken ist der Name ab Laravel 11, VerifyCsrfToken die alte
// Ableitung davon; ein nicht zutreffender Eintrag wird schlicht ignoriert.
Route::get('/teaser/weekly', [App\Http\Controllers\TeaserController::class, 'weekly'])
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ]);
