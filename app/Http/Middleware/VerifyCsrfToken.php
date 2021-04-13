<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        '/account/reset',
        '/account/change',
        '/account/create',
        '/account/validate',
        '/ranking/leaderboard',
        '/gametable/show'
    ];
}
