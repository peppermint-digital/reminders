<?php

use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

beforeEach(function () {
    $this->person = Person::create(['name' => 'Bastian']);
    $this->vorgang = Vorgang::create(['name' => 'Angebot Meier']);
});

function wiederkehrende(array $felder = []): Reminder
{
    return Reminder::create(array_merge([
        'subject_type' => test()->vorgang->getMorphClass(),
        'subject_id' => test()->vorgang->getKey(),
        'user_id' => test()->person->getKey(),
        'remind_at' => now()->subMinute(),
        'title' => 'Wochenbericht',
        'recurrence_pattern' => 'weekly',
    ], $felder));
}

it('legt keine Folge an, wenn nichts wiederkehrt', function () {
    $einmalig = wiederkehrende(['recurrence_pattern' => null]);

    expect($einmalig->istWiederkehrend())->toBeFalse();
    expect($einmalig->naechsteInstanz(now()->addWeek()))->toBeNull();
    expect(Reminder::count())->toBe(1);
});

it('legt die Folge mit demselben Inhalt an', function () {
    $erste = wiederkehrende(['note' => 'an die Zahlen denken']);

    $zweite = $erste->naechsteInstanz(now()->addWeek());

    expect($zweite)->not->toBeNull();
    expect($zweite->title)->toBe('Wochenbericht');
    expect($zweite->note)->toBe('an die Zahlen denken');
    expect($zweite->recurrence_pattern)->toBe('weekly');
    expect($zweite->subject_type)->toBe($this->vorgang->getMorphClass());
    expect((int) $zweite->user_id)->toBe($this->person->getKey());
});

it('gibt der Folge einen frischen Zustand', function () {
    // Uebernaehme sie das vom Vorgaenger, waere sie bei der Geburt schon
    // zugestellt — und fiele damit aus der Zustellung heraus.
    $erste = wiederkehrende();
    $erste->markDelivered();
    $erste->fresh()->snooze(30);
    $erste->fresh()->dismiss();

    $zweite = $erste->fresh()->naechsteInstanz(now()->addWeek());

    expect($zweite->delivered_at)->toBeNull();
    expect($zweite->snoozed_until)->toBeNull();
    expect($zweite->snooze_count)->toBe(0);
    expect($zweite->is_dismissed)->toBeFalse();
});

it('haengt die Kette an die WURZEL, nicht an den Vorgaenger', function () {
    // Eine flache Kette laesst sich in einer Abfrage nach „die ganze Serie"
    // fragen; eine verkettete Liste muss gegangen werden, und ein Gang ueber
    // Nutzerdaten trifft irgendwann eine Luecke.
    $erste = wiederkehrende();
    $zweite = $erste->naechsteInstanz(now()->addWeek());
    $dritte = $zweite->naechsteInstanz(now()->addWeeks(2));

    expect($zweite->parent_id)->toBe($erste->id);
    expect($dritte->parent_id)->toBe($erste->id)
        ->and($dritte->parent_id)->not->toBe($zweite->id);

    expect($erste->parent_id)->toBeNull();
    expect($zweite->parent->id)->toBe($erste->id);
    expect($erste->fresh()->instances()->count())->toBe(2);
});

it('legt fuer denselben Zeitpunkt keine zweite an', function () {
    // Dieselbe Begruendung wie bei `source`: Was Zeilen erzeugt und zweimal
    // laeuft, darf sie nicht verdoppeln.
    $erste = wiederkehrende();
    $zeitpunkt = now()->addWeek()->startOfMinute();

    $a = $erste->naechsteInstanz($zeitpunkt);
    $b = $erste->naechsteInstanz($zeitpunkt);

    expect($b->id)->toBe($a->id);
    expect(Reminder::count())->toBe(2);
});

it('fasst den Vorgaenger nicht an — auch keinen abgehakten', function () {
    // Eine abgehakte Erinnerung ist der Nachweis, dass jemand erinnert wurde
    // und gehandelt hat. Daran nachtraeglich zu drehen schreibt die
    // Geschichte um.
    $erste = wiederkehrende();
    $erste->dismiss();

    $vorher = $erste->fresh()->only(['is_dismissed', 'dismissed_at', 'remind_at', 'snooze_count']);

    $erste->fresh()->naechsteInstanz(now()->addWeek());

    expect($erste->fresh()->only(['is_dismissed', 'dismissed_at', 'remind_at', 'snooze_count']))
        ->toEqual($vorher);
});

it('findet die ganze Serie, die Wurzel eingeschlossen', function () {
    $erste = wiederkehrende();
    $zweite = $erste->naechsteInstanz(now()->addWeek());
    $erste->naechsteInstanz(now()->addWeeks(2));

    // Fremde Serie daneben, damit die Abfrage sich nicht aus Versehen alles holt.
    wiederkehrende(['title' => 'andere Serie'])->naechsteInstanz(now()->addWeek());

    expect(Reminder::query()->serie($erste)->count())->toBe(3);
    // Von einer Instanz aus gefragt kommt dieselbe Serie heraus.
    expect(Reminder::query()->serie($zweite)->count())->toBe(3);
});

it('trennt wiederkehrende von einmaligen', function () {
    wiederkehrende();
    wiederkehrende(['recurrence_pattern' => null, 'title' => 'einmalig']);

    expect(Reminder::query()->wiederkehrend()->pluck('title')->all())->toBe(['Wochenbericht']);
});

it('deutet das Muster NICHT', function () {
    // Das Paket speichert `weekly` und weiss nicht, was es heisst. Was es
    // bedeutet, entscheidet das Produkt — deshalb kommt der Zeitpunkt herein
    // und wird nicht gerechnet.
    $erste = wiederkehrende(['recurrence_pattern' => 'voelliger-unsinn']);

    $zweite = $erste->naechsteInstanz(now()->addDay());

    expect($zweite)->not->toBeNull();
    expect($zweite->recurrence_pattern)->toBe('voelliger-unsinn');
});
