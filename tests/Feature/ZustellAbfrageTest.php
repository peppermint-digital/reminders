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
