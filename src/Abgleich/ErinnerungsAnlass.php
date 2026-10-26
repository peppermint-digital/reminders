<?php

namespace Peppermint\Reminders\Abgleich;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Woraus Erinnerungen entstehen — die Haelfte, die das Produkt beantwortet.
 *
 * Ein Anlass sagt, WELCHE Dinge Erinnerungen verdienen und WANN. Alles andere
 * — anlegen, verschieben, Abgehaktes in Ruhe lassen, Verwaistes wegnehmen,
 * Bilanz fuehren — macht {@see Abgleich}, einmal fuer alle.
 *
 * ## Warum es diese Fuge gibt
 *
 * Am 26.10.2026 gemessen, als der Kalender als zweiter Abnehmer dazukam:
 * `DueDateReminders` (Aufgaben) und `EventReminders` (Kalender) waren 238 und
 * 247 Zeilen mit denselben Bauteilen. Verschieden war genau eines — welche
 * Zeitpunkte der Gegenstand hat. Der Paketvertrag nennt das Pruefrage 3, und
 * sie stand auf JA.
 *
 * Dieselbe Form hatte das Postfach-Paket: Nach dem zweiten Abnehmer standen
 * dort fuenf handgeschriebene Quellen, von denen vier dasselbe taten.
 */
interface ErinnerungsAnlass
{
    /**
     * Die Quellen, die DIESER Anlass besitzt.
     *
     * `source` trennt erzeugte Zeilen von denen, die ein Mensch gesetzt hat —
     * und trennt auch zwei Anlaesse voneinander. Ein Abgleich raeumt nur
     * zwischen seinen eigenen Quellen auf; sonst nimmt er dem Nachbarn die
     * Zeilen weg.
     *
     * @return array<int, string>
     */
    public function quellen(): array;

    /**
     * Die Klasse der Gegenstaende — die des PRODUKTS, nicht die eines Pakets.
     *
     * Gebraucht fuer den Morph-Typ und fuer das Aufraeumen, und deshalb auch
     * dann, wenn gerade gar kein Gegenstand infrage kommt. Steht hier die
     * Klasse eines Pakets, traegt `subject_type` ein Modell, das niemand
     * instanziiert — und nichts findet die Erinnerungen wieder. Lautlos.
     *
     * @return class-string<Model>
     */
    public function gegenstandsKlasse(): string;

    /**
     * Die Gegenstaende, die gerade Erinnerungen verdienen.
     *
     * Als Abfrage, nicht als Liste: Der Abgleich arbeitet in Haeppchen, weil
     * „alle offenen Aufgaben" in einem gewachsenen Produkt keine Handvoll ist.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    public function gegenstaende();

    /**
     * Wann erinnert wird — je Quelle ein Zeitpunkt, in UTC.
     *
     * Hier liegt das eigentliche Produktwissen, und hier gehoeren die
     * Sonderfaelle hin: ein Ganztagstermin, der nicht um Mitternacht erinnern
     * soll; eine Vorwarnung, die wegfaellt, sobald der Tag selbst dran ist.
     *
     * Eine leere Antwort ist zulaessig und heisst „dieser Gegenstand verdient
     * gerade keine" — der Abgleich raeumt dann seine Zeilen weg.
     *
     * @return array<string, DateTimeInterface>
     */
    public function momente(Model $gegenstand): array;

    /** Was in der Meldung steht. Worte gehoeren dem Produkt. */
    public function wortlaut(Model $gegenstand, string $quelle): string;

    /**
     * Wer erinnert wird, oder null.
     *
     * Null ist eine Antwort und kein Fehler — der Abgleich zaehlt sie und
     * nennt sie, statt sie zu verschweigen.
     */
    public function empfaenger(Model $gegenstand): int|string|null;
}
