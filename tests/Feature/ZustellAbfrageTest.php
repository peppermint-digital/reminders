<?php

use Peppermint\Reminders\Delivery\DueReminders;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

beforeEach(function () {
    $this->person = Person::create(['name' => 'Bastian']);
    $this->vorgang = Vorgang::create(['name' => 'Angebot']);
    $this->faellige = new DueReminders;
});

function erinnerung(array $felder = []): Reminder
{
    return Reminder::create(array_merge([
        'subject_type' => test()->vorgang->getMorphClass(),
        'subject_id' => test()->vorgang->getKey(),
        'user_id' => test()->person->getKey(),
        'remind_at' => now()->subMinute(),
    ], $felder));
}

it('holt nur was faellig, offen und noch nicht zugestellt ist', function () {
    $faellig = erinnerung();
    erinnerung(['remind_at' => now()->addDay()]);                 // noch nicht faellig
    erinnerung(['is_dismissed' => true]);                          // weggeklickt
    erinnerung(['delivered_at' => now()->subHour()]);              // schon raus

    $geholt = $this->faellige->holen();

    expect($geholt)->toHaveCount(1);
    expect($geholt->first()->id)->toBe($faellig->id);
    expect($this->faellige->anzahl())->toBe(1);
});

it('nimmt die aeltesten zuerst', function () {
    // Ein Deckel, der die neuesten nimmt, laesst einen Rueckstau fuer immer
    // verhungern — die aelteste Erinnerung kaeme nie heraus.
    $alt = erinnerung(['remind_at' => now()->subDays(3)]);
    $mittel = erinnerung(['remind_at' => now()->subDay()]);
    $neu = erinnerung(['remind_at' => now()->subMinute()]);

    expect($this->faellige->holen()->pluck('id')->all())
        ->toBe([$alt->id, $mittel->id, $neu->id]);
});

it('achtet den Deckel', function () {
    foreach (range(1, 5) as $i) {
        erinnerung(['remind_at' => now()->subMinutes($i)]);
    }

    expect($this->faellige->holen(2))->toHaveCount(2);
    expect($this->faellige->anzahl())->toBe(5);
});

it('vermerkt die Zustellung genau einmal', function () {
    // Zweimal vermerken wuerde den Zeitpunkt verschieben, und dann sagt die
    // Historie, es sei spaeter herausgegangen als es herausging.
    $e = erinnerung();

    expect($e->markDelivered())->toBeTrue();
    $zuerst = $e->fresh()->delivered_at;

    expect($e->fresh()->markDelivered())->toBeFalse();
    expect($e->fresh()->delivered_at->eq($zuerst))->toBeTrue();
});

it('trennt zugestellt von weggeklickt', function () {
    // Die Unterscheidung, ohne die ein Lauf jede nicht weggeklickte Erinnerung
    // alle fuenf Minuten erneut verschickt.
    $e = erinnerung();
    $e->markDelivered();

    expect($e->fresh()->is_dismissed)->toBeFalse();
    expect($this->faellige->holen())->toHaveCount(0);
});

it('klickt genau einmal weg', function () {
    $e = erinnerung();

    expect($e->dismiss())->toBeTrue();
    $zuerst = $e->fresh()->dismissed_at;

    expect($e->fresh()->dismiss())->toBeFalse();
    expect($e->fresh()->dismissed_at->eq($zuerst))->toBeTrue();
});

it('trennt von Hand gesetzte von erzeugten Erinnerungen', function () {
    // Ohne die Unterscheidung verdoppelt ein taeglicher Abgleich entweder
    // eigene Zeilen oder ueberschreibt, was ein Mensch gesetzt hat.
    $handgesetzt = erinnerung();
    $erzeugt = erinnerung(['source' => 'faelligkeit']);

    expect(Reminder::query()->handSet()->pluck('id')->all())->toBe([$handgesetzt->id]);
    expect(Reminder::query()->fromSource('faelligkeit')->pluck('id')->all())->toBe([$erzeugt->id]);
});

