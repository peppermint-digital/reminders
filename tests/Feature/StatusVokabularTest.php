<?php

use Illuminate\Support\Facades\DB;
use Peppermint\Reminders\Delivery\DueReminders;
use Peppermint\Reminders\Exceptions\UnknownReminderStatus;
use Peppermint\Reminders\Models\Reminder;
use Peppermint\Reminders\Status\StatusRegistry;
use Peppermint\Reminders\Tests\Fixtures\Person;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;
use Peppermint\Reminders\Tests\Support\Wartend;
use Peppermint\Reminders\Tests\Support\Weggeworfen;
use Peppermint\Reminders\Tests\Support\ZurKenntnis;

beforeEach(function () {
    $this->person = Person::create(['name' => 'Bastian']);
    $this->vorgang = Vorgang::create(['name' => 'Angebot']);
});

function mitVokabular(array $klassen = [Wartend::class, ZurKenntnis::class, Weggeworfen::class]): StatusRegistry
{
    config()->set('reminders.statuses', $klassen);

    // Das Verzeichnis ist ein Singleton und wurde beim Hochfahren gebaut.
    app()->forgetInstance(StatusRegistry::class);

    return app(StatusRegistry::class);
}

function erinnerungMitStatus(array $felder = []): Reminder
{
    return Reminder::create(array_merge([
        'subject_type' => test()->vorgang->getMorphClass(),
        'subject_id' => test()->vorgang->getKey(),
        'user_id' => test()->person->getKey(),
        'remind_at' => now()->subMinute(),
    ], $felder));
}

it('laesst ohne Vokabular den Bool regieren — und stellt weiter zu', function () {
    // DER kritische Fall. Wuerde ein leeres Verzeichnis als „kein Zustand
    // wartet" gelesen, traefe scopeActive nichts und der Lauf stellte still
    // nichts mehr zu. Genau die Form von Bug #880.
    expect(app(StatusRegistry::class)->istLeer())->toBeTrue();

    erinnerungMitStatus();
    erinnerungMitStatus(['is_dismissed' => true]);

    expect(Reminder::query()->active()->count())->toBe(1);
    expect((new DueReminders)->holen())->toHaveCount(1);
});

it('filtert mit Vokabular nach dem wartenden Status', function () {
    mitVokabular();

    erinnerungMitStatus(['status' => 'pending']);
    erinnerungMitStatus(['status' => 'acknowledged']);
    erinnerungMitStatus(['status' => 'dismissed']);

    expect(Reminder::query()->active()->pluck('status')->all())->toBe(['pending']);
    expect((new DueReminders)->holen())->toHaveCount(1);
});

it('stellt eine Zeile OHNE Status weiter zu — die Bruecke waehrend der Wanderung', function () {
    // Ein schlichtes whereIn schloesse sie aus. Die Erinnerung sieht heil aus
    // und erreicht niemanden.
    mitVokabular();

    erinnerungMitStatus(['status' => null]);

    expect(Reminder::query()->active()->count())->toBe(1);
    expect((new DueReminders)->holen())->toHaveCount(1);
});

it('macht is_dismissed zum harten Riegel, egal was im Status steht', function () {
    // Haelt das aeltere dismiss() neben einem Vokabular ehrlich: Sonst behielte
    // eine so weggeklickte Zeile ihren wartenden Status und wuerde weiter
    // zugestellt.
    mitVokabular();

    erinnerungMitStatus(['status' => 'pending', 'is_dismissed' => true]);

    expect(Reminder::query()->active()->count())->toBe(0);
    expect((new DueReminders)->holen())->toHaveCount(0);
});

it('haelt beim Setzen des Status den Bool in Gleichschritt', function () {
    mitVokabular();
    $e = erinnerungMitStatus(['status' => 'pending']);

    $e->setzeStatus('dismissed');

    expect($e->fresh()->is_dismissed)->toBeTrue();
    expect($e->fresh()->dismissed_at)->not->toBeNull();
    expect(Reminder::query()->active()->count())->toBe(0);
});

