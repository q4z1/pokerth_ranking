<?php

namespace App\Providers;

use App\Services\AvatarBlacklistService;
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
        //
    }
}
