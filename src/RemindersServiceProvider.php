<?php

namespace Peppermint\Reminders;

use Illuminate\Support\ServiceProvider;
use Peppermint\Reminders\Console\ErinnerungenZustellenCommand;
use Peppermint\Reminders\Console\GegenstandEntspannenCommand;
use Peppermint\Reminders\Delivery\NotificationsBridge;
use InvalidArgumentException;
use Peppermint\Reminders\Status\ReminderStatus;
use Peppermint\Reminders\Status\StatusRegistry;

class RemindersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reminders.php', 'reminders');

        $this->app->singleton(StatusRegistry::class, function ($app): StatusRegistry {
            $verzeichnis = new StatusRegistry;

            foreach ((array) $app['config']->get('reminders.statuses', []) as $klasse) {
                $eintrag = $app->make($klasse);

                // Die Pruefung ist ihre Zeilen wert: Ein Tippfehler in der
                // Konfiguration erzeugte sonst einen Status, der sich wie
                // einer anmeldet und keine Frage beantworten kann.
                if (! $eintrag instanceof ReminderStatus) {
                    throw new InvalidArgumentException(
                        "Angemeldeter Erinnerungs-Status [{$klasse}] erweitert ".ReminderStatus::class.' nicht.'
                    );
                }

                $verzeichnis->register($eintrag);
            }

            return $verzeichnis;
        });
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

        // Der Zustell-Befehl nur, wenn es etwas gibt, wohin zugestellt werden
        // koennte. `peppermint/notifications` ist ein `suggest` (Regel 7), und
        // ein Befehl, der ohne sie unweigerlich wirft, waere schlimmer als
        // keiner: Er stuende in `artisan list` und in einem Zeitplan, und das
        // Rot kaeme erst nachts.
        if ($this->app->runningInConsole()) {
            // Haengt an keinem anderen Paket: Es geht nur um eine Spalte in
            // der eigenen Tabelle.
            $this->commands([GegenstandEntspannenCommand::class]);
        }

        if ($this->app->runningInConsole() && NotificationsBridge::verfuegbar()) {
            $this->commands([ErinnerungenZustellenCommand::class]);
        }
    }
}
