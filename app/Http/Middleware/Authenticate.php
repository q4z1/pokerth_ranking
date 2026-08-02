<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // Das Backend hat keine eigene Login-Route – die Anmeldung passiert im
        // Vue-Frontend auf "/". Für alles andere gibt es einen 401.
        if (! $request->expectsJson()) {
            return url('/');
        }
    }
}
