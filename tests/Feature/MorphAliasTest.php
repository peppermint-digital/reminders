<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peppermint\Reminders\Delivery\DueReminders;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

/**
 * Was in `subject_type` steht, entscheidet das PRODUKT — nicht dieses Paket.
 *
 * AI Brain erzwingt eine Morph-Map (`'task' => Task::class`), dort landet der
 * Alias `task`. CRM, Manager und Verwaltung fuehren keine und speichern den
 * vollen Klassennamen. Beides ist richtig; eine Karte ist sogar der bessere
 * Weg, weil sie die Daten von Klassennamen entkoppelt und ein Umbenennen
 * erlaubt, ohne die Datenbank anzufassen.
 *
 * Falsch war die Annahme dieses Pakets, beides sei dasselbe. An drei Stellen
 * stand der Klassenname, wo der gespeicherte Typ gemeint war. In drei von
 * vier Produkten ist das zufaellig deckungsgleich — deshalb war die ganze
 * Reihe gruen und der Fehler lag ausschliesslich im vierten:
 *
 *   getMorphedModel('task')             -> App\Models\Task   ✓
 *   getMorphedModel('App\Models\Task')  -> null               ✗
 *
 * Gefunden am 26.10.2026, BEVOR das Paket in AI Brain eingebaut wurde — die
 * Nachfrage „die Tasks sollen doch unifiziert sein" hat die Messung
 * ausgeloest. AI Brain ist Produktionsserver ohne Entwicklungsumgebung; dort
 * waere es ein Datenschaden geworden und kein roter Test.
 */
function morphAliasTabelleAnlegen(): void
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

function morphAliasUebernahme(): void
{
    (require __DIR__.'/../../database/migrations/2026_10_26_000100_upgrade_adopted_reminders_table.php')->up();
}

beforeEach(function () {
    // Genau wie AI Brain: eine ERZWUNGENE Karte. Erzwungen ist der Teil, der
    // weh tut — ein Typ, der nicht drinsteht, wirft beim Aufloesen.
    Relation::enforceMorphMap([
        'vorgang' => Vorgang::class,
        'person' => Person::class,
    ]);

    $this->vorgang = Vorgang::create(['name' => 'Angebot Meier']);
    $this->person = Person::create(['name' => 'Meier']);
});

afterEach(function () {
    // Statischer Zustand. Ohne das Zuruecksetzen traegt der naechste Test die
    // Karte weiter und faellt an einer Stelle um, die nichts damit zu tun hat.
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
});

it('legt den Alias in subject_type ab, nicht den Klassennamen', function () {
    expect($this->vorgang->getMorphClass())->toBe('vorgang');

    $erinnerung = Reminder::create([
        'subject_type' => $this->vorgang->getMorphClass(),
        'subject_id' => $this->vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now()->addHour(),
    ]);

    expect($erinnerung->subject_type)->toBe('vorgang')
        ->and($erinnerung->subject_type)->not->toBe(Vorgang::class);
});

it('fuellt die alte Spalte auch dann, wenn der Typ ein Alias ist', function () {
    morphAliasTabelleAnlegen();

    // Reihenfolge: Die Uebernahme liest die Tabelle aus der Konfiguration.
    // Erst setzen, dann laufen lassen — sonst ergaenzt sie die Spalten an der
    // Vorgabe-Tabelle und die hier bleibt, wie sie war.
    config()->set('reminders.tables.reminders', 'task_reminders');
    config()->set('reminders.legacy_subject', [
        'column' => 'task_id',
        // Das Produkt konfiguriert die KLASSE — es kennt seinen Alias hier
        // nicht und soll ihn auch nicht wiederholen muessen.
        'type' => Vorgang::class,
        'relax' => false,
    ]);

    morphAliasUebernahme();

    $erinnerung = Reminder::create([
        'subject_type' => $this->vorgang->getMorphClass(),
        'subject_id' => $this->vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now()->addHour(),
    ]);

    // Das ist der Datenschaden, der AI Brain getroffen haette: Der Vergleich
    // lief gegen den Klassennamen, traf den Alias nie, liess `task_id` leer —
    // und `task_id` ist dort NOT NULL mit Fremdschluessel.
    expect($erinnerung->fresh()->task_id)->toBe($this->vorgang->getKey());
});

it('schreibt beim Nachfuellen den Alias und nicht den Klassennamen', function () {
    morphAliasTabelleAnlegen();

    DB::table('task_reminders')->insert([
        'task_id' => $this->vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now()->subHour(),
        'title' => 'Nachfassen',
    ]);

    config()->set('reminders.tables.reminders', 'task_reminders');
    config()->set('reminders.legacy_subject', [
        'column' => 'task_id',
        'type' => Vorgang::class,
        'relax' => false,
    ]);

    morphAliasUebernahme();

    $zeile = DB::table('task_reminders')->first();

    // Zwei Werte fuer dieselbe Sache waere der eigentliche Schaden: Altzeilen
    // mit Klassennamen, neue mit Alias — und kein Lauf findet mehr beide.
    expect($zeile->subject_type)->toBe('vorgang')
        ->and($zeile->subject_type)->not->toBe(Vorgang::class);
});

it('laedt den Gegenstand ueber den Alias, ohne lazy zu laden', function () {
    Model::preventLazyLoading();

    Reminder::create([
        'subject_type' => $this->vorgang->getMorphClass(),
        'subject_id' => $this->vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now()->subHour(),
    ]);

    $faellige = (new DueReminders)->holen();

    expect($faellige)->toHaveCount(1);

    $erinnerung = $faellige->first();

    // Kein `LazyLoadingViolationException` — und der Gegenstand ist wirklich
    // da, nicht nur „ungeladen durchgewinkt".
    expect($erinnerung->relationLoaded('subject'))->toBeTrue()
        ->and($erinnerung->subject)->not->toBeNull()
        ->and($erinnerung->subject->name)->toBe('Angebot Meier');
});
