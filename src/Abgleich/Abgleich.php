<?php

namespace Peppermint\Reminders\Abgleich;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Peppermint\Reminders\Models\Reminder;

/**
 * Bringt erzeugte Erinnerungen in Einklang mit ihrem Anlass — einmal fuer alle.
 *
 * ## Was „Abgleich" heisst
 *
 * Nicht „einmal anlegen und vergessen". Ein Anlass, der sich verschiebt, nimmt
 * seine Erinnerung mit; einer, der wegfaellt, nimmt sie weg; einer, der
 * erledigt ist, hoert auf zu mahnen. Keines davon laesst sich als Formel
 * ausdruecken, die beim Lesen gerechnet wird — darum bleibt die Zeile absolut
 * und eine Regel besitzt sie (AI Brain #5808).
 *
 * ## Warum erzeugen und nicht verschicken
 *
 * Kuerzer waere, direkt zu benachrichtigen. Es waere auch ein ZWEITER
 * Zustellweg neben den Erinnerungen, die Menschen selbst setzen — mit eigener
 * Einmal-Sperre, eigener Vorstellung von „schon gesagt", eigener Drift. Genau
 * die Lage, aus der Bug #880 entstand.
 *
 * Also entsteht eine ganz normale {@see Reminder}. Zustellung, Glocke,
 * Schlummern, Abhaken und Verlauf sind die, die es schon gibt — und die
 * erzeugte Erinnerung steht in derselben Liste wie die handgesetzten.
 *
 * ## Zwei Sorten Anlass, ein Abgleich
 *
 * Seit dem 29.10.2026 gibt es neben {@see ErinnerungsAnlass} (eine
 * zustaendige Person) auch {@see AnlassMitEmpfaengern} (mehrere, jede zu ihrer
 * eigenen Zeit). Der Unterschied steckt in genau zwei Stellen dieser Klasse —
 * {@see passende()} und {@see ueberzaehligeWegnehmen()} —, und er ist kein
 * Schoenheitsfehler, sondern der Kern:
 *
 * | | wechselt die Person | ueberzaehlige Zeile |
 * |---|---|---|
 * | eine zustaendige | die Zeile WANDERT zu ihr | je Quelle |
 * | mehrere | eine kommt dazu, eine faellt weg | je Quelle UND Person |
 *
 * Eine wandernde Zeile bei mehreren Empfaengern wuerde einem Dritten den
 * Verlauf eines anderen unterschieben, samt `delivered_at` und Abgehaktem.
 */
class Abgleich
{
    public function __construct(private readonly Anlass $anlass) {}

    /**
     * @return array{angelegt: int, verschoben: int, weggenommen: int, ohne_empfaenger: array<int, mixed>, verschobene_ids: array<int, mixed>}
     */
    public function fahren(int $haeppchen = 200): array
    {
        $bilanz = [
            'angelegt' => 0,
            'verschoben' => 0,
            'weggenommen' => 0,
            'ohne_empfaenger' => [],
            // Die Kennungen der verschobenen Zeilen.
            //
            // Nicht nur Information: Ein Produkt kann eine EIGENE Einmal-Sperre
            // fuehren, die das Paket nicht kennt. AI Brain tut genau das — ein
            // Cache-Schluessel `erinnerung:gemeldet:{id}`, aelter als
            // `delivered_at`. Verschiebt sich eine Zeile, muss das Produkt
            // seinen Schluessel vergessen, sonst feuert die Erinnerung zum
            // neuen Zeitpunkt nie. Lautlos: Die Zeile sieht richtig aus.
            //
            // `delivered_at` setzt der Abgleich selbst zurueck; diese Liste ist
            // fuer alles, was er nicht kennen kann.
            'verschobene_ids' => [],
        ];

        // Aufraeumen ZUERST. Ein Gegenstand, der aus der Menge gefallen ist,
        // soll seine Erinnerung verlieren, bevor irgendetwas Neues entsteht —
        // sonst zaehlt ein Lauf eine Zeile als „verschoben", die er im selben
        // Durchgang haette wegnehmen muessen.
        $bilanz['weggenommen'] = $this->verwaisteWegnehmen();

        $this->anlass->gegenstaende()->chunkById($haeppchen, function (Collection $gegenstaende) use (&$bilanz): void {
            $vorhandene = $this->vorhandeneZu($gegenstaende);

            foreach ($gegenstaende as $gegenstand) {
                $gewollt = $this->gewollt($gegenstand, $bilanz);

                if ($gewollt === null) {
                    continue;
                }

                $zeilen = $vorhandene[$gegenstand->getKey()] ?? [];

                foreach ($gewollt as $quelle => $jePerson) {
                    foreach ($jePerson as $person => $zeitpunkt) {
                        $this->eine(
                            $gegenstand,
                            (string) $quelle,
                            $zeitpunkt,
                            $person,
                            $this->passende($zeilen, (string) $quelle, $person),
                            $bilanz
                        );
                    }
                }

                // Und wegnehmen, was dieser Gegenstand nicht mehr verdient.
                //
                // Nicht derselbe Fall wie „aus der Menge gefallen": Der
                // Gegenstand ist noch da, nur eine seiner Quellen hat keinen
                // Zeitpunkt mehr — die Vorwarnung faellt weg, weil der Tag
                // selbst schon dran ist. Oder, bei mehreren Empfaengern: Eine
                // Person ist kein Teilnehmer mehr. Ohne das mahnt eine Zeile zu
                // einem Anlass, den es fuer sie nicht mehr gibt.
                $bilanz['weggenommen'] += $this->ueberzaehligeWegnehmen($gewollt, $zeilen);
            }
        });

        return $bilanz;
    }

