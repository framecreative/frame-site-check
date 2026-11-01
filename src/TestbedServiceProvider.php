<?php

namespace Frame\Testbed;

use Illuminate\Support\ServiceProvider;

class TestbedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (is_file(base_path(Settings::FILE)) && $this->app->environment('testbed')) {
            // Site seeders read their accounts and options from config('testbed').
            $this->app['config']->set('testbed', Settings::load(base_path())->all());
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
