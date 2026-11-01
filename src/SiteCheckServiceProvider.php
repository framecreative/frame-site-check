<?php

namespace Frame\SiteCheck;

use Illuminate\Support\ServiceProvider;

class SiteCheckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (is_file(base_path(Settings::FILE)) && $this->app->environment('site-check')) {
            // Site seeders read their accounts and options from config('site-check').
            $this->app['config']->set('site-check',Settings::load(base_path())->all());
        }
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Commands\PreflightCommand::class,
            Commands\ResetCommand::class,
            Commands\SeedCommand::class,
            Commands\FreshnessCommand::class,
            Commands\AlgoliaResetCommand::class,
        ]);
    }
}
