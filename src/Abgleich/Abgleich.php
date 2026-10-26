<?php

namespace Peppermint\Reminders\Abgleich;

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
 */
class Abgleich
{
    public function __construct(private readonly ErinnerungsAnlass $anlass) {}

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
                $empfaenger = $this->anlass->empfaenger($gegenstand);

                if ($empfaenger === null) {
                    // Gezaehlt, nicht still uebergangen. Ein Lauf, der leise
                    // nichts tut, meldet Erfolg — und die Luecke faellt erst
                    // Monate spaeter auf, wenn jemand fragt, warum er nie
                    // etwas gehoert hat.
                    $bilanz['ohne_empfaenger'][] = $gegenstand->getKey();

                    continue;
                }

                $momente = $this->anlass->momente($gegenstand);

                foreach ($momente as $quelle => $zeitpunkt) {
                    $this->eine(
                        $gegenstand,
                        (string) $quelle,
                        $zeitpunkt,
                        $empfaenger,
                        $vorhandene[$gegenstand->getKey()][$quelle] ?? null,
                        $bilanz
                    );
                }

                // Und wegnehmen, was dieser Gegenstand nicht mehr verdient.
                //
                // Nicht derselbe Fall wie „aus der Menge gefallen": Der
                // Gegenstand ist noch da, nur eine seiner Quellen hat keinen
                // Zeitpunkt mehr — die Vorwarnung faellt weg, weil der Tag
                // selbst schon dran ist. Ohne das mahnt eine Zeile zu einem
                // Anlass, den es nicht mehr gibt.
                //
                // In beiden heutigen Abnehmern tritt es nicht auf, weil ihre
                // Auswahl schon filtert. Der Vertrag sagt es aber zu, und eine
                // Zusage, die die Umsetzung nicht haelt, ist schlimmer als
                // keine.
                $bilanz['weggenommen'] += $this->ueberzaehligeWegnehmen(
                    $gegenstand,
                    array_map('strval', array_keys($momente)),
                    $vorhandene[$gegenstand->getKey()] ?? []
                );
            }
        });

        return $bilanz;
    }

    /**
     * Eine Quelle an einem Gegenstand.
     */
    private function eine(
        Model $gegenstand,
        string $quelle,
        \DateTimeInterface $zeitpunkt,
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
     * Zeilen an EINEM Gegenstand wegnehmen, deren Quelle keinen Zeitpunkt mehr
     * hat.
     *
     * Abgehaktes bleibt auch hier stehen.
     *
     * @param  array<int, string>  $momentQuellen
     * @param  array<string, Reminder>  $vorhandene
     */
    private function ueberzaehligeWegnehmen(Model $gegenstand, array $momentQuellen, array $vorhandene): int
    {
        $weg = 0;

        foreach ($vorhandene as $quelle => $zeile) {
            if (in_array((string) $quelle, $momentQuellen, true) || $zeile->is_dismissed) {
                continue;
            }

            $zeile->delete();
            $weg++;
        }

        return $weg;
    }

    /**
     * Die vorhandenen erzeugten Zeilen, nach Gegenstand und Quelle.
     *
     * Eager je Haeppchen, nicht ueber eine Beziehung am Gegenstand: Das
     * Erinnerungs-Paket ist fuer die aufnehmenden Pakete ein `suggest`, und
     * eine Beziehung, deren Rumpf eine fehlende Klasse nennt, bricht beim
     * Aufruf. Dazu ist der Name `reminders` in Produkten vergeben — daran hat
     * das Aufgaben-Paket schon einen halben Tag verloren.
     *
     * @param  Collection<int, Model>  $gegenstaende
     * @return array<array-key, array<string, Reminder>>
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
            ->get()
            ->groupBy([
                fn (Reminder $e) => $e->{Reminder::column('subject_id')},
                fn (Reminder $e) => $e->{Reminder::column('source')},
            ])
            ->map(fn ($nachQuelle) => $nachQuelle->map(fn ($zeilen) => $zeilen->first())->all())
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
