<?php

namespace Peppermint\Reminders\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Peppermint\Reminders\Status\ReminderStatus;
use Peppermint\Reminders\Status\StatusRegistry;

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

    /**
     * Still wants something from someone.
     *
     * ## Two clauses, and both of them are load-bearing
     *
     * `is_dismissed = false` applies ALWAYS, vocabulary or not. It is a hard
     * veto: someone who clicked a reminder away has clicked it away, and no
     * state in any vocabulary may bring it back. That is also what keeps the
     * older {@see dismiss()} honest next to a vocabulary — otherwise a row
     * dismissed through it would keep a waiting status and go on being
     * delivered.
     *
     * The status clause is added only when a vocabulary exists, and it lets a
     * NULL status through. Both halves matter:
     *
     * - No vocabulary registered — which is where every product starts — and
     *   the boolean alone rules. Reading an empty registry as "no state waits"
     *   would make this scope match nothing, and the delivery would quietly
     *   stop delivering. That is not a hypothetical: it is the shape of Bug
     *   #880, which is why this package exists.
     * - A NULL status during a migration is a row the backfill has not reached
     *   yet. Excluded by a plain `whereIn`, it would fall out of the delivery
     *   silently — the reminder looks fine and reaches nobody.
     */
    public function scopeActive(Builder $query): Builder
    {
        $query->where(static::column('is_dismissed'), false);

        $verzeichnis = app(StatusRegistry::class);

        if ($verzeichnis->istLeer()) {
            return $query;
        }

        return $query->where(fn (Builder $wartend) => $wartend
            ->whereIn(static::column('status'), $verzeichnis->wartendeSchluessel())
            ->orWhereNull(static::column('status'))
        );
    }

    /** The registered state, or null while this product has no vocabulary. */
    public function statusEintrag(): ?ReminderStatus
    {
        $verzeichnis = app(StatusRegistry::class);
        $key = $this->{static::column('status')};

        return $key !== null && $verzeichnis->has($key) ? $verzeichnis->get($key) : null;
    }

    /**
     * Move it to a registered state, keeping the boolean in step.
     *
     * The boolean is not a second truth here, it is a maintained derivation —
     * and it is the bridge: four products run the old column today, and
     * `scopeActive` leans on it as its veto. Both writers keep it correct, so
     * neither path can strand a row. It goes once every product has a
     * vocabulary, not before.
     *
     * Throws on an unregistered key rather than storing it. A row whose state
     * nobody can read would either nag forever or reach nobody, and nothing
     * would look broken.
     */
    public function setzeStatus(string $key): bool
    {
        $status = app(StatusRegistry::class)->get($key);

        $wartet = $status->wartetNoch();

        $this->forceFill([
            static::column('status') => $key,
            static::column('is_dismissed') => ! $wartet,
            // Idempotent in beide Richtungen: Ein zweites Abschliessen
            // verschiebt den Zeitpunkt nicht, und wer eine Erinnerung zurueck
            // ins Wartende holt, soll keinen Abschluss-Zeitpunkt behalten.
            static::column('dismissed_at') => $wartet ? null : ($this->dismissed_at ?? now()),
        ]);

        return $this->save();
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

    /**
     * The first entry of the series this one belongs to.
     *
     * Null on the root itself — asking a root for its parent and getting
     * itself back would make "is this the first one" unanswerable.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, static::column('parent_id'));
    }

    /** The later entries of the series. Only meaningful on the root. */
    public function instances(): HasMany
    {
        return $this->hasMany(static::class, static::column('parent_id'));
    }

    /** The id the whole series hangs off — this one, or its root. */
    public function wurzelId(): int|string
    {
        return $this->{static::column('parent_id')} ?? $this->getKey();
    }

    /** Every entry of the series, the root included. */
    public function scopeSerie(Builder $query, self $erinnerung): Builder
    {
        $wurzel = $erinnerung->wurzelId();

        return $query->where(fn (Builder $q) => $q
            ->whereKey($wurzel)
            ->orWhere(static::column('parent_id'), $wurzel)
        );
    }

    public function scopeWiederkehrend(Builder $query): Builder
    {
        return $query->whereNotNull(static::column('recurrence_pattern'));
    }

    /** Derived, never stored — same reason as the snooze state. */
    public function istWiederkehrend(): bool
    {
        return $this->recurrence_pattern !== null;
    }

    /**
     * The next entry of this series, at a moment the PRODUCT worked out.
     *
     * ## Why the moment comes in from outside
     *
     * The package stores `recurrence_pattern` and never reads it. What
     * `weekly` means — and whether it skips weekends, and what happens on the
     * 31st — is the product's vocabulary, exactly as peppermint/tasks ships no
     * status words. A package that guessed here would be wrong in one product
     * and unoverridable in all of them.
     *
     * `peppermint/calendar` can already resolve series, with RRULE. Depending
     * on it would be the obvious move and the wrong one: it chains two core
     * packages, and then a reminder store cannot ship without a calendar.
     *
     * ## Why it refuses to make a second one for the same moment
     *
     * The same reason `source` exists: something that generates rows and runs
     * twice must not double them. Dedup is on (series, moment), so a second
     * call returns the entry that is already there.
     *
     * ## What it does NOT touch
     *
     * The predecessor. Not its state, not its timestamps — a dismissed
     * reminder is the record that someone was reminded and acted, and editing
     * it afterwards rewrites that history.
     */
    public function naechsteInstanz(\DateTimeInterface $zeitpunkt): ?static
    {
        if (! $this->istWiederkehrend()) {
            return null;
        }

        $wurzel = $this->wurzelId();

        $vorhanden = static::query()
            ->serie($this)
            ->where(static::column('remind_at'), $zeitpunkt)
            ->first();

        if ($vorhanden !== null) {
            return $vorhanden;
        }

        return static::query()->create([
            static::column('subject_type') => $this->subject_type,
            static::column('subject_id') => $this->subject_id,
            static::column('user_id') => $this->{static::column('user_id')},
            static::column('remind_at') => $zeitpunkt,
            static::column('title') => $this->title,
            static::column('note') => $this->note,
            static::column('source') => $this->source,
            static::column('recurrence_pattern') => $this->recurrence_pattern,
            static::column('parent_id') => $wurzel,
            // Frisch, ausdruecklich: Der Nachfolger hat noch niemanden
            // erreicht und wurde von niemandem weggeschoben. Uebernaehme er
            // das vom Vorgaenger, waere er bei der Geburt schon zugestellt
            // und fiele aus der Zustellung heraus.
            static::column('delivered_at') => null,
            static::column('snoozed_until') => null,
            static::column('snooze_count') => 0,
            static::column('is_dismissed') => false,
            static::column('dismissed_at') => null,
        ]);
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
