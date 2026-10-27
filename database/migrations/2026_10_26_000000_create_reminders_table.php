<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders on anything — several per subject, dismissible, with a history.
 *
 * Skipped when the table already exists. Two products arrive here with one
 * they have been running for a while: the Manager since 12/2025, the CRM with
 * live rows and a delivery on top. They adopt the package rather than being
 * migrated onto it; the upgrade migration next to this one fills in what their
 * table is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('reminders.tables.reminders', 'reminders');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();

            // What the reminder is about. No foreign key — a polymorphic
            // reference cannot carry one, and the package must not learn what
            // kinds of thing exist. Cleanup belongs to the subject's owner.
            $blueprint->string('subject_type');
            $blueprint->unsignedBigInteger('subject_id');

            // Whose reminder it is. NOT a foreign key: the user table is named
            // per product (`reminders.user_model`), and a package that insists
            // on `users` locks out the product that calls them something else.
            $blueprint->unsignedBigInteger('user_id');

            $blueprint->dateTime('remind_at');

            // Optional wording. Without it a reminder still works — it points
            // at its subject — but "before the customer call" is the difference
            // between a nudge and a note to nobody.
            $blueprint->string('title')->nullable();
            $blueprint->text('note')->nullable();

            // Where it came from. `null` means a person set it. Anything else
            // means a rule generated it, and the rule owns it: it moves the row
            // when the trigger moves and removes it when the trigger goes away.
            $blueprint->string('source')->nullable();

            // Dismissed rows STAY. They are the record that someone was
            // reminded and acted.
            $blueprint->boolean('is_dismissed')->default(false);
            $blueprint->dateTime('dismissed_at')->nullable();

            // The state, in this application's own words. Nullable because the
            // package ships no vocabulary and an application need not have one
            // — then the boolean above rules alone.
            $blueprint->string('status')->nullable();

            // Went out — not the same as "someone reacted". Without this column
            // a delivery re-sends every reminder nobody clicked away, every few
            // minutes, forever.
            $blueprint->dateTime('delivered_at')->nullable();

            // Pushed away until later. No `is_snoozed` boolean next to it: that
            // would be a second truth about the same fact, and the two drift.
            // Snoozed means `snoozed_until` is still in the future — nothing
            // else needs saying.
            $blueprint->dateTime('snoozed_until')->nullable();

            // How often it was pushed away. Not bookkeeping for its own sake:
            // it is the only way to answer later which reminders nobody ever
            // intends to act on.
            $blueprint->unsignedInteger('snooze_count')->default(0);

            // Recurrence. The package STORES it and does not read it: what
            // `weekly` means is the product's vocabulary, the same way
            // peppermint/tasks ships no status words. No `is_recurring`
            // boolean — that is `recurrence_pattern !== null`.
            $blueprint->string('recurrence_pattern')->nullable();

            // The ROOT of the series, not the predecessor. A flat chain can be
            // asked "show me this whole series" in one query; a linked list
            // has to be walked, and a walk over user data is a walk that
            // eventually meets a gap.
            //
            // No foreign key, deliberately: a cascade would delete the history
            // of a series when its first entry goes, and that history is the
            // record that people were reminded.
            $blueprint->unsignedBigInteger('parent_id')->nullable();

            $blueprint->index(['parent_id', 'remind_at']);

            $blueprint->timestamps();

            // The query the delivery runs every few minutes: mine, due, open.
            $blueprint->index(['user_id', 'remind_at', 'is_dismissed']);
            $blueprint->index(['status', 'remind_at']);

            // Everything on one thing — what a subject owner asks before it
            // deletes the subject.
            $blueprint->index(['subject_type', 'subject_id']);

            // The reconciliation's own question: "is there already a generated
            // row of this kind on this subject?"
            $blueprint->index(['subject_type', 'subject_id', 'source']);
        });
    }

    /**
     * Nur wegnehmen, was diese Migration selbst angelegt hat.
     *
     * ## Was hier stand, und warum es gefaehrlich war
     *
     * `Schema::dropIfExists(config('reminders.tables.reminders'))` — ohne
     * jede Pruefung.
     *
     * `up()` kehrt korrekt um, wenn die Tabelle schon da ist: Wer das Paket
     * auf eine BESTEHENDE Tabelle setzt, soll sie behalten. `down()` tat das
     * nicht. In jedem der vier Produkte zeigt
     * `reminders.tables.reminders` auf die eigene, gewachsene Tabelle —
     * `task_reminders` im CRM, Manager und AI Brain, `ticket_reminders` in der
     * Verwaltung. Ein `migrate:rollback` haette sie gedroppt.
     *
     * Gemessen am 27.10.2026 in Produktion: 27 lebende Zeilen in
     * `peppermint-manager.task_reminders`, 15 in AI Brain. Die waeren weg
     * gewesen — und auf breezy-star gibt es keinen Nachtlauf und kein
     * binlog, also auch keinen Rueckweg.
     *
     * Aufgefallen ist es durch die Rueckfrage einer anderen Sitzung nach
     * genau dieser Zeile. Kein Test hat es gesehen: Eine Testreihe mit
     * `RefreshDatabase` ruft `down()` nie auf.
     *
     * ## Die zwei Riegel
     *
     * 1. **Uebernommen?** Ist eine alte Gegenstands-Spalte konfiguriert und
     *    in der Tabelle vorhanden, ist sie aelter als dieses Paket. Dasselbe
     *    Kennzeichen benutzt das Paket an jeder anderen Stelle auch.
     * 2. **Hat sie Inhalt?** Eine Tabelle mit Zeilen nimmt kein `down()`
     *    weg. Auch eine frisch angelegte kann in der Zwischenzeit gefuellt
     *    worden sein, und dann ist der Rueckbau nicht mehr das Gegenstueck
     *    zum Aufbau.
     */
    public function down(): void
    {
        $table = (string) config('reminders.tables.reminders', 'reminders');

        if (! Schema::hasTable($table)) {
            return;
        }

        $alt = config('reminders.legacy_subject');
        $spalte = is_array($alt) ? (string) ($alt['column'] ?? '') : '';

        if ($spalte !== '' && Schema::hasColumn($table, $spalte)) {
            // Uebernommene Tabelle. Sie gehoert dem Produkt, nicht dieser
            // Migration.
            return;
        }

        if (DB::table($table)->exists()) {
            return;
        }

        Schema::drop($table);
    }
};
