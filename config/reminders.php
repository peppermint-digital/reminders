<?php

return [
    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | The package has no user management of its own — it only holds a reference.
    */
    'user_model' => env('REMINDERS_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | For adopting a table that already exists under another name. A package
    | that only runs on fresh tables cannot take on a grown product — and then
    | it gets built twice.
    |
    | This is not hypothetical here: reminders lived inside peppermint/tasks
    | until 10/2026 as `task_reminders`, with live rows in the Peppermint CRM
    | and a table the Manager has carried since 12/2025. Both adopt this
    | package on top of what they have.
    |
    | ALWAYS read the name from here, never from the default. In the CRM
    | `reminders` is the name of a DIFFERENT, product-owned table — dropping or
    | truncating the default name there would hit the wrong rows.
    */
    'tables' => [
        'reminders' => 'reminders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Columns
    |--------------------------------------------------------------------------
    |
    | Package field name => actual column name. Lets a product keep its
    | years-old column names while the package speaks its own.
    */
    'columns' => [
        'reminders' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy subject column
    |--------------------------------------------------------------------------
    |
    | Only for a product migrating off a single-subject table. Before this
    | package a reminder hung on exactly one kind of thing, so the table had a
    | plain `task_id` instead of a polymorphic pair.
    |
    | Name the column and the class it implied, and the upgrade migration fills
    | `subject_type`/`subject_id` from it. The old column is deliberately NOT
    | dropped — as long as it stays populated the way back is open. Dropping it
    | is a separate, later decision.
    |
    |   'legacy_subject' => [
    |       'column' => 'task_id',
    |       'type'   => App\Models\Task::class,
    |       'relax'  => false,
    |   ],
    |
    | `relax` lets the old column be empty, which an appointment's reminder
    | needs — it has no task. It ships false because it is the first step that
    | changes an existing constraint instead of adding one, and it runs against
    | live rows. A deploy must not trigger it by accident.
    */
    'legacy_subject' => null,

    /*
    |--------------------------------------------------------------------------
    | Status vocabulary
    |--------------------------------------------------------------------------
    |
    | The states this application knows. Each entry is the class name of a
    | Peppermint\Reminders\Status\ReminderStatus.
    |
    | The package ships NONE, and an empty list is a valid answer. While the
    | list is empty the package falls back to the plain `is_dismissed` boolean
    | it shipped with — that fallback is load-bearing, not politeness: an empty
    | registry read as "no state waits" would make `scopeActive` match nothing,
    | and the delivery would quietly stop delivering.
    |
    | What every vocabulary must answer is one question — does this state still
    | want something from someone? The delivery asks it, so a wrong answer
    | either nags people about what they handled or reaches nobody at all.
    */
    'statuses' => [],

    /*
    |--------------------------------------------------------------------------
    | Legacy status backfill
    |--------------------------------------------------------------------------
    |
    | Only for a product moving from the boolean to a vocabulary. Name the two
    | keys and the upgrade migration fills `status` from `is_dismissed`:
    |
    |   'legacy_status' => ['waiting' => 'pending', 'dismissed' => 'dismissed'],
    |
    | Without this, nothing is backfilled. The migration cannot guess: only the
    | product knows its own words, and a wrong guess means either a handled
    | reminder starts waiting again or — worse — a waiting one counts as done
    | and reaches nobody. That is Bug #880 in a new wrapper, so the migration
    | would rather do nothing.
    |
    | `is_dismissed` is NOT dropped. As long as it keeps its values the way
    | back is a config change; the package also keeps writing it, so the
    | fallback above stays valid for the products that have not moved yet.
    */
    'legacy_status' => null,

    /*
    |--------------------------------------------------------------------------
    | Snooze
    |--------------------------------------------------------------------------
    |
    | The steps a product offers, in minutes. The numbers are here because
    | they are the same everywhere — five minutes, a quarter hour, an hour,
    | tomorrow, next week. The WORDS are not: a package that ships labels
    | ships a language, and the product already has one.
    |
    | Snoozing clears `delivered_at`, which is the only reason it works at
    | all — see Reminder::snooze().
    */
    'snooze' => [
        'steps' => [5, 15, 30, 60, 240, 1440, 10080],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | The package does NOT deliver. It answers "whose, due, open, not yet
    | delivered" and marks what went out; the way out belongs to the product.
    |
    | With peppermint/notifications installed the bridge takes over and routes
    | by the person's own per-channel preferences — that package holds them per
    | user AND per type, which is strictly more than a channel picker on a
    | single reminder could express. Deliberately a `suggest`, not a `require`:
    | notifications pulls peppermint/inbox and peppermint/ai-brain-bridge with
    | it, and a reminder store should not force a messaging system on a product
    | that has none.
    |
    | `type` is the key the preferences are stored under. One string, because
    | it appears in the preference screens of every product that installs this.
    |
    | ⚠ NO DOT in it, and that is not cosmetic. A product that wants a default
    | for this type writes it as
    | `notifications.preferences.per_type.<type>.<channel>` — a CONFIG path,
    | and a dotted type would be read as nesting: `per_type.reminder.due.email`
    | looks for `['reminder' => ['due' => …]]`, never for the key
    | `'reminder.due'`. The default would silently never apply.
    |
    | The HTTP endpoint tolerates dots (the type travels in the body, for the
    | same reason spelled out in that package's routes file — in a path a dot
    | looks like a file extension). The config path does not, and that is the
    | one that bites quietly.
    */
    'delivery' => [
        'bridge_notifications' => true,
        'type' => 'reminder_due',
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | false for a product that already has its table and only adopts the
    | package on top of it.
    */
    'run_migrations' => true,
];
