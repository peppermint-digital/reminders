<?php

namespace Peppermint\Reminders\Abgleich;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Anlass, der MEHRERE Menschen zu demselben Gegenstand erinnert — jeden
 * zu seiner eigenen Zeit.
 *
 * ## Woran es gefehlt hat
 *
 * Gemessen am 29.10.2026 an Bug #1080 im Peppermint Manager: Chris bekam keine
 * Terminerinnerungen, und der Grund war nicht der abgeschaltete Schalter
 * allein. `EventReminders::empfaenger()` gab `owner_id` zurueck — den
 * Organisator. Bei seinen Terminen ist Chris TEILNEHMER. Auch eingeschaltet
 * haette er nie etwas bekommen, und zwar lautlos: Der Lauf meldet „angelegt",
 * nur fuer jemand anderen.
 *
 * Dazu kommt, dass der Vorlauf je Person einstellbar sein soll (keine / 15 /
 * 30 Minuten). Damit hat derselbe Termin nicht einen Zeitpunkt, sondern einen
 * je Mensch — und `momente()` kann das nicht ausdruecken, weil es die Person
 * nicht kennt.
 *
 * ## Warum das eine zweite Sorte ist und keine Erweiterung der ersten
 *
 * Weil der einfache Pfad eine Eigenschaft hat, die der mehrfache nicht haben
 * darf: Wechselt dort die zustaendige Person, WANDERT die bestehende Zeile zu
 * ihr — eine Aufgabe, eine Erinnerung, ein neuer Bearbeiter. Bei mehreren
 * Menschen ist derselbe Vorgang etwas anderes: Jemand kommt dazu, jemand
 * faellt weg. Eine Zeile, die dabei wandert, wuerde einem Dritten den Verlauf
 * eines anderen unterschieben — samt dessen `delivered_at` und dessen
 * Abgehaktem.
 *
 * Deshalb bleibt {@see ErinnerungsAnlass} unveraendert, und das Aufgaben-Paket
 * merkt von dieser Sorte nichts.
 */
interface AnlassMitEmpfaengern extends Anlass
{
    /**
     * Wer erinnert wird, und wann — je Person je Quelle ein Zeitpunkt, in UTC.
     *
     * Eine Person, die hier FEHLT, bekommt keine Erinnerung; eine vorhandene
     * Zeile von ihr nimmt der Abgleich weg. Genau so ist „ich will zu Terminen
     * nicht erinnert werden" ausdrueckbar, ohne ein zweites Feld dafuer.
     *
     * Eine leere Antwort ist zulaessig und heisst „dieser Gegenstand erinnert
     * gerade niemanden".
     *
     * ## Warum der Abgleich hier NICHT „ohne Empfaenger" zaehlt
     *
     * Beim einfachen Anlass ist `null` ein Befund: Die Erinnerung wuerde
     * niemanden erreichen, und niemand weiss davon. Hier ist eine leere Liste
     * der Normalfall — ein Termin, bei dem alle Beteiligten Erinnerungen
     * abgestellt haben, sieht genauso aus. Wuerde der Abgleich das melden,
     * warnte der Zeitplan dauerhaft ueber eine Entscheidung, die Menschen
     * bewusst getroffen haben. Wer einen echten Fehlstand erkennen kann — ein
     * Termin ganz ohne Beteiligte —, meldet ihn selbst.
     *
     * @return array<int|string, array<string, DateTimeInterface>>  Person => Quelle => Zeitpunkt
     */
    public function empfaengerMomente(Model $gegenstand): array;
}
