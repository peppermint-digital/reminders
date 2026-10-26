<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Leer. Der Schritt lebt in einem Befehl.
 *
 * ## Was hier stand, und warum es nicht gehen konnte
 *
 * Diese Migration sollte die alte Fremdschluessel-Spalte (`task_id`,
 * `ticket_id`) auf `nullable` setzen, damit auch eine Erinnerung ohne diesen
 * einen Gegenstand in der Tabelle leben kann.
 *
 * Sie war per `legacy_subject.relax` abschaltbar und stand ausdruecklich auf
 * „aus" — als einziger Schritt der Uebernahme aendert sie eine bestehende
 * BEDINGUNG statt etwas hinzuzufuegen, und ein Deploy darf das nicht aus
 * Versehen tun.
 *
 * Genau dieser Riegel hat sie nutzlos gemacht. Beim ersten `migrate` tat sie
 * richtigerweise nichts — und trug sich unvermeidlich als erledigt ein.
 * Danach konnte die Zustimmung nicht mehr greifen: Laravel fuehrt eine
 * eingetragene Migration nie wieder aus. Eine per Konfiguration abschaltbare
 * Migration ist eine Einmal-Zustimmung, die beim ersten Deploy verfaellt.
 *
 * Gemessen im CRM-DevStack am 26.10.2026: `relax` auf `true` gestellt,
 * `php artisan migrate` gelaufen, `task_id` weiter `not null`, und im
 * Protokoll stand diese Migration als Stapel 10 erledigt.
 *
 * ## Warum die Datei bleibt
 *
 * Sie ist in vier Produkten eingetragen. Ein `migrate:rollback`, der auf eine
 * eingetragene Migration ohne Datei stoesst, bricht ab — eine geloeschte
 * Datei waere also ein Problem fuer jemanden, der mit dieser Umstellung
 * nichts zu tun hat.
 *
 * ## Der Schritt heisst jetzt
 *
 *     php artisan erinnerungen:gegenstand-entspannen
 *
 * {@see \Peppermint\Reminders\Console\GegenstandEntspannenCommand}
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    /**
     * Leer, und das war sie auch vorher schon: Eine Spalte wieder auf
     * Pflicht zu setzen wuerde an genau den Zeilen scheitern, fuer die das
     * Entspannen gemacht wurde.
     */
    public function down(): void
    {
        //
    }
};
