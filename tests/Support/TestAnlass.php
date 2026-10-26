<?php

namespace Peppermint\Reminders\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Reminders\Abgleich\ErinnerungsAnlass;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

/**
 * Ein Anlass, dessen Antworten der Test setzt.
 *
 * Damit prueft die Reihe den ABGLEICH und nicht die Produktlogik. Welche
 * Zeitpunkte ein Gegenstand hat, ist in jedem Produkt anders und dort
 * getestet; was hier interessiert, ist alles andere: anlegen, verschieben,
 * Abgehaktes in Ruhe lassen, Verwaistes wegnehmen, zaehlen.
 */
class TestAnlass implements ErinnerungsAnlass
{
    /** @var array<int, string> */
    public array $quellen = ['test:eins'];

    /** @var array<array-key, array<string, \DateTimeInterface>> */
    public array $momenteJeGegenstand = [];

    /** @var array<array-key, int|string|null> */
    public array $empfaengerJeGegenstand = [];

    public int|string|null $empfaengerVorgabe = null;

    /** @var array<int, array-key>|null */
    public ?array $nurDiese = null;

    public function quellen(): array
    {
        return $this->quellen;
    }

    public function gegenstandsKlasse(): string
    {
        return Vorgang::class;
    }

    public function gegenstaende()
    {
        $abfrage = Vorgang::query();

        // `null` heisst „alle" — eine Liste schraenkt ein, und genau darueber
        // faellt ein Gegenstand aus der Menge, was das Aufraeumen ausloest.
        if ($this->nurDiese !== null) {
            $abfrage->whereKey($this->nurDiese);
        }

        return $abfrage;
    }

    public function momente(Model $gegenstand): array
    {
        return $this->momenteJeGegenstand[$gegenstand->getKey()] ?? [];
    }

    public function wortlaut(Model $gegenstand, string $quelle): string
    {
        return "{$quelle} an {$gegenstand->name}";
    }

    public function empfaenger(Model $gegenstand): int|string|null
    {
        return $this->empfaengerJeGegenstand[$gegenstand->getKey()] ?? $this->empfaengerVorgabe;
    }
}
