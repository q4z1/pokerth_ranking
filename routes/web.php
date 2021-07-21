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

Route::get('/html/{title}', [App\Http\Controllers\HtmlBlockController::class, 'getBlock']);

Route::post('/gametable/show', [App\Http\Controllers\GameController::class, 'show_table']);

Route::get('/game/get', [App\Http\Controllers\GameController::class, 'get']);
Route::get('/game/log', [App\Http\Controllers\GameController::class, 'log']);

Route::post('/ranking/leaderboard', [App\Http\Controllers\PlayerController::class, 'getLeaderboard']);
Route::get('/ranking/cod', [App\Http\Controllers\GameController::class, 'getCOD']);

Route::post('/account/reset', [App\Http\Controllers\PlayerController::class, 'account_reset']);
Route::post('/account/create', [App\Http\Controllers\PlayerController::class, 'account_create']);
Route::post('/account/change', [App\Http\Controllers\PlayerController::class, 'account_change']);
Route::post('/account/validate', [App\Http\Controllers\PlayerController::class, 'account_validate']);
Route::post('/account/delete', [App\Http\Controllers\PlayerController::class, 'account_delete']);

Route::get('/downloads', [App\Http\Controllers\DownloadsController::class, 'files']);
Route::get('/styles', [App\Http\Controllers\DownloadsController::class, 'styles']);
