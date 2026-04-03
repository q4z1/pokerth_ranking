<?php

use App\Console\Invokes\SeasonSwitch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            '/account/reset',
            '/account/change',
            '/account/create',
            '/account/validate',
            '/ranking/leaderboard/*',
            '/gametable/show',
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->call(new SeasonSwitch)->cron('0 0 01 */3 *');
        $schedule->command('attack:check')->cron('*/5 * * * *');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
