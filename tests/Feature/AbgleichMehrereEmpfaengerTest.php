<?php

use Peppermint\Reminders\Abgleich\Abgleich;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;
use Peppermint\Reminders\Tests\Support\TestMehrfachAnlass;

/*
| Der Abgleich mit MEHREREN Empfaengern (Bug #1080 im Peppermint Manager).
|
| Anlass: `EventReminders` erinnerte nur `owner_id`. Chris ist bei seinen
| Terminen Teilnehmer — er haette auch mit eingeschaltetem Schalter nie etwas
| bekommen, und der Lauf haette dabei „angelegt" gemeldet.
|
| Geprueft wird hier der Unterschied zum einfachen Pfad, nicht Produktlogik:
| eine Zeile JE PERSON, ein eigener Zeitpunkt je Person, und ein Wegfall, der
| nur die betroffene Person trifft.
*/

beforeEach(function () {
    $this->bastian = Person::create(['name' => 'Bastian']);
    $this->chris = Person::create(['name' => 'Chris']);
    $this->vorgang = Vorgang::create(['name' => 'Besprechung']);

    $this->gleich = now()->addHour()->startOfSecond();
    $this->spaeter = now()->addHours(2)->startOfSecond();

    $this->anlass = new TestMehrfachAnlass;
    $this->anlass->jeGegenstand = [
        $this->vorgang->getKey() => [
            $this->bastian->getKey() => ['test:eins' => $this->gleich],
            $this->chris->getKey() => ['test:eins' => $this->spaeter],
        ],
    ];
});

function fahrenMehrfach(TestMehrfachAnlass $anlass): array
{
    return (new Abgleich($anlass))->fahren();
}

it('legt je Person eine eigene Zeile mit eigenem Zeitpunkt an', function () {
    expect(fahrenMehrfach($this->anlass)['angelegt'])->toBe(2);

    expect(Reminder::query()->forUser($this->bastian)->first()->remind_at->eq($this->gleich))->toBeTrue();
    expect(Reminder::query()->forUser($this->chris)->first()->remind_at->eq($this->spaeter))->toBeTrue();
    expect(Reminder::query()->forSubject($this->vorgang)->count())->toBe(2);
});

it('legt beim zweiten Durchgang nichts doppelt an', function () {
    fahrenMehrfach($this->anlass);

    expect(fahrenMehrfach($this->anlass))
        ->angelegt->toBe(0)
        ->verschoben->toBe(0)
        ->weggenommen->toBe(0);

    expect(Reminder::count())->toBe(2);
});

it('verschiebt nur die Zeile der Person, deren Zeitpunkt sich geaendert hat', function () {
    fahrenMehrfach($this->anlass);

    $vorher = Reminder::query()->forUser($this->bastian)->first();

    $neu = now()->addMinutes(90)->startOfSecond();
    $this->anlass->jeGegenstand[$this->vorgang->getKey()][$this->chris->getKey()]['test:eins'] = $neu;

    expect(fahrenMehrfach($this->anlass)['verschoben'])->toBe(1);

    expect(Reminder::query()->forUser($this->chris)->first()->remind_at->eq($neu))->toBeTrue();
    expect(Reminder::query()->forUser($this->bastian)->first()->updated_at->eq($vorher->updated_at))->toBeTrue();
});

it('nimmt NUR der Person die Zeile weg, die nicht mehr genannt wird', function () {
    fahrenMehrfach($this->anlass);

    unset($this->anlass->jeGegenstand[$this->vorgang->getKey()][$this->chris->getKey()]);

    expect(fahrenMehrfach($this->anlass)['weggenommen'])->toBe(1);

    expect(Reminder::query()->forUser($this->chris)->count())->toBe(0);
    expect(Reminder::query()->forUser($this->bastian)->count())->toBe(1);
});

it('uebernimmt die Zeile einer anderen Person NICHT, sondern legt eine eigene an', function () {
    // Der Kern des Unterschieds zum einfachen Pfad: Dort wandert die Zeile bei
    // einem Wechsel mit. Hier gehoert sie der Person — sie zu uebernehmen
    // wuerde Bastian seine Erinnerung nehmen und Chris dessen `delivered_at`
    // unterschieben.
    $this->anlass->jeGegenstand = [
        $this->vorgang->getKey() => [$this->bastian->getKey() => ['test:eins' => $this->gleich]],
    ];

    fahrenMehrfach($this->anlass);
    $bastiansZeile = Reminder::query()->forUser($this->bastian)->first()->getKey();

    $this->anlass->jeGegenstand[$this->vorgang->getKey()] = [
        $this->chris->getKey() => ['test:eins' => $this->gleich],
    ];

    $bilanz = fahrenMehrfach($this->anlass);

    expect($bilanz['angelegt'])->toBe(1);
    expect($bilanz['weggenommen'])->toBe(1);
    expect(Reminder::query()->whereKey($bastiansZeile)->exists())->toBeFalse();
    expect(Reminder::query()->forUser($this->chris)->count())->toBe(1);
});

it('laesst eine abgehakte Zeile stehen, auch wenn die Person nicht mehr genannt wird', function () {
    fahrenMehrfach($this->anlass);

    Reminder::query()->forUser($this->chris)->first()->dismiss();

    unset($this->anlass->jeGegenstand[$this->vorgang->getKey()][$this->chris->getKey()]);

    expect(fahrenMehrfach($this->anlass)['weggenommen'])->toBe(0);
    expect(Reminder::query()->forUser($this->chris)->count())->toBe(1);
});

it('zaehlt eine leere Empfaengerliste NICHT als „ohne Empfaenger"', function () {
    // Ein Termin, bei dem alle Beteiligten Erinnerungen abgestellt haben, sieht
    // genauso aus wie einer ohne Beteiligte. Wuerde der Abgleich das melden,
    // warnte der Zeitplan dauerhaft ueber eine bewusste Entscheidung.
    $this->anlass->jeGegenstand = [$this->vorgang->getKey() => []];

    expect(fahrenMehrfach($this->anlass))
        ->angelegt->toBe(0)
        ->ohne_empfaenger->toBe([]);
});

it('nimmt allen Personen die Zeile weg, wenn der Gegenstand aus der Menge faellt', function () {
    fahrenMehrfach($this->anlass);

    $this->anlass->nurDiese = [];

    expect(fahrenMehrfach($this->anlass)['weggenommen'])->toBe(2);
    expect(Reminder::count())->toBe(0);
});
