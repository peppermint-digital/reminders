<?php

namespace Peppermint\Reminders\Delivery;

use Illuminate\Database\Eloquent\Collection;
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
        return Reminder::query()
            ->active()
            ->due()
            ->undelivered()
            // Oldest first: if a backlog built up, the one that has been
            // waiting longest goes out first. A limit that takes the newest
            // starves the backlog forever.
            ->orderBy(Reminder::column('remind_at'))
            ->limit($hoechstens)
            ->get();
    }

    /** How many are waiting. For a health check, not for the delivery. */
    public function anzahl(): int
    {
        return Reminder::query()->active()->due()->undelivered()->count();
    }
}
