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
 * ## Diese Sorte erinnert EINEN Menschen
 *
 * Fuer einen Gegenstand mit einer zustaendigen Person: Eine Aufgabe hat ihren
 * Bearbeiter, und wechselt der, WANDERT die Erinnerung zu ihm. Wer mehrere
 * Menschen zu demselben Gegenstand erinnern will — jeden zu seiner eigenen
 * Zeit —, nimmt {@see AnlassMitEmpfaengern}.
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
interface ErinnerungsAnlass extends Anlass
{
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

    /**
     * Wer erinnert wird, oder null.
     *
     * Null ist eine Antwort und kein Fehler — der Abgleich zaehlt sie und
     * nennt sie, statt sie zu verschweigen.
     */
    public function empfaenger(Model $gegenstand): int|string|null;
}
