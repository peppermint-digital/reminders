<?php

use Peppermint\Reminders\Abgleich\Abgleich;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;
use Peppermint\Reminders\Tests\Support\TestAnlass;

/*
| Der gemeinsame Abgleich (#8786).
|
| Er entstand, weil Aufgaben- und Kalender-Paket ihn je einmal hatten: 238
| gegen 247 Zeilen mit denselben Bauteilen, verschieden war nur, welche
| Zeitpunkte der Gegenstand hat. Prüffrage 3 des Paketvertrags, und sie stand
| auf JA.
|
| Geprüft wird hier der Abgleich, nicht Produktlogik: Welche Momente ein
| Gegenstand verdient, setzt die Attrappe.
*/

beforeEach(function () {
    $this->person = Person::create(['name' => 'Bastian']);
    $this->andere = Person::create(['name' => 'Chris']);
    $this->vorgang = Vorgang::create(['name' => 'Angebot']);

    $this->anlass = new TestAnlass;
    $this->anlass->empfaengerVorgabe = $this->person->getKey();
    $this->anlass->momenteJeGegenstand = [
        $this->vorgang->getKey() => ['test:eins' => now()->addDay()->startOfSecond()],
    ];
});

function fahren(TestAnlass $anlass): array
{
    return (new Abgleich($anlass))->fahren();
}

it('legt je Quelle eine Erinnerung an', function () {
    $this->anlass->quellen = ['test:eins', 'test:zwei'];
    $this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:zwei'] = now()->addDays(2)->startOfSecond();

    expect(fahren($this->anlass)['angelegt'])->toBe(2);

    expect(Reminder::query()->pluck('source')->sort()->values()->all())
        ->toBe(['test:eins', 'test:zwei']);
    expect(Reminder::query()->first()->title)->toBe('test:eins an Angebot');
});

it('haengt sie an den Gegenstand mit der Klasse des Anlasses', function () {
    fahren($this->anlass);

    expect(Reminder::query()->first()->subject_type)->toBe($this->vorgang->getMorphClass());
    expect(Reminder::query()->forSubject($this->vorgang)->count())->toBe(1);
});

it('legt beim zweiten Durchgang nichts doppelt an', function () {
    expect(fahren($this->anlass)['angelegt'])->toBe(1);
    expect(fahren($this->anlass)['angelegt'])->toBe(0);
    expect(Reminder::count())->toBe(1);
});

it('nimmt die Erinnerung mit, wenn der Zeitpunkt wandert — und hebt die Zustell-Sperre auf', function () {
    // Ohne das Zuruecksetzen feuert die verschobene Erinnerung NIE: Sie gilt
    // als „schon gemeldet", obwohl sie zum neuen Zeitpunkt nichts gesagt hat.
    fahren($this->anlass);
    Reminder::query()->first()->markDelivered();

    $neu = now()->addDays(5)->startOfSecond();
    $this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:eins'] = $neu;

    expect(fahren($this->anlass)['verschoben'])->toBe(1);

    $e = Reminder::query()->first();
    expect($e->remind_at->equalTo($neu))->toBeTrue();
    expect($e->delivered_at)->toBeNull();
    expect(Reminder::count())->toBe(1);
});

it('nimmt sie mit, wenn der Empfaenger wechselt', function () {
    fahren($this->anlass);

    $this->anlass->empfaengerVorgabe = $this->andere->getKey();

    expect(fahren($this->anlass)['verschoben'])->toBe(1);
    expect((int) Reminder::query()->first()->user_id)->toBe($this->andere->getKey());
});

it('fasst eine abgehakte Zeile nicht an', function () {
    // Sie ist der Nachweis, dass jemand erinnert wurde und gehandelt hat.
    fahren($this->anlass);
    $e = Reminder::query()->first();
    $e->dismiss();
    $vorher = $e->fresh()->remind_at;

    $this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:eins'] = now()->addDays(9)->startOfSecond();

    expect(fahren($this->anlass)['verschoben'])->toBe(0);
    expect(Reminder::query()->first()->remind_at->equalTo($vorher))->toBeTrue();
});

it('nimmt weg, was aus der Menge gefallen ist', function () {
    fahren($this->anlass);

    // Der Gegenstand verdient keine mehr.
    $this->anlass->nurDiese = [];

    expect(fahren($this->anlass)['weggenommen'])->toBe(1);
    expect(Reminder::count())->toBe(0);
});

it('laesst eine abgehakte Zeile stehen, auch wenn ihr Anlass entfaellt', function () {
    fahren($this->anlass);
    Reminder::query()->first()->dismiss();

    $this->anlass->nurDiese = [];

    expect(fahren($this->anlass)['weggenommen'])->toBe(0);
    expect(Reminder::count())->toBe(1);
});

