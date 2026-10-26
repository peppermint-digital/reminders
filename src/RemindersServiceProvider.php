<?php

namespace Peppermint\Reminders;

use Illuminate\Support\ServiceProvider;

class RemindersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reminders.php', 'reminders');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/reminders.php' => config_path('reminders.php'),
        ], 'reminders-config');

        // A product that already has its table adopts the package on top of it
        // and runs its own migrations — see `run_migrations` in the config.
        if (config('reminders.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