it('gibt jedem nur seine eigenen', function () {
    $andere = Person::create(['name' => 'Chris']);
    $meine = erinnerung();
    erinnerung(['user_id' => $andere->getKey()]);

    expect(Reminder::query()->forUser($this->person)->pluck('id')->all())->toBe([$meine->id]);
    expect(Reminder::query()->forUser($andere)->count())->toBe(1);
});

it('haelt eine geschlummerte Erinnerung aus der Zustellung heraus', function () {
    // Ohne die Schlummer-Bedingung in `scopeDue` ist Schlummern wirkungslos:
    // der Lauf gibt sie sofort wieder heraus.
    $e = erinnerung();
    $e->snooze(60);

    expect($this->faellige->holen())->toHaveCount(0);
    expect($this->faellige->anzahl())->toBe(0);
});

it('gibt sie heraus, sobald der Schlummer vorbei ist — Gegenprobe nach oben', function () {
    // Die andere Richtung. Ohne sie wuesste man nicht, ob die Erinnerung
    // schlummert oder einfach verschwunden ist.
    $e = erinnerung();
    $e->snooze(60);

    expect($this->faellige->holen())->toHaveCount(0);

    $this->travel(61)->minutes();

    expect($this->faellige->holen())->toHaveCount(1);
    expect($this->faellige->holen()->first()->id)->toBe($e->id);
});

it('setzt beim Schlummern die Zustellung zurueck', function () {
    // Der Fehler, der aussieht wie ein funktionierender Knopf: Bleibt
    // `delivered_at` stehen, filtert `undelivered()` die Erinnerung fuer
    // immer weg — geschlummert heisst dann weggeworfen.
    $e = erinnerung();
    $e->markDelivered();

    expect($e->fresh()->delivered_at)->not->toBeNull();

    $e->fresh()->snooze(30);

    expect($e->fresh()->delivered_at)->toBeNull();
});

it('zaehlt mit, wie oft weggeschoben wurde', function () {
    $e = erinnerung();

    $e->snooze(5);
    expect($e->fresh()->snooze_count)->toBe(1);

    $e->fresh()->snooze(5);
    expect($e->fresh()->snooze_count)->toBe(2);
});

it('leitet den Schlummer-Zustand ab, statt ihn zu fuehren', function () {
    // Kein `is_snoozed` neben dem Zeitstempel — zwei Wahrheiten laufen
    // auseinander.
    $e = erinnerung();

    expect($e->istGeschlummert())->toBeFalse();

    $e->snooze(60);
    expect($e->fresh()->istGeschlummert())->toBeTrue();

    $this->travel(61)->minutes();
    expect($e->fresh()->istGeschlummert())->toBeFalse();
});

it('faellt eine geschlummerte Erinnerung nicht aus beiden Listen', function () {
    // Faellig ist sie nicht, und mit einem schlichten `remind_at > now` waere
    // sie auch nicht „kommend" — dann waere sie nirgends.
    $e = erinnerung();
    $e->snooze(60);

    expect(Reminder::query()->active()->due()->count())->toBe(0);
    expect(Reminder::query()->active()->upcoming()->pluck('id')->all())->toBe([$e->id]);
    expect(Reminder::query()->snoozed()->count())->toBe(1);
});

it('weist eine unsinnige Schlummerdauer ab', function () {
    $e = erinnerung();

    expect($e->snooze(0))->toBeFalse();
    expect($e->fresh()->snoozed_until)->toBeNull();
});

it('bietet die Stufen aus der Konfiguration an, ohne Woerter', function () {
    expect(Reminder::snoozeSteps())->toBe([5, 15, 30, 60, 240, 1440, 10080]);

    config()->set('reminders.snooze.steps', [10, 20]);
    expect(Reminder::snoozeSteps())->toBe([10, 20]);
});
