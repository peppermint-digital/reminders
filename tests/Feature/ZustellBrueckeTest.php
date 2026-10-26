<?php

use Peppermint\Notifications\Models\Notification;
use Peppermint\Reminders\Delivery\DueReminders;
use Peppermint\Reminders\Delivery\NotificationsBridge;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

/*
| Die Bruecke zu peppermint/notifications.
|
| Sie liegt im Paket, damit nicht vier Produkte dieselben zwanzig Zeilen
| schreiben (Pruefrage 3 des Paketvertrags). Die Abhaengigkeit ist ein
| `suggest`: ohne das Paket bleibt das Erinnerungs-Paket ein Speicher mit
| Abfrage, und das Produkt stellt selbst zu.
*/

beforeEach(function () {
    $this->person = Person::create(['name' => 'Bastian']);
    $this->vorgang = Vorgang::create(['name' => 'Angebot Meier']);

    // Ohne Punkt: der Pfad fuer die Vorlieben ist Punkt-Notation, und eine Art
    // wie `reminder.due` wuerde darin als Verschachtelung gelesen.
    config()->set('reminders.delivery.type', 'erinnerung');
});

function erinnerungFuerBruecke(array $felder = []): Reminder
{
    return Reminder::create(array_merge([
        'subject_type' => test()->vorgang->getMorphClass(),
        'subject_id' => test()->vorgang->getKey(),
        'user_id' => test()->person->getKey(),
        'remind_at' => now()->subMinute(),
        'title' => 'Nachfassen',
    ], $felder));
}

function bruecke(): NotificationsBridge
{
    return new NotificationsBridge(
        app(\Peppermint\Notifications\Delivery\Notifier::class),
        new DueReminders,
    );
}

it('ist verfuegbar, weil das Meldungs-Paket installiert ist', function () {
    expect(NotificationsBridge::verfuegbar())->toBeTrue();
});

it('macht aus einer faelligen Erinnerung eine Meldung', function () {
    $erinnerung = erinnerungFuerBruecke();

    expect(bruecke()->zustellen())->toBe(1);

    $meldung = Notification::query()->first();

    expect($meldung)->not->toBeNull();
    expect($meldung->type)->toBe('erinnerung');
    expect($meldung->title)->toBe('Nachfassen');
    expect((int) $meldung->user_id)->toBe($this->person->getKey());

    expect($erinnerung->fresh()->delivered_at)->not->toBeNull();
});

it('reicht den Gegenstand mit durch', function () {
    // Damit die Meldung in der Glocke dorthin fuehrt, worum es geht.
    //
    // Achtung beim Lesen: Dasselbe Ding heisst in den beiden Paketen
    // verschieden — hier `subject`, im Meldungs-Paket `notifiable`. Das ist
    // kein Versehen, sondern der Vertrag: Jedes Paket spricht sein eigenes
    // Vokabular, und die Bruecke uebersetzt. Wer das nicht weiss, prueft die
    // falsche Spalte und haelt eine heile Bruecke fuer kaputt.
    erinnerungFuerBruecke();

    bruecke()->zustellen();

    $meldung = Notification::query()->first();

    expect($meldung->notifiable_type)->toBe($this->vorgang->getMorphClass());
    expect((int) $meldung->notifiable_id)->toBe($this->vorgang->getKey());
});

it('stellt zweimal hintereinander nicht doppelt zu', function () {
    // `delivered_at` ist der Riegel. Ohne ihn verschickt der Lauf jede nicht
    // weggeklickte Erinnerung alle fuenf Minuten erneut.
    erinnerungFuerBruecke();

    expect(bruecke()->zustellen())->toBe(1);
    expect(bruecke()->zustellen())->toBe(0);
    expect(Notification::query()->count())->toBe(1);
});

it('laesst Nicht-Faelliges, Weggeklicktes und Zugestelltes liegen', function () {
    erinnerungFuerBruecke(['remind_at' => now()->addDay()]);
    erinnerungFuerBruecke(['is_dismissed' => true]);
    erinnerungFuerBruecke(['delivered_at' => now()->subHour()]);

    expect(bruecke()->zustellen())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
});

it('vermerkt die Zustellung auch, wenn die Person diese Art abgestellt hat', function () {
    // Der Fall, der ohne Vermerk zur Endlosschleife wird: Die Vorliebe sagt
    // nein, der Notifier legt nichts an — und der Lauf wuerde es alle fuenf
    // Minuten erneut versuchen, fuer immer.
    //
    // „Niemand wollte es" und „wir haben es kaputt gemacht" sind hier
    // dieselbe Rueckgabe. Deshalb gilt sie als erledigt: Die Person hat
    // entschieden, und eine Entscheidung ist kein Fehlschlag.
    config()->set('notifications.preferences.per_type.erinnerung.inapp', 'off');

    $erinnerung = erinnerungFuerBruecke();

    expect(bruecke()->zustellen())->toBe(1);
    expect(Notification::query()->count())->toBe(0);
    expect($erinnerung->fresh()->delivered_at)->not->toBeNull();
});

it('wirft NICHT, wenn die Meldungsart nirgends angemeldet ist', function () {
    // Hier stand bis zum 26.10.2026 ein Riegel, der genau das tat — auf einer
    // erfundenen Gefahr. Gemessen: Eine unbekannte Art ist nicht unsichtbar,
    // das Meldungs-Paket haelt dafuer einen ausdruecklichen Rueckfall
    // (`unknown_type`, „Sichtbar falsch ist besser als unsichtbar"). Wer es
    // strenger will, schaltet `notifications.strict_types` ein — das ist die
    // Entscheidung des Produkts und nicht die der Bruecke.
    expect(config('notifications.types'))->toBe([]);

    erinnerungFuerBruecke();

    expect(bruecke()->zustellen())->toBe(1);
    expect(Notification::query()->count())->toBe(1);
});

it('achtet den Deckel', function () {
    foreach (range(1, 4) as $i) {
        erinnerungFuerBruecke(['remind_at' => now()->subMinutes($i)]);
    }

    expect(bruecke()->zustellen(2))->toBe(2);
    expect(Reminder::query()->undelivered()->count())->toBe(2);
});
