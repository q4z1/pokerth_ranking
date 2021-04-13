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
Route::get('/player/games/get', [App\Http\Controllers\PlayerController::class, 'games']);
Route::post('/gametable/show', [App\Http\Controllers\GameController::class, 'show_table']);


Route::get('/game/get', [App\Http\Controllers\GameController::class, 'get']);
Route::post('/ranking/leaderboard', [App\Http\Controllers\PlayerController::class, 'getLeaderboard']);

Route::post('/account/reset', [App\Http\Controllers\PlayerController::class, 'account_reset']);
Route::post('/account/create', [App\Http\Controllers\PlayerController::class, 'account_create']);
Route::post('/account/change', [App\Http\Controllers\PlayerController::class, 'account_change']);
Route::post('/account/validate', [App\Http\Controllers\PlayerController::class, 'account_validate']);