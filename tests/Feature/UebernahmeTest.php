<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

/**
 * Die Tabelle, wie sie VOR diesem Paket aussah: eine Erinnerung haengt an
 * genau einer Art Sache, und die Spalte sagt das — `task_id`, echter
 * Fremdschluessel mit Kaskade.
 *
 * So liegt sie in Produktion: im CRM mit lebenden Zeilen, im Manager seit
 * 12/2025.
 */
function alteTabelle(): void
{
    Schema::create('task_reminders', function (Blueprint $blueprint) {
        $blueprint->id();
        $blueprint->foreignId('task_id')->constrained('vorgaenge')->cascadeOnDelete();
        $blueprint->unsignedBigInteger('user_id');
        $blueprint->dateTime('remind_at');
        $blueprint->string('title')->nullable();
        $blueprint->text('note')->nullable();
        $blueprint->boolean('is_dismissed')->default(false);
        $blueprint->dateTime('dismissed_at')->nullable();
        $blueprint->timestamps();
    });
}

function uebernahmeLaufenLassen(): void
{
    (require __DIR__.'/../../database/migrations/2026_10_26_000100_upgrade_adopted_reminders_table.php')->up();
}

function entspannenLaufenLassen(): void
{
    (require __DIR__.'/../../database/migrations/2026_10_26_000200_relax_legacy_subject_column.php')->up();
}

beforeEach(function () {
    alteTabelle();

    config()->set('reminders.tables.reminders', 'task_reminders');
    config()->set('reminders.legacy_subject', [
        'column' => 'task_id',
        'type' => Vorgang::class,
        'relax' => false,
    ]);

    $this->vorgang = Vorgang::create(['name' => 'Angebot Meier']);
});

it('ergaenzt die fehlenden Spalten und fuellt den Gegenstand nach', function () {
    DB::table('task_reminders')->insert([
        'task_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now()->subHour(),
        'title' => 'Nachfassen',
    ]);

    expect(Schema::hasColumn('task_reminders', 'subject_type'))->toBeFalse();

    uebernahmeLaufenLassen();

    foreach (['subject_type', 'subject_id', 'delivered_at', 'source'] as $spalte) {
        expect(Schema::hasColumn('task_reminders', $spalte))->toBeTrue("Spalte {$spalte} fehlt");
    }

    $zeile = DB::table('task_reminders')->first();
    expect($zeile->subject_type)->toBe(Vorgang::class);
    expect((int) $zeile->subject_id)->toBe($this->vorgang->getKey());

    // Und die alte Spalte behaelt ihren Wert — der Rueckweg bleibt offen.
    expect((int) $zeile->task_id)->toBe($this->vorgang->getKey());
    expect($zeile->title)->toBe('Nachfassen');
});

it('ist zweimal laufen lassen harmlos', function () {
    DB::table('task_reminders')->insert([
        'task_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now(),
    ]);

    uebernahmeLaufenLassen();
    $nachEinmal = DB::table('task_reminders')->get()->toArray();

    uebernahmeLaufenLassen();

    expect(DB::table('task_reminders')->get()->toArray())->toEqual($nachEinmal);
});

it('fasst eine Zeile nicht an, die schon einen Gegenstand hat', function () {
    // Wer die Uebernahme nach einer Teilwanderung erneut faehrt, darf keine
    // bereits gesetzte Zuordnung ueberschreiben.
    uebernahmeLaufenLassen();

    $andere = Vorgang::create(['name' => 'anderer']);

    DB::table('task_reminders')->insert([
        'task_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now(),
        'subject_type' => 'Etwas\\Anderes',
        'subject_id' => $andere->getKey(),
    ]);

    uebernahmeLaufenLassen();

    $zeile = DB::table('task_reminders')->first();
    expect($zeile->subject_type)->toBe('Etwas\\Anderes');
    expect((int) $zeile->subject_id)->toBe($andere->getKey());
});

it('tut nichts, wenn kein alter Gegenstand konfiguriert ist', function () {
    // Ein Produkt mit frischer Tabelle laesst `legacy_subject` leer. Dann
    // darf hier nichts geraten werden — genau die Vorsicht, an der eine
    // Migration einmal fast 4357 echte Kontakte geloescht hat.
    config()->set('reminders.legacy_subject', null);

    DB::table('task_reminders')->insert([
        'task_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now(),
    ]);

    uebernahmeLaufenLassen();

    expect(DB::table('task_reminders')->first()->subject_type)->toBeNull();
});

it('liest die uebernommene Tabelle anschliessend als Modell', function () {
    DB::table('task_reminders')->insert([
        'task_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now()->subHour(),
    ]);

    uebernahmeLaufenLassen();

    $erinnerung = Reminder::query()->active()->due()->undelivered()->first();

    expect($erinnerung)->not->toBeNull();
    expect($erinnerung->getTable())->toBe('task_reminders');
    expect($erinnerung->subject)->toBeInstanceOf(Vorgang::class);
});

it('entspannt die alte Spalte nur auf ausdrueckliche Ansage', function () {
    uebernahmeLaufenLassen();

    // Vorgabe ist false — ein Deploy darf das nicht aus Versehen ausloesen.
    entspannenLaufenLassen();

    DB::table('task_reminders')->insert([
        'task_id' => null,
        'user_id' => 7,
        'remind_at' => now(),
        'subject_type' => Vorgang::class,
        'subject_id' => $this->vorgang->getKey(),
    ]);
})->throws(Exception::class);

it('entspannt die alte Spalte, wenn sie eingeschaltet ist', function () {
    uebernahmeLaufenLassen();

    config()->set('reminders.legacy_subject.relax', true);
    entspannenLaufenLassen();

    // Jetzt darf eine Termin-Erinnerung ohne Aufgabe in dieselbe Tabelle.
    DB::table('task_reminders')->insert([
        'task_id' => null,
        'user_id' => 7,
        'remind_at' => now(),
        'subject_type' => 'Termin',
        'subject_id' => 1,
    ]);

    expect(DB::table('task_reminders')->whereNull('task_id')->count())->toBe(1);
});

it('verweigert das Entspannen, solange Zeilen ohne Gegenstand liegen', function () {
    // Sonst entstehen Zeilen, die auf keiner der beiden Referenzen mehr
    // auffindbar sind.
    uebernahmeLaufenLassen();

    DB::table('task_reminders')->insert([
        'task_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now(),
        'subject_type' => null,
        'subject_id' => null,
    ]);

    config()->set('reminders.legacy_subject.relax', true);

    entspannenLaufenLassen();
})->throws(RuntimeException::class, 'noch keinen Gegenstand');
