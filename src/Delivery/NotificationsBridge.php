<?php

namespace Peppermint\Reminders\Delivery;

use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Reminders\Models\Reminder;

/**
 * Turns a due reminder into a notification — once, for every product.
 *
 * ## Why this lives in the package and not in each product
 *
 * Four products would otherwise write the same twenty lines: ask for the due
 * ones, hand each to the notifier, write down that it went out. That is the
 * third of the package contract's three questions — "does every product write
 * the same wiring?" — and the answer has to be no.
 *
 * ## Why peppermint/notifications is a suggestion and not a requirement
 *
 * It pulls peppermint/inbox and peppermint/ai-brain-bridge along with it. A
 * reminder store that forces a messaging stack on a product without one is a
 * package that gets vendored around instead of installed. So: the bridge is
 * here, the dependency is optional, and {@see verfuegbar()} decides.
 *
 * ## Why the channel is not ours to pick
 *
 * The notifier already routes by what the PERSON set, per channel and per
 * type. That is strictly more than a reminder could express on its own: a
 * checkbox on one reminder cannot say "bell immediately, mail as a digest,
 * push never". Asking each reminder which channel it wants would be a fourth
 * answer to a question that already has one.
 */
class NotificationsBridge
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly DueReminders $faellige,
    ) {}

    /** Is peppermint/notifications installed at all? */
    public static function verfuegbar(): bool
    {
        return class_exists(Notifier::class);
    }

    /**
     * Deliver everything that is due.
     *
     * @return int how many were handed over
     */
    public function zustellen(int $hoechstens = 500): int
    {
        $art = (string) config('reminders.delivery.type', 'reminder.due');
        $zugestellt = 0;

        foreach ($this->faellige->holen($hoechstens) as $erinnerung) {
            if ($this->eine($erinnerung, $art)) {
                $zugestellt++;
            }
        }

        return $zugestellt;
    }

    /**
     * One reminder.
     *
     * Marked as delivered even when the notifier declines. We never pass a
     * trigger, so the only reason it can decline is the person's own
     * preference — and that is a delivery that correctly went nowhere, not one
     * to retry every few minutes until the end of time.
     *
     * ## Why there is no check that the type is registered
     *
     * There was one, built on a guess, and the guess was wrong. An
     * unregistered type in `peppermint/notifications` is NOT invisible: its
     * config carries an explicit `unknown_type` fallback, with the reasoning
     * spelled out — "Bewusst ein Rueckfall und kein Fehler ... Sichtbar falsch
     * ist besser als unsichtbar." The notification arrives, with a generic
     * bell instead of its own icon.
     *
     * A product that wants its vocabulary complete sets
     * `notifications.strict_types`, and then the notifier itself throws. That
     * is the product's call about its own strictness, and a bridge that
     * duplicates it would be a second answer to a settled question.
     */
    private function eine(Reminder $erinnerung, string $art): bool
    {
        $this->notifier->send(
            to: $erinnerung->{Reminder::column('user_id')},
            type: $art,
            title: $erinnerung->title ?: __('Erinnerung'),
            message: $erinnerung->note,
            // NUR wenn geladen. Ein Zugriff auf die unaufgeloeste Beziehung
            // wirft dort, wo `preventLazyLoading` laeuft — und ein Typ, dessen
            // Klasse es nicht mehr gibt, bleibt absichtlich ungeladen.
            subject: $erinnerung->relationLoaded('subject') ? $erinnerung->subject : null,
        );

        return $erinnerung->markDelivered();
    }

}