    /**
     * Wer wann erinnert werden soll — fuer beide Sorten Anlass dieselbe Form.
     *
     * `null` heisst „uebergangen und gezaehlt"; das gibt es nur beim einfachen
     * Anlass, bei dem eine fehlende Person ein Befund ist. Die Begruendung,
     * warum eine leere Liste bei mehreren Empfaengern KEIN Befund ist, steht
     * an {@see AnlassMitEmpfaengern::empfaengerMomente()}.
     *
     * @param  array{angelegt: int, verschoben: int, weggenommen: int, ohne_empfaenger: array<int, mixed>, verschobene_ids: array<int, mixed>}  $bilanz
     * @return array<string, array<int|string, DateTimeInterface>>|null  Quelle => Person => Zeitpunkt
     */
    private function gewollt(Model $gegenstand, array &$bilanz): ?array
    {
        if ($this->anlass instanceof AnlassMitEmpfaengern) {
            $nachQuelle = [];

            foreach ($this->anlass->empfaengerMomente($gegenstand) as $person => $momente) {
                foreach ($momente as $quelle => $zeitpunkt) {
                    $nachQuelle[(string) $quelle][$person] = $zeitpunkt;
                }
            }

            return $nachQuelle;
        }

        /** @var ErinnerungsAnlass $anlass */
        $anlass = $this->anlass;

        $empfaenger = $anlass->empfaenger($gegenstand);

        if ($empfaenger === null) {
            // Gezaehlt, nicht still uebergangen. Ein Lauf, der leise nichts
            // tut, meldet Erfolg — und die Luecke faellt erst Monate spaeter
            // auf, wenn jemand fragt, warum er nie etwas gehoert hat.
            $bilanz['ohne_empfaenger'][] = $gegenstand->getKey();

            return null;
        }

        $nachQuelle = [];

        foreach ($anlass->momente($gegenstand) as $quelle => $zeitpunkt) {
            $nachQuelle[(string) $quelle][$empfaenger] = $zeitpunkt;
        }

        return $nachQuelle;
    }

    /**
     * Die vorhandene Zeile, auf die dieser Wunsch passt.
     *
     * Hier liegt der eine Unterschied zwischen den beiden Sorten Anlass:
     *
     * - **Eine zustaendige Person.** Gesucht wird nur ueber die QUELLE. Traegt
     *   die Zeile jemand anderen, ist das eine Zuweisung, die gewechselt hat,
     *   und die Zeile wandert mit. Das ist das Verhalten, das das
     *   Aufgaben-Paket seit Jahren hat.
     * - **Mehrere.** Gesucht wird ueber Quelle UND Person. Eine Zeile einer
     *   anderen Person gehoert dieser anderen Person — sie zu uebernehmen
     *   wuerde deren Verlauf verfaelschen und ihr die Erinnerung nehmen.
     *
     * @param  array<string, array<int, Reminder>>  $zeilen
     */
    private function passende(array $zeilen, string $quelle, int|string $person): ?Reminder
    {
        $derQuelle = $zeilen[$quelle] ?? [];

        if (! $this->anlass instanceof AnlassMitEmpfaengern) {
            return $derQuelle[0] ?? null;
        }

        foreach ($derQuelle as $zeile) {
            if ((string) $zeile->{Reminder::column('user_id')} === (string) $person) {
                return $zeile;
            }
        }

        return null;
    }

