<?php

namespace Peppermint\Reminders\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Reminders\Abgleich\AnlassMitEmpfaengern;
use Peppermint\Reminders\Tests\Fixtures\Vorgang;

/**
 * Ein Anlass mit mehreren Empfaengern, dessen Antworten der Test setzt.
 *
 * Das Gegenstueck zu {@see TestAnlass}: Hier interessiert, dass derselbe
 * Gegenstand mehrere Zeilen traegt — eine je Person, jede mit eigenem
 * Zeitpunkt — und dass ein Wegfall nur die Zeile der betroffenen Person
 * trifft.
 */
class TestMehrfachAnlass implements AnlassMitEmpfaengern
{
    /** @var array<int, string> */
    public array $quellen = ['test:eins'];

    /** @var array<array-key, array<int|string, array<string, \DateTimeInterface>>> */
    public array $jeGegenstand = [];

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

        if ($this->nurDiese !== null) {
            $abfrage->whereKey($this->nurDiese);
        }

        return $abfrage;
    }

    public function wortlaut(Model $gegenstand, string $quelle): string
    {
        return "{$quelle} an {$gegenstand->name}";
    }

    public function empfaengerMomente(Model $gegenstand): array
    {
        return $this->jeGegenstand[$gegenstand->getKey()] ?? [];
    }
}
