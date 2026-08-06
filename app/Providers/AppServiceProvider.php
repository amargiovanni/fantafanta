<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
        // Lazy loading e attributi scartati silenziosamente sono bug: falliscono forte fuori da produzione.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
