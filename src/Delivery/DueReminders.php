<?php

namespace Peppermint\Reminders\Delivery;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Peppermint\Reminders\Models\Reminder;

/**
 * The query a delivery runs: whose, due, open, not yet sent.
 *
 * This is the part of delivery that belongs in the package, and the only
 * part. Every product asks exactly this question in exactly this way — four
 * copies of it is four places for the same off-by-one.
 *
 * The way OUT stays in the product: whether a reminder becomes a bell, a mail
 * or a push is a question about the product's messaging, not about reminders.
 * {@see NotificationsBridge} is the answer when peppermint/notifications is
 * installed, and it is a suggestion, not a requirement.
 */
class DueReminders
{
    /**
     * @return Collection<int, Reminder>
     */
    public function holen(int $hoechstens = 500): Collection
    {
        $erinnerungen = Reminder::query()
            ->active()
            ->due()
            ->undelivered()
            // Oldest first: if a backlog built up, the one that has been
            // waiting longest goes out first. A limit that takes the newest
            // starves the backlog forever.
            ->orderBy(Reminder::column('remind_at'))
            ->limit($hoechstens)
            ->get();

        $this->ladeGegenstaende($erinnerungen);

        return $erinnerungen;
    }

    /**
     * Die Gegenstaende nachladen — eager, und nur die auflösbaren.
     *
     * ## Warum eager
     *
     * Zwei Produkte fahren `preventLazyLoading`. Dort wirft jeder Zugriff auf
     * `$erinnerung->subject` eine `LazyLoadingViolationException` — und zwar
     * MITTEN im Zustell-Lauf, nachdem die erste Erinnerung schon vermerkt war.
     * Gefunden am 26.10.2026 im Peppermint Manager, beim ersten echten
     * Durchlauf gegen eine laufende Anwendung. Die Testreihe dieses Pakets
     * konnte es nicht sehen: In ihr ist die Sperre aus.
     *
     * ## Warum nicht einfach `->with('subject')`
     *
     * Ein `morphTo`-Eagerload loest ALLE vorkommenden Typen auf, und unter
     * Altbestaenden liegen Klassen, die es im Code nicht mehr gibt. Dann
     * wirft das Nachladen und nimmt dem Lauf die ganze Liste — dieselbe
     * Falle, die das Meldungs-Paket in seinem Feed ausdruecklich umgeht
     * („ein `morphTo` darauf wirft beim Aufloesen und nimmt dem Leser die
     * ganze Liste").
     *
     * Deshalb je Typ, und nur wenn er aufloesbar ist. Ein nicht aufloesbarer
     * bleibt ungeladen; wer den Gegenstand braucht, fragt vorher
     * `relationLoaded('subject')`.
     *
     * ## Die Morph-Map
     *
     * Mit erzwungener Morph-Map steht in `subject_type` ein Alias und kein
     * Klassenname — `class_exists()` darauf ist immer falsch. Erst die Karte
     * fragen, dann die Klasse.
     *
     * @param  Collection<int, Reminder>  $erinnerungen
     */
    private function ladeGegenstaende(Collection $erinnerungen): void
    {
        $erinnerungen
            ->groupBy(fn (Reminder $e) => (string) $e->{Reminder::column('subject_type')})
            ->each(function (Collection $gruppe, string $typ): void {
                if ($typ === '') {
                    return;
                }

                $klasse = Relation::getMorphedModel($typ) ?? $typ;

                if (! class_exists($klasse)) {
                    return;
                }

                $gruppe->load('subject');
            });
    }

    /** How many are waiting. For a health check, not for the delivery. */
    public function anzahl(): int
    {
        return Reminder::query()->active()->due()->undelivered()->count();
    }
}
