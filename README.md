# peppermint/reminders

A reminder on anything — a task, an appointment, whatever the application
registers. Stores, queries, and reports what went out. It does not deliver.

Extracted from `peppermint/tasks` in 10/2026, where reminders had lived as
`task_reminders` since 09/2026. The extraction was not tidying: a reminder that
can only hang on a task forces the next subject into a second table, and a
second table is a second delivery run. The Peppermint CRM had exactly that, and
the rows in the table its delivery did not query reached nobody for weeks
(Bug #880).

## Install

```
composer require peppermint/reminders
```

The provider is discovered automatically. Publish the config with
`--tag=reminders-config`.

## Adopting a table you already have

A package that only runs on fresh tables cannot take on a grown product. Two
products arrive here with a reminder table they have been running — the Manager
since 12/2025, the CRM with live rows and a delivery on top.

```php
// config/reminders.php
'tables' => ['reminders' => 'task_reminders'],
'legacy_subject' => [
    'column' => 'task_id',
    'type'   => App\Models\Task::class,
    'relax'  => false,
],
```

Three migrations, in this order, and each one does less than it could:

1. **create** — skipped entirely when the table exists.
2. **upgrade** — adds `subject_type`, `subject_id`, `delivered_at`, `source`
   where they are missing and fills the subject pair from the old column. It
   only adds. It never touches a row whose subject is already set, and running
   it twice changes nothing.
3. **relax** — lets the old column be empty, which a subject that is not a task
   needs. **Ships off (`relax => false`)**: it is the first step that changes an
   existing constraint rather than adding one, and it runs against live rows. It
   refuses while any row still lacks a subject.

The old column is **not dropped**, and dropping it is not scheduled. As long as
it keeps its values the way back is a config change rather than a restore.

> Always read the table name from the config, never from the default. In the
> CRM `reminders` is the name of a *different*, product-owned table.

## Deleting a subject

The subject reference is polymorphic, so it carries no foreign key, so the
database does not cascade. Whoever owns the subject cleans up after it:

```php
static::deleting(function (Task $task) {
    Reminder::query()->forSubject($task)->delete();
});
```

This is the one duty the package hands back. It is deliberate: the alternative
is a package that knows which kinds of thing exist.

## Delivery

The package answers the question — *whose, due, open, not yet sent* — and marks
what went out. The way out belongs to the product.

```php
app(DueReminders::class)->holen();   // the delivery's query
$reminder->markDelivered();          // idempotent
```

With `peppermint/notifications` installed, `NotificationsBridge` does the whole
loop and routes by the person's own per-channel, per-type preferences. That is
strictly more than a reminder could express on its own: a checkbox on one
reminder cannot say *bell immediately, mail as a digest, push never*.

The dependency is a `suggest`, not a `require` — notifications pulls
`peppermint/inbox` and `peppermint/ai-brain-bridge` along with it, and a
reminder store should not force a messaging stack on a product without one.

**Registering the notification type is optional.** An unregistered type is not
invisible — `peppermint/notifications` carries an explicit `unknown_type`
fallback, and the notification arrives with a generic bell instead of its own
icon. Register it to get the icon and label; set
`notifications.strict_types` if you want an unknown type to throw. The bridge
deliberately does not second-guess either choice.

> This paragraph said the opposite until 26.10.2026, and the bridge carried a
> guard to match. Both were built on a guess about the notifications package
> rather than a reading of it. The guard is gone.

## Generating reminders from a rule

A start time or a deadline tells nobody on its own. Implement
`ErinnerungsAnlass` and `Abgleich` does the rest:

```php
$bilanz = (new Abgleich($anlass))->fahren();
// ['angelegt' => 2, 'verschoben' => 1, 'weggenommen' => 0, 'ohne_empfaenger' => []]
```

The product answers four questions — which things qualify, which moments each
one deserves, what the wording is, and who gets told. Everything else happens
once, here: chunking, dedup by `source`, leaving dismissed rows alone, moving
a reminder when its moment or recipient changes, taking away what no longer
has anything to say, and counting what it skipped.

Two rules are load-bearing and were learned the hard way:

- **Moving a reminder clears `delivered_at`.** What went out at the old moment
  no longer applies; without the reset the moved reminder never fires, because
  it counts as "already told you".
- **A subject without a recipient is counted and named, not skipped quietly.**
  A run that silently does nothing reports success, and the gap only surfaces
  months later when somebody asks why they were never told.

It exists because both consumers had written it: 238 lines in
`peppermint/tasks`, 247 in `peppermint/calendar`, with the same parts.
Question 3 of the package contract — *does every product write the same
wiring?* — and the answer was yes.

## Snoozing

```php
$reminder->snooze(60);          // push it an hour out
Reminder::snoozeSteps();        // [5, 15, 30, 60, 240, 1440, 10080] — minutes, configurable
$reminder->istGeschlummert();   // derived, never stored
```

There is no `is_snoozed` column. Snoozed means `snoozed_until` is still in the
future; a boolean beside the timestamp would be a second truth about the same
fact, and the two drift.

**`snooze()` clears `delivered_at`**, and that is the only reason snoozing
works: the delivery asks for undelivered rows, so a reminder that kept its
`delivered_at` would never come back. The button would appear to work and
silently throw the reminder away. `snooze_count` keeps the record that it went
out and was pushed aside — the more useful question anyway.

`due` excludes a snoozed reminder and `upcoming` includes it. Both halves
matter: without the first, snoozing does nothing; without the second, a snoozed
reminder falls out of every list and is invisible until it returns.

## Recurrence

```php
$reminder->istWiederkehrend();                      // recurrence_pattern !== null
$next = $reminder->naechsteInstanz($moment);        // the product worked out $moment
Reminder::query()->serie($reminder)->get();         // the whole series, root included
```

**The package stores `recurrence_pattern` and never reads it.** What `weekly`
means — whether it skips weekends, what happens on the 31st — is the product's
vocabulary, exactly as `peppermint/tasks` ships no status words. So the moment
comes in from outside; nothing here computes it.

`peppermint/calendar` can already resolve series with RRULE. Depending on it
would be the obvious move and the wrong one: it chains two core packages, and
then a reminder store cannot ship without a calendar.

The chain points at the **root**, not the predecessor — a flat chain answers
"show me this series" in one query, where a linked list has to be walked, and a
walk over user data eventually meets a gap.

`naechsteInstanz()` refuses to make a second entry for the same moment (same
reason `source` exists: something that generates rows and runs twice must not
double them), gives the successor a **fresh** delivery and snooze state, and
does not touch the predecessor — not even a dismissed one. That row is the
record that someone was reminded and acted; editing it afterwards rewrites the
history.

## Status vocabulary

The package ships none, for the same reason `peppermint/tasks` ships no task
statuses: two systems legitimately use different words, and forcing a shared
one means rewriting every row, query and screen of one of them.

```php
// config/reminders.php
'statuses' => [Pending::class, Acknowledged::class, Dismissed::class],
```

Each state answers one question — **does this still want something from
someone?** The delivery asks it, so a wrong answer either nags people about
what they already handled or reaches nobody.

```php
$reminder->setzeStatus('dismissed');   // validated, keeps is_dismissed in step
$reminder->statusEintrag();            // the registered state, or null
```

**An empty vocabulary is valid, and the fallback is load-bearing.** With
nothing registered the plain `is_dismissed` boolean rules alone. Reading an
empty registry as "no state waits" would make `active` match nothing and the
delivery would quietly stop — the exact shape of the bug this package exists
to prevent. A `null` status is let through for the same reason: during a
backfill it means "not reached yet", not "not waiting".

`is_dismissed` is a **hard veto** in `active`, vocabulary or not. Someone who
clicked a reminder away has clicked it away, and no registered state brings it
back. That is also what keeps the older `dismiss()` honest alongside a
vocabulary.

Moving an existing product across: name your two words and the upgrade
migration fills `status` from the boolean.

```php
'legacy_status' => ['waiting' => 'pending', 'dismissed' => 'dismissed'],
```

Without it, nothing is backfilled — the migration cannot guess, and a wrong
guess means a waiting reminder counts as done and reaches nobody.
`is_dismissed` is not dropped.

## The delivery command

With `peppermint/notifications` installed, the package registers one:

```
php artisan erinnerungen:zustellen [--limit=500]
```

Same command, same name, same behaviour in every product. **The schedule stays
in the product** — how often reminders go out is a product decision, and a
package that writes itself into the scheduler takes it away.

```php
Schedule::command('erinnerungen:zustellen')->everyFiveMinutes()->withoutOverlapping();
```

`--dry-run` shows what would go out and marks nothing. A reminder whose
person no longer exists is skipped and **named with its id** — `user_id` is
deliberately not a foreign key (the user table is named per product), so the
database does not clean up, and without that line the gap between "due" and
"delivered" looks like a delivery problem when it lives in the user data.

### Giving the notification a destination

The package knows a reminder HAS a subject and never how to get there — an
address is product knowledge. Bind `ErinnerungsVerweis` and the notification
gets its link, icon and wording:

```php
$this->app->bind(ErinnerungsVerweis::class, fn () => new class implements ErinnerungsVerweis {
    public function fuer(Reminder $e): array
    {
        return ['link' => route('tasks.show', $e->subject_id), 'icon' => 'bell-ring'];
    }
});
```

Without a binding the notification stays plain — allowed, but without a
destination. This hook exists because the CRM had a hand-written mail with an
"open the task" button, and moving to the shared path would have dropped it
without a single test going red.

It exists because it was otherwise going to exist four times. Measured on
26.10.2026: of four products, two had a delivery run and two did not — and in
one of those two, reminders could be created through a form *and* an MCP tool
and reached nobody. Bug #880 unchanged, in a different window, invisible to
7781 passing tests.

## `delivered_at` is not `is_dismissed`

`is_dismissed` says *I reacted*. `delivered_at` says *it went out*. Treating one
as the other re-sends every reminder nobody has clicked away, every few minutes,
forever.

Dismissed rows stay. They are the record that someone was reminded and acted.