it('raeumt NUR die eigenen Quellen weg', function () {
    // Eine Zeile einer fremden Quelle gehoert einem anderen Anlass — oder
    // einem Menschen, der sie von Hand gesetzt hat.
    fahren($this->anlass);

    $fremde = Reminder::create([
        'subject_type' => $this->vorgang->getMorphClass(),
        'subject_id' => $this->vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now()->addDay(),
        'source' => 'fremder:anlass',
    ]);
    $handgesetzt = Reminder::create([
        'subject_type' => $this->vorgang->getMorphClass(),
        'subject_id' => $this->vorgang->getKey(),
        'user_id' => $this->person->getKey(),
        'remind_at' => now()->addDay(),
    ]);

    $this->anlass->nurDiese = [];

    expect(fahren($this->anlass)['weggenommen'])->toBe(1);
    expect(Reminder::query()->whereKey([$fremde->id, $handgesetzt->id])->count())->toBe(2);
});

it('zaehlt und NENNT Gegenstaende ohne Empfaenger', function () {
    // Ein Lauf, der leise nichts tut, meldet Erfolg — und die Luecke faellt
    // erst Monate spaeter auf.
    $this->anlass->empfaengerVorgabe = null;

    $bilanz = fahren($this->anlass);

    expect($bilanz['angelegt'])->toBe(0);
    expect($bilanz['ohne_empfaenger'])->toBe([$this->vorgang->getKey()]);
    expect(Reminder::count())->toBe(0);
});

it('nimmt weg, wenn eine Quelle keinen Zeitpunkt mehr hat', function () {
    // Nicht derselbe Fall wie „aus der Menge gefallen": Der Gegenstand ist
    // noch da, nur eine seiner Quellen hat nichts mehr zu sagen. Ohne das
    // mahnt eine Zeile zu einem Anlass, den es nicht mehr gibt.
    //
    // Gefunden beim Testschreiben: Der Vertrag sagte es zu, die Umsetzung tat
    // es nicht. Eine Zusage, die der Code nicht haelt, ist schlimmer als keine.
    $this->anlass->quellen = ['test:eins', 'test:zwei'];
    $this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:zwei'] = now()->addDays(2)->startOfSecond();

    expect(fahren($this->anlass)['angelegt'])->toBe(2);

    // Die zweite Quelle faellt weg — wie die Vorwarnung, sobald der Tag dran ist.
    unset($this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:zwei']);

    expect(fahren($this->anlass)['weggenommen'])->toBe(1);
    expect(Reminder::query()->pluck('source')->all())->toBe(['test:eins']);
});

it('laesst eine abgehakte Zeile stehen, auch wenn ihre Quelle schweigt', function () {
    $this->anlass->quellen = ['test:eins', 'test:zwei'];
    $this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:zwei'] = now()->addDays(2)->startOfSecond();
    fahren($this->anlass);

    Reminder::query()->where('source', 'test:zwei')->first()->dismiss();
    unset($this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:zwei']);

    expect(fahren($this->anlass)['weggenommen'])->toBe(0);
    expect(Reminder::count())->toBe(2);
});

it('arbeitet in Haeppchen', function () {
    $momente = [];

    foreach (range(1, 7) as $i) {
        $v = Vorgang::create(['name' => "V{$i}"]);
        $momente[$v->getKey()] = ['test:eins' => now()->addDays($i)->startOfSecond()];
    }

    $this->anlass->momenteJeGegenstand = $momente;

    expect((new Abgleich($this->anlass))->fahren(haeppchen: 2)['angelegt'])->toBe(7);
    expect(Reminder::count())->toBe(7);
});

it('nennt die Kennungen der verschobenen Zeilen', function () {
    // Nicht nur Information: Ein Produkt kann eine EIGENE Einmal-Sperre
    // fuehren, die das Paket nicht kennt — AI Brain haelt einen Cache-
    // Schluessel `erinnerung:gemeldet:{id}`, aelter als `delivered_at`. Ohne
    // diese Liste vergisst es ihn nicht, und die verschobene Erinnerung feuert
    // zum neuen Zeitpunkt NIE. Lautlos, weil die Zeile richtig aussieht.
    fahren($this->anlass);
    $e = Reminder::query()->first();

    $this->anlass->momenteJeGegenstand[$this->vorgang->getKey()]['test:eins'] = now()->addDays(4)->startOfSecond();

    $bilanz = fahren($this->anlass);

    expect($bilanz['verschobene_ids'])->toBe([$e->id]);

    // Und ohne Verschiebung bleibt die Liste leer — sonst wuerde ein Produkt
    // seine Sperre bei jedem Lauf wegwerfen.
    expect(fahren($this->anlass)['verschobene_ids'])->toBe([]);
});
