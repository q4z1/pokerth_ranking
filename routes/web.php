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
Route::get('/ranking/leaderboard', [App\Http\Controllers\PlayerController::class, 'getLeaderboard']);