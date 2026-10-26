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

**Register the notification type.** An unregistered type counts as one nobody
wants to see, so the delivery would run, succeed, mark everything as sent, and
reach no one — the original bug, one layer up. The bridge therefore checks
`config('notifications.types')` and throws rather than proceeding quietly.

## `delivered_at` is not `is_dismissed`

`is_dismissed` says *I reacted*. `delivered_at` says *it went out*. Treating one
as the other re-sends every reminder nobody has clicked away, every few minutes,
forever.

Dismissed rows stay. They are the record that someone was reminded and acted.
