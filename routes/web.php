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
Route::post('/player/gender-country', [App\Http\Controllers\PlayerController::class, 'set_gender_country']);
Route::get('/player/gender-country', [App\Http\Controllers\PlayerController::class, 'get_gender_country']);
Route::get('/player/games/get', [App\Http\Controllers\PlayerController::class, 'games']);
Route::get('/player/season/get/{player}/{season}', [App\Http\Controllers\PlayerController::class, 'getSeason']);
Route::get('/player/season/get/leaderboard/{season}', [App\Http\Controllers\PlayerController::class, 'getSeasonLeaderboard']);

Route::post('/login', [App\Http\Controllers\AdminController::class, 'login']);
Route::get('/logout', [App\Http\Controllers\AdminController::class, 'logout']);

Route::post('/banlist/{player}', [App\Http\Controllers\AdminController::class, 'banlist']);
Route::get('/banlist', [App\Http\Controllers\AdminController::class, 'banlist']);
Route::post('/adverts', [App\Http\Controllers\AdminController::class, 'adverts']);
Route::get('/adverts', [App\Http\Controllers\AdminController::class, 'adverts']);
Route::get('/reports/offenders', [App\Http\Controllers\AdminController::class, 'offenders']);
// Muss vor /reports/{type} stehen, sonst greift dort das type-Pattern nicht mehr.
Route::get('/reports/avatar/image/{hash}', [App\Http\Controllers\AdminController::class, 'avatarImage'])->where('hash', '[0-9a-fA-F]{8,128}');
Route::get('/reports/{type}', [App\Http\Controllers\AdminController::class, 'reports'])->where('type', 'avatar|gamename');
Route::post('/reports/{type}', [App\Http\Controllers\AdminController::class, 'reportAction'])->where('type', 'avatar|gamename');

Route::get('/html/{title}', [App\Http\Controllers\HtmlBlockController::class, 'getBlock']);
Route::get('/a/{position}', [App\Http\Controllers\AdvertController::class, 'getAdverts']);


Route::post('/gametable/show', [App\Http\Controllers\GameController::class, 'show_table']);

Route::get('/game/get', [App\Http\Controllers\GameController::class, 'get']);
Route::get('/game/log', [App\Http\Controllers\GameController::class, 'log']);
Route::get('/game/pdb/{id}', [App\Http\Controllers\GameController::class, 'pdbDownload']);
Route::post('/game/pdb', [App\Http\Controllers\GameController::class, 'pdbUpload'])->middleware('throttle:10,1');

Route::post('/ranking/leaderboard/{season}', [App\Http\Controllers\PlayerController::class, 'getLeaderboard']);
Route::get('/ranking/cod', [App\Http\Controllers\GameController::class, 'getCOD']);

Route::post('/account/reset', [App\Http\Controllers\PlayerController::class, 'account_reset']);
Route::post('/account/create', [App\Http\Controllers\PlayerController::class, 'account_create']);
Route::post('/account/change', [App\Http\Controllers\PlayerController::class, 'account_change']);
Route::post('/account/validate', [App\Http\Controllers\PlayerController::class, 'account_validate']);
Route::post('/account/delete', [App\Http\Controllers\PlayerController::class, 'account_delete']);

Route::get('/downloads/1.1.2', [App\Http\Controllers\DownloadsController::class, 'oldfiles']);
Route::get('/downloads/2.0', [App\Http\Controllers\DownloadsController::class, 'currentfiles']);
Route::get('/downloads/all', [App\Http\Controllers\DownloadsController::class, 'allversions']);
Route::get('/downloads/tracker', [App\Http\Controllers\DownloadsController::class, 'tracker']);
Route::get('/styles', [App\Http\Controllers\DownloadsController::class, 'styles']);

Route::get('/teaser/weekly', [App\Http\Controllers\TeaserController::class, 'weekly']);
