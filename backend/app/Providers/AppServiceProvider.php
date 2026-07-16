<?php

namespace App\Providers;

use App\Game\GameTelemetry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // janken-game 用の OTel プロバイダを 1 リクエスト内で使い回す
        $this->app->singleton(GameTelemetry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
