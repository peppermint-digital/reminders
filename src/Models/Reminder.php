<?php

namespace Peppermint\Reminders\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A reminder a person set for themselves on something.
 *
 * ## Why the subject is polymorphic
 *
 * This started as a reminder on a task and nothing else. The moment a second
 * kind of thing wanted one — an appointment — the choice was a second table
 * with a second delivery run, or one subject reference. Two stores is the
 * shape of the bug this package was extracted to fix: the Peppermint CRM had
 * a delivery that queried one of its two reminder tables, and the rows in the
 * other one silently reached nobody for weeks.
 *
 * ## Why there is no foreign key on the subject
 *
 * A polymorphic reference cannot carry one. That is a real loss: the old
 * `task_id` was `constrained()->cascadeOnDelete()`, so a deleted task took its
 * reminders with it, enforced by the database.
 *
 * The cleanup therefore belongs to whoever owns the subject — the tasks
 * package deletes the reminders of its task, the calendar those of its event.
 * That is one line in each, and it keeps this package free of any knowledge
 * about what it is reminding anyone about. Deleting the subject without
 * deleting its reminders leaves rows that point at nothing; {@see forSubject}
 * is how an owner finds them.
 *
 * ## Why `delivered_at` is not `is_dismissed`
 *
 * `is_dismissed` says "I reacted". `delivered_at` says "it went out". Treating
 * one as the other re-sends every reminder nobody has clicked away, every few
 * minutes, forever. The distinction was learned the hard way in the CRM and is
 * the reason that column exists.
 *
 * ## Why dismissed rows stay
 *
 * `is_dismissed` marks, it does not delete. The row is the record that someone
 * was reminded and acted — the history. Deleting it would answer "is anything
 * pending" faster and make "what did we already chase" unanswerable.
 */
class Reminder extends Model
{
    protected $guarded = [];

    /**
     * The table, resolvable: a grown product keeps its own name instead of
     * being renamed into the package. The CRM and the Manager both arrive here
     * with a `task_reminders` they already had.
     */
    public function getTable(): string
    {
        return (string) config('reminders.tables.reminders', 'reminders');
    }

