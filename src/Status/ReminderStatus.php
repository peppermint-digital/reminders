<?php

namespace Peppermint\Reminders\Status;

/**
 * One state in an application's reminder vocabulary.
 *
 * The package ships none, for the same reason `peppermint/tasks` ships no task
 * statuses: two systems legitimately call the same thing different things, and
 * forcing a shared word means rewriting every row, query, filter and screen of
 * one of them for a cosmetic gain.
 *
 * The Peppermint CRM knows at least three (`pending`, `acknowledged`,
 * `dismissed`) and distinguishes two of them on purpose: "I have seen this"
 * and "throw it away" are different answers, and a system that collapses them
 * cannot say afterwards what a person actually did.
 *
 * ## The one thing every vocabulary must answer
 *
 * For tasks the shared yardstick is external — iCalendar VTODO decides what
 * "done" means. Reminders have no such standard: the closest thing, `VALARM`,
 * carries no state at all.
 *
 * So the shared question here is the delivery's own: **does this still want
 * something from someone?** It is narrower than an external standard and it is
 * genuine, because the delivery is the one thing every product runs over the
 * same rows. Everything else about a state — its word, its colour, what it
 * does to a screen — belongs to the product.
 */
abstract class ReminderStatus
{
    /** The value as it is stored in this application's rows. */
    abstract public function key(): string;

    /** What a human reads. */
    abstract public function label(): string;

    /**
     * Does a reminder in this state still want something from someone?
     *
     * `true` keeps it in the delivery and in "what is still open". `false`
     * takes it out — and takes it out for good, because a state that has
     * stopped waiting is the record that someone acted.
     *
     * This is the question the delivery asks, so getting it wrong is not a
     * display bug: `true` where it should be `false` nags people about
     * something they already handled, and `false` where it should be `true`
     * makes a reminder silently reach nobody.
     */
    abstract public function wartetNoch(): bool;
}
