<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
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

/**
 * Das Entspannen anstossen und den Rueckgabewert durchgeben.
 *
 * Fuehrt den BEFEHL aus, nicht mehr die Migration. Die Migration konnte es
 * nicht: Sie war per Konfiguration abschaltbar und trug sich beim ersten
 * `migrate` als erledigt ein, obwohl sie nichts getan hatte — danach greift
 * keine Zustimmung mehr.
 *
 * Der Befehl WIRFT nicht, sondern gibt einen Rueckgabewert und sagt, was er
 * vorgefunden hat. Deshalb pruefen die Tests hier den Wert und den Zustand
 * der Spalte, nicht eine Ausnahme.
 */
function entspannenLaufenLassen(): int
{
    return Artisan::call('erinnerungen:gegenstand-entspannen');
}

/** Steht die alte Spalte noch auf Pflicht? */
function alteSpalteIstPflicht(string $tabelle = 'task_reminders', string $spalte = 'task_id'): bool
{
    foreach (Schema::getColumns($tabelle) as $eintrag) {
        if (($eintrag['name'] ?? null) === $spalte) {
            return ($eintrag['nullable'] ?? true) === false;
        }
    }

    return false;
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

    // Vorgabe ist false — und der Befehl LEHNT AB statt stillschweigend
    // nichts zu tun. Genau diese Sichtbarkeit fehlte der Migration: Sie
    // konnte „ich darf nicht" nicht von „ich bin schon gelaufen"
    // unterscheiden.
    expect(entspannenLaufenLassen())->toBe(1)
        ->and(alteSpalteIstPflicht())->toBeTrue();

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

    expect(entspannenLaufenLassen())->toBe(0)
        ->and(alteSpalteIstPflicht())->toBeFalse();

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

    // Abgelehnt — und die Bedingung steht unveraendert. Waere sie gefallen,
    // waere diese Zeile ueber KEINE der beiden Referenzen mehr auffindbar.
    expect(entspannenLaufenLassen())->toBe(1)
        ->and(alteSpalteIstPflicht())->toBeTrue();
});

it('fuellt die alte Spalte mit, wenn ein Schreibweg nur den Gegenstand setzt', function () {
    // Eine uebernommene Tabelle fuehrt `task_id` als NOT NULL weiter, bis
    // jemand das Entspannen einschaltet. Der gemeinsame Abgleich schreibt aber
    // nur den Gegenstand — ohne diese Mitschrift laeuft er dort in eine
    // Verletzung der Bedingung. Gefunden am 26.10.2026: 17 Tests auf einen
    // Schlag.
    uebernahmeLaufenLassen();

    $erinnerung = Reminder::create([
        'subject_type' => Vorgang::class,
        'subject_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now(),
    ]);

    expect((int) $erinnerung->task_id)->toBe($this->vorgang->getKey());
});

it('schreibt die alte Spalte NICHT, wenn der Gegenstand eine andere Art ist', function () {
    // Eine Termin-Erinnerung hat keine Aufgabe. Ihr eine aufzuschreiben waere
    // schlimmer als die leere Spalte — sie zeigte auf eine fremde Zeile.
    config()->set('reminders.legacy_subject.relax', true);
    uebernahmeLaufenLassen();
    entspannenLaufenLassen();

    $erinnerung = Reminder::create([
        'subject_type' => 'App\\Models\\Termin',
        'subject_id' => 42,
        'user_id' => 7,
        'remind_at' => now(),
    ]);

    expect($erinnerung->task_id)->toBeNull();
});

/**
 * Den Rueckbau anstossen.
 *
 * Eigene Hilfe, weil keine Testreihe `down()` von sich aus aufruft:
 * `RefreshDatabase` baut die Datenbank neu auf, es rollt nichts zurueck.
 * Genau deshalb blieb das Loch unten unentdeckt, bis eine Nachbarsitzung
 * nach der Zeile gefragt hat.
 */
function anlageRueckbauen(): void
{
    (require __DIR__.'/../../database/migrations/2026_10_26_000000_create_reminders_table.php')->down();
}

it('nimmt eine UEBERNOMMENE Tabelle beim Rueckbau nicht weg', function () {
    /*
    | Der gefaehrlichste Fall, und er war offen.
    |
    | `down()` machte `Schema::dropIfExists(config('reminders.tables.reminders'))`
    | ohne jede Pruefung. In allen vier Produkten zeigt dieser Schluessel auf
    | die eigene, gewachsene Tabelle — `task_reminders` im CRM, Manager und
    | AI Brain, `ticket_reminders` in der Verwaltung. Ein `migrate:rollback`
    | haette sie gedroppt.
    |
    | Gemessen am 27.10.2026 in Produktion: 27 lebende Zeilen im Manager,
    | 15 in AI Brain. Und auf dem Server gibt es keinen Nachtlauf und kein
    | binlog — es waere kein Rueckweg gewesen, sondern ein Ende.
    */
    // `alteTabelle()` steht schon im `beforeEach` — die Tabelle ist da.
    config()->set('reminders.tables.reminders', 'task_reminders');
    expect(Schema::hasTable('task_reminders'))->toBeTrue();

    anlageRueckbauen();

    expect(Schema::hasTable('task_reminders'))->toBeTrue(
        'Eine Tabelle, die das Paket nicht angelegt hat, darf es nicht wegnehmen.'
    );
});

it('nimmt eine Tabelle mit Inhalt beim Rueckbau nicht weg', function () {
    // Zweiter Riegel, unabhaengig vom ersten: Auch eine Tabelle, die diese
    // Migration SELBST angelegt hat, kann inzwischen gefuellt sein. Dann ist
    // der Rueckbau nicht mehr das Gegenstueck zum Aufbau.
    config()->set('reminders.tables.reminders', 'reminders');
    config()->set('reminders.legacy_subject', null);

    (require __DIR__.'/../../database/migrations/2026_10_26_000000_create_reminders_table.php')->up();

    // `subject_type` und `subject_id` sind in der eigenen Tabelle des Pakets
    // Pflicht — eine Erinnerung ohne Gegenstand gibt es dort nicht.
    DB::table('reminders')->insert([
        'subject_type' => Vorgang::class,
        'subject_id' => $this->vorgang->getKey(),
        'user_id' => 7,
        'remind_at' => now(),
        'title' => 'Eine Zeile, die jemandem gehoert',
    ]);

    anlageRueckbauen();

    expect(Schema::hasTable('reminders'))->toBeTrue('Eine gefuellte Tabelle bleibt stehen.');
});

it('nimmt die eigene, leere Tabelle beim Rueckbau sehr wohl weg', function () {
    // Die Gegenprobe. Ohne sie koennte `down()` auch schlicht nie etwas tun,
    // und die beiden Tests oben waeren gruen, ohne etwas zu pruefen.
    config()->set('reminders.tables.reminders', 'reminders');
    config()->set('reminders.legacy_subject', null);

    (require __DIR__.'/../../database/migrations/2026_10_26_000000_create_reminders_table.php')->up();
    expect(Schema::hasTable('reminders'))->toBeTrue();

    anlageRueckbauen();

    expect(Schema::hasTable('reminders'))->toBeFalse();
});