    /**
     * Eine Quelle an einem Gegenstand, fuer eine Person.
     *
     * @param  array{angelegt: int, verschoben: int, weggenommen: int, ohne_empfaenger: array<int, mixed>, verschobene_ids: array<int, mixed>}  $bilanz
     */
    private function eine(
        Model $gegenstand,
        string $quelle,
        DateTimeInterface $zeitpunkt,
        int|string $empfaenger,
        ?Reminder $vorhanden,
        array &$bilanz,
    ): void {
        if ($vorhanden === null) {
            Reminder::query()->create([
                Reminder::column('subject_type') => $gegenstand->getMorphClass(),
                Reminder::column('subject_id') => $gegenstand->getKey(),
                Reminder::column('user_id') => $empfaenger,
                Reminder::column('remind_at') => $zeitpunkt,
                Reminder::column('source') => $quelle,
                Reminder::column('title') => $this->anlass->wortlaut($gegenstand, $quelle),
            ]);

            $bilanz['angelegt']++;

            return;
        }

        // Abgehaktes bleibt, wie es ist. Es ist der Nachweis, dass jemand
        // erinnert wurde und gehandelt hat — daran nachtraeglich zu drehen
        // hiesse, die Geschichte umzuschreiben.
        if ($vorhanden->is_dismissed) {
            return;
        }

        $verschoben = ! $vorhanden->remind_at->equalTo($zeitpunkt)
            || (string) $vorhanden->{Reminder::column('user_id')} !== (string) $empfaenger;

        if (! $verschoben) {
            return;
        }

        $vorhanden->forceFill([
            Reminder::column('remind_at') => $zeitpunkt,
            Reminder::column('user_id') => $empfaenger,
            Reminder::column('title') => $this->anlass->wortlaut($gegenstand, $quelle),
            // Die Zustell-Sperre HIER aufheben, nicht beim Aufrufer.
            //
            // Was zum alten Zeitpunkt herausging, gilt nicht mehr; ohne das
            // Zuruecksetzen feuert die verschobene Erinnerung nie — sie gilt
            // als „schon gemeldet", obwohl sie zum neuen Zeitpunkt nichts
            // gesagt hat.
            //
            // Die Aufgaben-Fassung gab dafuer `moved_ids` heraus und verliess
            // sich darauf, dass der Aufrufer es tut. Ein Aufrufer, der es
            // vergisst, erzeugt genau diesen stillen Ausfall — und niemand
            // merkt es, weil die Zeile richtig aussieht.
            Reminder::column('delivered_at') => null,
        ])->save();

        $bilanz['verschoben']++;
        $bilanz['verschobene_ids'][] = $vorhanden->getKey();
    }

    /**
     * Zeilen an EINEM Gegenstand wegnehmen, die der Anlass nicht mehr nennt.
     *
     * ## Das ist BREITER als die Regel, die es vorher gab
     *
     * `DueDateReminders::removeStaleRows()` raeumte je AUFGABE: Was weg war,
     * war die Frist oder die Aufgabe selbst (`whereNotIn('task_id', $offen)`).
     * Hier wird je MOMENT geraeumt — eine Aufgabe kann offen bleiben und ihre
     * Vorwarn-Zeile trotzdem verlieren, weil `momente()` den Vorlauf
     * weglaesst, sobald der Fristtag selbst vorbei ist.
     *
     * Gemessen in AI Brain am 26.10.2026, 21 lebende Zeilen: Der Unterschied
     * traf fuenf `due:lead`-Zeilen zu noch offenen Aufgaben. Jede dieser
     * Aufgaben behielt ihre `due:day`-Zeile — verloren ging nur ein
     * Vorwarn-Moment, der nie wiederkommen kann. Keine Aufgabe wurde
     * sprachlos.
     *
     * ## Je Quelle oder je Quelle UND Person
     *
     * Bei einer zustaendigen Person zaehlt nur die Quelle: Eine Zeile mit
     * fremdem Empfaenger ist dort kein Ueberzaehliger, sondern die Zuweisung
     * von gestern — {@see passende()} greift sie vorher ab und laesst sie
     * wandern.
     *
     * Bei mehreren Empfaengern zaehlt das Paar. Nur so verliert jemand, der
     * kein Teilnehmer mehr ist, seine Erinnerung — und nur so verliert sie
     * NICHT, wer noch einer ist.
     *
     * ## Wo es gefaehrlich bleibt
     *
     * Eine Aufgabe, die NUR eine Vorwarn-Zeile hat und keine zum Fristtag,
     * verliert damit ihre letzte. Entstehen kann das nur, wenn die Zeile zum
     * Tag von Hand entfernt wurde — `momente()` liefert den Tag immer mit.
     * Wer diesen Fall einmal sieht, soll ihn hier nachlesen koennen und nicht
     * raten muessen.
     *
     * ## Was stehen bleibt
     *
     * Eine weggeklickte Zeile. Das ist die Regel aus dem Kommentar der alten
     * Fassung, und sie gilt unveraendert: „Der Verlauf soll zeigen, dass
     * erinnert wurde."
     *
     * @param  array<string, array<int|string, DateTimeInterface>>  $gewollt
     * @param  array<string, array<int, Reminder>>  $zeilen
     */
    private function ueberzaehligeWegnehmen(array $gewollt, array $zeilen): int
    {
        $mehrere = $this->anlass instanceof AnlassMitEmpfaengern;
        $weg = 0;

        foreach ($zeilen as $quelle => $derQuelle) {
            foreach ($derQuelle as $index => $zeile) {
                if ($zeile->is_dismissed) {
                    continue;
                }

                if ($mehrere) {
                    $gehalten = isset($gewollt[(string) $quelle][$zeile->{Reminder::column('user_id')}]);
                } else {
                    // Je Quelle, und nur die Zeile, die {@see passende()}
                    // gegriffen haette. Eine zweite Zeile derselben Quelle
                    // kann der einfache Anlass nicht erzeugt haben; sie
                    // anzufassen waere ein Eingriff in fremde Daten.
                    $gehalten = isset($gewollt[(string) $quelle]) || $index !== 0;
                }

                if ($gehalten) {
                    continue;
                }

                $zeile->delete();
                $weg++;
            }
        }

        return $weg;
    }

