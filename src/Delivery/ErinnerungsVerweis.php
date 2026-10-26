<?php

namespace Peppermint\Reminders\Delivery;

use Peppermint\Reminders\Models\Reminder;

/**
 * Woran eine Erinnerung haengt — in den Worten und Adressen des Produkts.
 *
 * Das Paket weiss, DASS eine Erinnerung einen Gegenstand hat, und nie, wie man
 * dorthin kommt. Eine Adresse ist Produktwissen: `route('tasks.show', …)` gibt
 * es hier nicht, und ein Paket, das Adressen baut, liefert eine Anwendung aus.
 *
 * ## Warum das ueberhaupt gebraucht wird
 *
 * Ohne Verweis ist die Meldung eine Zeile ohne Ziel. Das CRM hatte dafuer eine
 * eigene Mail mit einem Knopf „Aufgabe oeffnen" — beim Umzug auf den geteilten
 * Weg waere der weggefallen, und zwar ohne dass ein Test rot geworden waere.
 * Genau die Sorte Feinheit, die der Paketvertrag einzeln entschieden haben
 * will, statt sie beim Verschmelzen zu verlieren.
 *
 * Ein Produkt bindet eine Umsetzung im Container; ohne Bindung bleibt die
 * Meldung schlicht, und das ist ein zulaessiger Zustand.
 */
interface ErinnerungsVerweis
{
    /**
     * @return array{link?: string|null, icon?: string|null, title?: string|null}
     *         Was davon fehlt, bleibt leer — es ist kein Fehler, zu einem
     *         Gegenstand nichts sagen zu koennen.
     */
    public function fuer(Reminder $erinnerung): array;
}