    /**
     * Package field name => actual column name.
     *
     * Only the fields the package itself queries are mapped. Attribute-level
     * renaming is deliberately not supported: a product that wants to read
     * `$reminder->remind_at` under another name is asking the package to speak
     * its vocabulary, and that is the opposite of a shared core.
     */
    public static function column(string $field): string
    {
        // Ueberschreibbar, und die Scopes rufen sie als `static::` auf: Eine
        // Unterklasse wie die Aufgaben-Erinnerung liest ihre Abbildung aus der
        // Konfiguration IHRES Pakets. Mit `self::` waere die Bindung frueh und
        // jede Ueberschreibung wirkungslos — ein Fehler, der nichts bricht,
        // sondern still die falsche Abbildung benutzt.
        $mapping = config('reminders.columns.reminders', []);

        return is_array($mapping) ? (string) ($mapping[$field] ?? $field) : $field;
    }

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'is_dismissed' => 'boolean',
            'dismissed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'snooze_count' => 'integer',
        ];
    }

    /** Whatever this reminder is about. The package never asks what it is. */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('reminders.user_model'), static::column('user_id'));
    }

    /** Not dismissed — still wants something from someone. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(static::column('is_dismissed'), false);
    }

    /**
     * Its moment has come or passed, and it has not been pushed past it.
     *
     * The snooze clause is not a refinement — without it snoozing does
     * nothing at all. The delivery would hand a snoozed reminder straight
     * back out, which looks like a broken button rather than a missing
     * `where`, and sends whoever debugs it to the wrong file.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where(static::column('remind_at'), '<=', now())
            ->where(fn (Builder $ohneSchlummer) => $ohneSchlummer
                ->whereNull(static::column('snoozed_until'))
                ->orWhere(static::column('snoozed_until'), '<=', now())
            );
    }

    /**
     * Still ahead — including one pushed into the future.
     *
     * A snoozed reminder whose own moment has passed is neither due nor,
     * under a plain `remind_at > now`, upcoming: it would fall out of both
     * lists and be invisible until it came back. From the person's side it is
     * plainly still ahead, so it belongs here.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(static::column('remind_at'), '>', now())
            ->orWhere(static::column('snoozed_until'), '>', now())
        );
    }

    /** Pushed past its moment, not yet back. */
    public function scopeSnoozed(Builder $query): Builder
    {
        return $query->where(static::column('snoozed_until'), '>', now());
    }

    public function scopeForUser(Builder $query, Model|int|string $user): Builder
    {
        return $query->where(
            static::column('user_id'),
            $user instanceof Model ? $user->getKey() : $user
        );
    }

    /**
     * Every reminder on one thing.
     *
     * The query a subject owner runs before deleting it — see the note on the
     * missing foreign key above.
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where(static::column('subject_type'), $subject->getMorphClass())
            ->where(static::column('subject_id'), $subject->getKey());
    }

    /** Not yet sent anywhere. The other half of the delivery query. */
    public function scopeUndelivered(Builder $query): Builder
    {
        return $query->whereNull(static::column('delivered_at'));
    }

    /**
     * Set by a person, not generated by a rule.
     *
     * `source` is null for a hand-set reminder and names the rule otherwise.
     * Without the distinction a reconciliation either duplicates its own rows
     * on every run or starts rewriting what someone set by hand.
     */
    public function scopeHandSet(Builder $query): Builder
    {
        return $query->whereNull(static::column('source'));
    }

    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where(static::column('source'), $source);
    }

    /** Derived, never stored: a boolean beside the timestamp would drift. */
    public function istGeschlummert(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /**
     * Push it away for a while.
     *
     * ## Why this clears `delivered_at`
     *
     * That is the whole point of snoozing: tell me again later. The delivery
     * asks for undelivered rows, so a reminder that keeps its `delivered_at`
     * is one that never comes back — the button would appear to work and
     * silently throw the reminder away. The one failure mode this package
     * exists to prevent, wearing a different hat.
     *
     * The record that it already went out once is not lost: `snooze_count`
     * carries it, and it answers the more useful question anyway — not
     * "was it delivered" but "how often has this been pushed away".
     *
     * @param  int  $minuten  how long to push it out
     */
    public function snooze(int $minuten): bool
    {
        if ($minuten < 1) {
            return false;
        }

        $this->forceFill([
            static::column('snoozed_until') => now()->addMinutes($minuten),
            static::column('snooze_count') => (int) $this->snooze_count + 1,
            static::column('delivered_at') => null,
        ]);

        return $this->save();
    }

    /**
     * The steps a product offers. Configured, not hardcoded.
     *
     * The numbers are shared because they are the same everywhere; the WORDS
     * are not here. A package that ships labels ships a language.
     *
     * @return array<int, int>
     */
    public static function snoozeSteps(): array
    {
        $stufen = config('reminders.snooze.steps', []);

        return is_array($stufen) ? array_values(array_filter(array_map('intval', $stufen))) : [];
    }

    /**
     * Mark as dealt with.
     *
     * Idempotent on purpose: a second dismissal must not move the timestamp,
     * or the history says the person acted later than they did.
     */
    public function dismiss(): bool
    {
        if ($this->is_dismissed) {
            return false;
        }

        return $this->update([
            static::column('is_dismissed') => true,
            static::column('dismissed_at') => now(),
        ]);
    }

    /**
     * Mark as sent.
     *
     * Idempotent for the same reason, and `saveQuietly` because delivery is
     * bookkeeping: it must not wake observers that are watching for what a
     * person did.
     */
    public function markDelivered(): bool
    {
        if ($this->delivered_at !== null) {
            return false;
        }

        $this->forceFill([static::column('delivered_at') => now()]);

        return $this->saveQuietly();
    }
}