    /**
     * Die vorhandenen erzeugten Zeilen, nach Gegenstand und Quelle.
     *
     * Als LISTE je Quelle, nicht als eine Zeile: Bei mehreren Empfaengern hat
     * dieselbe Quelle am selben Gegenstand eine Zeile je Person. Der einfache
     * Pfad nimmt davon weiter die erste und verhaelt sich unveraendert.
     *
     * Eager je Haeppchen, nicht ueber eine Beziehung am Gegenstand: Das
     * Erinnerungs-Paket ist fuer die aufnehmenden Pakete ein `suggest`, und
     * eine Beziehung, deren Rumpf eine fehlende Klasse nennt, bricht beim
     * Aufruf. Dazu ist der Name `reminders` in Produkten vergeben — daran hat
     * das Aufgaben-Paket schon einen halben Tag verloren.
     *
     * @param  Collection<int, Model>  $gegenstaende
     * @return array<array-key, array<string, array<int, Reminder>>>
     */
    private function vorhandeneZu(Collection $gegenstaende): array
    {
        if ($gegenstaende->isEmpty()) {
            return [];
        }

        return Reminder::query()
            ->where(Reminder::column('subject_type'), $this->morphTyp())
            ->whereIn(Reminder::column('subject_id'), $gegenstaende->modelKeys())
            ->whereIn(Reminder::column('source'), $this->anlass->quellen())
            // Die Reihenfolge entscheidet, welche Zeile der einfache Pfad
            // greift. Die aelteste ist die richtige: Sie ist die, die bisher
            // zugestellt wurde.
            ->orderBy((new Reminder)->getKeyName())
            ->get()
            ->groupBy([
                fn (Reminder $e) => $e->{Reminder::column('subject_id')},
                fn (Reminder $e) => $e->{Reminder::column('source')},
            ])
            ->map(fn ($nachQuelle) => $nachQuelle->map(fn ($zeilen) => $zeilen->values()->all())->all())
            ->all();
    }

    /**
     * Zeilen wegnehmen, deren Anlass es nicht mehr gibt.
     *
     * NUR die noch offenen und NUR die eigenen Quellen. Eine abgehakte bleibt
     * stehen, auch wenn ihr Anlass entfallen ist: Der Verlauf soll zeigen,
     * dass erinnert wurde. Und eine Zeile einer fremden Quelle gehoert einem
     * anderen Anlass — oder einem Menschen.
     */
    private function verwaisteWegnehmen(): int
    {
        $offen = $this->anlass->gegenstaende()->pluck(
            (new ($this->anlass->gegenstandsKlasse()))->getKeyName()
        );

        return Reminder::query()
            ->where(Reminder::column('subject_type'), $this->morphTyp())
            ->whereIn(Reminder::column('source'), $this->anlass->quellen())
            ->where(Reminder::column('is_dismissed'), false)
            ->whereNotIn(Reminder::column('subject_id'), $offen)
            ->delete();
    }

    private function morphTyp(): string
    {
        return (new ($this->anlass->gegenstandsKlasse()))->getMorphClass();
    }
}
