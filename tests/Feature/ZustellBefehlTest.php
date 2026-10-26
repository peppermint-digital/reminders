<?php

use Peppermint\Notifications\Models\Notification;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

/*
| Der Zustell-Befehl, den jedes Produkt bekommt (#8803).
|
| Er liegt hier, weil er sonst viermal hier laege. Am 26.10.2026 gemessen:
| Von vier Produkten hatten zwei einen Zustell-Lauf und zwei keinen — im
| Peppermint Manager konnte man eine Erinnerung anlegen, und niemand bekam
| sie je. Bug #880 unverändert, nur in einem anderen Fenster.
*/

beforeEach(function () {
    $this->person = Person::create(['name' => 'Bastian']);
    $this->vorgang = Vorgang::create(['name' => 'Angebot']);
    config()->set('reminders.delivery.type', 'erinnerung');
});

function befehlsErinnerung(array $felder = []): Reminder
{
    return Reminder::create(array_merge([
        'subject_type' => test()->vorgang->getMorphClass(),
        'subject_id' => test()->vorgang->getKey(),
        'user_id' => test()->person->getKey(),
        'remind_at' => now()->subMinute(),
        'title' => 'Nachfassen',
    ], $felder));
}

it('ist registriert, weil das Meldungswesen da ist', function () {
    expect(array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all()))
        ->toContain('erinnerungen:zustellen');
});

it('sagt es, wenn nichts faellig ist', function () {
    befehlsErinnerung(['remind_at' => now()->addDay()]);

    $this->artisan('erinnerungen:zustellen')
        ->expectsOutputToContain('Keine faelligen Erinnerungen')
        ->assertExitCode(0);

    expect(Notification::query()->count())->toBe(0);
});

it('stellt zu, vermerkt es und erzeugt die Meldung', function () {
    $e = befehlsErinnerung();

    $this->artisan('erinnerungen:zustellen')->assertExitCode(0);

    expect($e->fresh()->delivered_at)->not->toBeNull();
    expect(Notification::query()->count())->toBe(1);
    expect((int) Notification::query()->first()->user_id)->toBe($this->person->getKey());
});

it('stellt zweimal hintereinander nicht doppelt zu', function () {
    befehlsErinnerung();

    $this->artisan('erinnerungen:zustellen')->assertExitCode(0);
    $this->artisan('erinnerungen:zustellen')->assertExitCode(0);

    expect(Notification::query()->count())->toBe(1);
});

it('nennt den Rest, wenn der Deckel greift', function () {
    // Ein Deckel, der still kuerzt, liest sich wie „alles erledigt" — und
    // beim naechsten Lauf wieder.
    foreach (range(1, 3) as $i) {
        befehlsErinnerung(['remind_at' => now()->subMinutes($i)]);
    }

    $this->artisan('erinnerungen:zustellen', ['--limit' => 1])
        ->expectsOutputToContain('warten noch')
        ->assertExitCode(0);

    expect(Notification::query()->count())->toBe(1);
    expect(Reminder::query()->undelivered()->count())->toBe(2);
});

it('laesst Geschlummertes und Abgehaktes liegen', function () {
    befehlsErinnerung(['is_dismissed' => true]);
    befehlsErinnerung()->snooze(60);

    $this->artisan('erinnerungen:zustellen')
        ->expectsOutputToContain('Keine faelligen Erinnerungen')
        ->assertExitCode(0);

    expect(Notification::query()->count())->toBe(0);
});
