<?php

namespace Peppermint\Reminders\Abgleich;

use Illuminate\Database\Eloquent\Model;

/**
 * Die Haelfte eines Anlasses, die NICHT von den Empfaengern abhaengt.
 *
 * ## Warum es diese Zwischenstufe gibt
 *
 * Seit dem 29.10.2026 gibt es zwei Sorten Anlass, und sie unterscheiden sich
 * in genau einer Frage: Erinnert dieser Gegenstand EINEN Menschen
 * ({@see ErinnerungsAnlass}) oder mehrere, jeden zu seiner eigenen Zeit
 * ({@see AnlassMitEmpfaengern})?
 *
 * Alles andere — welche Quellen er besitzt, welche Klasse seine Gegenstaende
 * haben, welche gerade infrage kommen, wie die Meldung heisst — ist bei beiden
 * dasselbe und steht deshalb hier. Der {@see Abgleich} nimmt diese Stufe und
 * fragt erst danach, welche der beiden Sorten vorliegt.
 *
 * Herausgezogen, nicht neu erfunden: Die vier Methoden standen bis zum
 * 29.10.2026 wortgleich in `ErinnerungsAnlass`. Wer dieses Interface
 * implementiert hat, muss nichts aendern — es verlangt unveraendert dieselben
 * Methoden.
 */
interface Anlass
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

    /** Was in der Meldung steht. Worte gehoeren dem Produkt. */
    public function wortlaut(Model $gegenstand, string $quelle): string;
}