it('holt eine Erinnerung zurueck ins Wartende und raeumt den Abschluss weg', function () {
    mitVokabular();
    $e = erinnerungMitStatus(['status' => 'dismissed', 'is_dismissed' => true, 'dismissed_at' => now()]);

    $e->setzeStatus('pending');

    expect($e->fresh()->is_dismissed)->toBeFalse();
    expect($e->fresh()->dismissed_at)->toBeNull();
    expect(Reminder::query()->active()->count())->toBe(1);
});

it('verschiebt den Abschluss-Zeitpunkt beim zweiten Mal nicht', function () {
    mitVokabular();
    $e = erinnerungMitStatus(['status' => 'pending']);

    $e->setzeStatus('acknowledged');
    $zuerst = $e->fresh()->dismissed_at;

    $e->fresh()->setzeStatus('dismissed');

    expect($e->fresh()->dismissed_at->eq($zuerst))->toBeTrue();
});

it('weist einen nicht angemeldeten Status ab, statt ihn zu speichern', function () {
    mitVokabular();
    $e = erinnerungMitStatus(['status' => 'pending']);

    $e->setzeStatus('erfunden');
})->throws(UnknownReminderStatus::class, 'nicht angemeldet');

it('trennt wartende von abgeschlossenen Schluesseln', function () {
    $v = mitVokabular();

    expect($v->istLeer())->toBeFalse();
    expect($v->wartendeSchluessel())->toBe(['pending']);
    expect($v->abgeschlosseneSchluessel())->toBe(['acknowledged', 'dismissed']);
});

it('gibt den angemeldeten Eintrag zur Zeile heraus', function () {
    mitVokabular();
    $e = erinnerungMitStatus(['status' => 'acknowledged']);

    expect($e->statusEintrag()?->label())->toBe('Zur Kenntnis genommen');
    expect($e->statusEintrag()?->wartetNoch())->toBeFalse();

    // Ohne Status gibt es nichts herauszugeben — und das ist kein Fehler.
    expect(erinnerungMitStatus(['status' => null])->statusEintrag())->toBeNull();
});

it('fuellt den Status aus dem Bool nach — in beide Richtungen', function () {
    config()->set('reminders.legacy_status', ['waiting' => 'pending', 'dismissed' => 'dismissed']);

    $offen = erinnerungMitStatus(['status' => null, 'is_dismissed' => false]);
    $zu = erinnerungMitStatus(['status' => null, 'is_dismissed' => true]);

    (require __DIR__.'/../../database/migrations/2026_10_26_000100_upgrade_adopted_reminders_table.php')->up();

    expect($offen->fresh()->status)->toBe('pending');
    expect($zu->fresh()->status)->toBe('dismissed');

    // Der Bool bleibt — der Rueckweg ist eine Konfigurationsaenderung.
    expect($zu->fresh()->is_dismissed)->toBeTrue();
});

it('fuellt NICHTS nach, wenn das Produkt seine Woerter nicht genannt hat', function () {
    // Ein falsch gefuellter Status heisst: eine wartende Erinnerung gilt als
    // erledigt und erreicht niemanden. Lieber nichts tun.
    config()->set('reminders.legacy_status', null);

    $e = erinnerungMitStatus(['status' => null, 'is_dismissed' => true]);

    (require __DIR__.'/../../database/migrations/2026_10_26_000100_upgrade_adopted_reminders_table.php')->up();

    expect($e->fresh()->status)->toBeNull();
});

it('fasst einen bereits gesetzten Status nicht an', function () {
    config()->set('reminders.legacy_status', ['waiting' => 'pending', 'dismissed' => 'dismissed']);

    $e = erinnerungMitStatus(['status' => 'acknowledged', 'is_dismissed' => true]);

    (require __DIR__.'/../../database/migrations/2026_10_26_000100_upgrade_adopted_reminders_table.php')->up();

    expect($e->fresh()->status)->toBe('acknowledged');
});
