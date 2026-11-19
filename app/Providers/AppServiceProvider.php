<?php

namespace App\Providers;

use App\Services\AvatarBlacklistService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Singleton, damit die Blacklist pro Request nur einmal geladen wird –
        // der Player-Accessor fragt sie sonst für jede Zeile einer Liste neu ab.
        $this->app->singleton(AvatarBlacklistService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Admin-Login: Passwort-Raten bremsen. Bewusst nur per IP und nicht per
        // Nick – sonst könnte ein Angreifer den echten Admin aussperren.
        // Die IP ist dank real_ip (CF-Connecting-IP) in nginx die echte.
        RateLimiter::for('admin-login', function (Request $request) {
            $tooMany = fn () => response()->json(
                ['success' => false, 'msg' => 'Too many login attempts. Please try again later.'],
                429
            );
            return [
                Limit::perMinute(5)->by('min:' . $request->ip())->response($tooMany),
                Limit::perHour(30)->by('hour:' . $request->ip())->response($tooMany),
            ];
        });
    }
}
