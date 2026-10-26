<?php

namespace Peppermint\Reminders\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Die alte Fremdschluessel-Spalte auf `nullable` setzen.
 *
 * ## Warum ein Befehl und keine Migration
 *
 * Hier stand bis zum 26.10.2026 eine Migration, und die war eine Falle.
 *
 * Sie war per Konfiguration abschaltbar (`legacy_subject.relax`) und stand
 * ausdruecklich auf „aus", weil dieser Schritt der einzige der Uebernahme
 * ist, der eine bestehende BEDINGUNG aendert statt etwas hinzuzufuegen — ein
 * Deploy darf das nicht aus Versehen tun. Genau das hat sie unmoeglich
 * gemacht: Beim ersten `migrate` tat sie (richtig) nichts und trug sich
 * (unvermeidlich) als erledigt ein. Danach konnte die Zustimmung nicht mehr
 * greifen, denn Laravel fuehrt eine eingetragene Migration nie wieder aus.
 *
 * Gefunden im CRM-DevStack: `relax` auf `true` gestellt, `php artisan
 * migrate` gelaufen, und `task_id` war weiter `not null`. Das Protokoll
 * zeigte die Migration in Stapel 10 — erledigt, ohne etwas getan zu haben.
 *
 * Ein Befehl hat das Problem nicht. Er laeuft, wenn ein Mensch ihn tippt,
 * beliebig oft, und er sagt jedes Mal, was er vorgefunden hat.
 *
 * ## Der Riegel bleibt
 *
 * `legacy_subject.relax` muss trotzdem `true` sein. Der Befehl ist die
 * Handlung, die Konfiguration die dauerhafte Aussage des Produkts — eine
 * frische Installation soll die Absicht nachlesen koennen und nicht aus einer
 * Befehlshistorie erschliessen muessen.
 */
class GegenstandEntspannenCommand extends Command
{
    protected $signature = 'erinnerungen:gegenstand-entspannen';

    protected $description = 'Setzt die alte Fremdschluessel-Spalte auf nullable, damit Erinnerungen ohne diesen Gegenstand moeglich werden.';

    public function handle(): int
    {
        $alt = config('reminders.legacy_subject');

        if (! is_array($alt) || blank($alt['column'] ?? null)) {
            $this->components->info('Keine alte Gegenstands-Spalte konfiguriert — nichts zu tun.');

            return self::SUCCESS;
        }

        $spalte = (string) $alt['column'];
        $tabelle = (string) config('reminders.tables.reminders', 'reminders');

        if (($alt['relax'] ?? false) !== true) {
            $this->components->error(
                "Abgelehnt: `reminders.legacy_subject.relax` steht nicht auf true. "
                ."Dieser Schritt aendert eine bestehende Bedingung an [{$tabelle}.{$spalte}] — "
                ."das Produkt muss ihn ausdruecklich erlauben."
            );

            return self::FAILURE;
        }

        if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, $spalte)) {
            $this->components->info("[{$tabelle}.{$spalte}] gibt es nicht — nichts zu tun.");

            return self::SUCCESS;
        }

        // Dieselbe Weigerung wie zuvor, und aus demselben Grund: Eine Zeile
        // ohne Gegenstand ist nach dem Entspannen ueber KEINE der beiden
        // Referenzen mehr auffindbar.
        $ohneGegenstand = DB::table($tabelle)->whereNull('subject_id')->count();

        if ($ohneGegenstand > 0) {
            $this->components->error(
                "Abgebrochen: {$ohneGegenstand} Zeile(n) in [{$tabelle}] haben noch keinen Gegenstand. "
                .'Erst `php artisan migrate` laufen lassen (die Uebernahme fuellt sie nach), dann diesen Befehl.'
            );

            return self::FAILURE;
        }

        if (! $this->istPflicht($tabelle, $spalte)) {
            $this->components->info("[{$tabelle}.{$spalte}] ist schon nullable — nichts zu tun.");

            return self::SUCCESS;
        }

        Schema::table($tabelle, function (Blueprint $blueprint) use ($spalte) {
            $blueprint->unsignedBigInteger($spalte)->nullable()->change();
        });

        $this->components->info("[{$tabelle}.{$spalte}] ist jetzt nullable.");

        return self::SUCCESS;
    }

    /**
     * Steht die Spalte noch auf `NOT NULL`?
     *
     * Ueber die Schema-Auskunft, nicht ueber einen Probe-Schreibvorgang: Eine
     * Zeile einzufuegen, um die Bedingung zu pruefen, hinterlaesst bei Erfolg
     * Muell und bei Misserfolg einen Eintrag im Fehlerprotokoll.
     */
    private function istPflicht(string $tabelle, string $spalte): bool
    {
        foreach (Schema::getColumns($tabelle) as $eintrag) {
            if (($eintrag['name'] ?? null) === $spalte) {
                return ($eintrag['nullable'] ?? true) === false;
            }
        }

        return false;
    }
}
