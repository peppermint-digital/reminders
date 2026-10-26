<?php

use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Termin;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

beforeEach(function () {
    $this->person = Person::create(['name' => 'Bastian']);
});

it('haengt eine Erinnerung an einen Vorgang', function () {
    $vorgang = Vorgang::create(['name' => 'Angebot Meier']);

    $erinnerung = Reminder::create([
        'subject_type' => $vorgang->getMorphClass(),
        'subject_id' => $vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now()->addDay(),
        'title' => 'Nachfassen',
    ]);

    expect($erinnerung->subject)->toBeInstanceOf(Vorgang::class);
    expect($erinnerung->subject->name)->toBe('Angebot Meier');
});

it('haengt eine Erinnerung an einen Termin — in DERSELBEN Tabelle', function () {
    // Der ganze Zweck des Pakets. Zwei Gegenstaende, die nichts teilen: keine
    // Tabelle, keine Basisklasse, keine Spalte. Lagen sie in zwei Tabellen,
    // muesste ein Zustell-Lauf beide kennen — und genau das Vergessen der
    // zweiten ist Bug #880 im CRM.
    $vorgang = Vorgang::create(['name' => 'Angebot Meier']);
    $termin = Termin::create(['name' => 'Kundengespraech']);

    foreach ([$vorgang, $termin] as $gegenstand) {
        Reminder::create([
            'subject_type' => $gegenstand->getMorphClass(),
            'subject_id' => $gegenstand->getKey(),
            'user_id' => $this->person->getKey(),
            'remind_at' => now()->addDay(),
        ]);
    }

    expect(Reminder::count())->toBe(2);
    expect(Reminder::query()->get()->pluck('subject')->map(fn ($g) => $g::class)->all())
        ->toBe([Vorgang::class, Termin::class]);
});

it('findet ueber forSubject genau die Erinnerungen EINES Gegenstands', function () {
    // Die Abfrage, die ein Besitzer braucht, bevor er seinen Gegenstand
    // loescht: Der polymorphe Verweis traegt keinen Fremdschluessel, also
    // kaskadiert die Datenbank nicht.
    $meiner = Vorgang::create(['name' => 'meiner']);
    $fremder = Vorgang::create(['name' => 'fremder']);
    $termin = Termin::create(['name' => 'Termin']);

    foreach ([$meiner, $meiner, $fremder, $termin] as $gegenstand) {
        Reminder::create([
            'subject_type' => $gegenstand->getMorphClass(),
            'subject_id' => $gegenstand->getKey(),
            'user_id' => $this->person->getKey(),
            'remind_at' => now(),
        ]);
    }

    expect(Reminder::query()->forSubject($meiner)->count())->toBe(2);
    expect(Reminder::query()->forSubject($termin)->count())->toBe(1);
});

it('verwechselt gleiche Kennungen verschiedener Arten nicht', function () {
    // Vorgang 1 und Termin 1 sind verschiedene Dinge. Eine Abfrage nur auf
    // subject_id wuerde beide treffen — der haeufigste Fehler an einem
    // polymorphen Verweis.
    $vorgang = Vorgang::create(['name' => 'eins']);
    $termin = Termin::create(['name' => 'eins']);

    expect($vorgang->getKey())->toBe($termin->getKey());

    Reminder::create([
        'subject_type' => $vorgang->getMorphClass(),
        'subject_id' => $vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now(),
    ]);

    expect(Reminder::query()->forSubject($vorgang)->count())->toBe(1);
    expect(Reminder::query()->forSubject($termin)->count())->toBe(0);
});
