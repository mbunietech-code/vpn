<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Everything (admin, emails, API messages) is English — owner's decision
        // 2026-10-11 — regardless of an older APP_LOCALE=sw left in .env.
        config(['app.locale' => 'en']);
        $this->app->setLocale('en');
    }
}
