<?php

namespace Peppermint\Reminders\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Peppermint\Reminders\RemindersServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        $anbieter = [RemindersServiceProvider::class];

        // Nur wenn es da ist — `peppermint/notifications` ist ein `suggest`,
        // kein `require` (Regel 7). Genauso entscheidet es sich im Produkt,
        // und so laeuft die Reihe auch in einer Installation ohne die
        // Entwicklungs-Abhaengigkeiten.
        if (class_exists(\Peppermint\Notifications\NotificationsServiceProvider::class)) {
            $anbieter[] = \Peppermint\Notifications\NotificationsServiceProvider::class;
        }

        return $anbieter;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite does not enforce foreign keys by default. Without this any
            // check on cascading passes vacuously while MySQL in the product
            // actually cascades — a test that cannot see the difference tests
            // nothing here.
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('reminders.user_model', Fixtures\Person::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (is_dir($meldungen = __DIR__.'/../vendor/peppermint/notifications/database/migrations')) {
            $this->loadMigrationsFrom($meldungen);
        }
    }

    /** The tables the fixtures live in — subjects of two different kinds. */
    protected function setUp(): void
    {
        parent::setUp();

        // So streng wie die Produkte: Zwei der vier aufnehmenden fahren
        // `preventLazyLoading`. Ohne das hier konnte diese Reihe eine ganze
        // Fehlerklasse nicht sehen — und hat sie am 26.10.2026 auch nicht
        // gesehen: Die Zustell-Bruecke griff lazy auf `$erinnerung->subject`
        // zu und flog im Peppermint Manager mitten im Lauf, nachdem die erste
        // Erinnerung schon vermerkt war. Gefunden hat es ein Durchlauf gegen
        // die laufende Anwendung, nicht 64 gruene Tests.
        Model::preventLazyLoading();

        foreach (['personen', 'vorgaenge', 'termine'] as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                Schema::create($tabelle, function (Blueprint $blueprint) {
                    $blueprint->id();
                    $blueprint->string('name')->nullable();
                    $blueprint->timestamps();
                });
            }
        }
    }
}
