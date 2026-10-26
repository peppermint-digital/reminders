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
    */
    'delivery' => [
        'bridge_notifications' => true,
        'type' => 'reminder.due',
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
