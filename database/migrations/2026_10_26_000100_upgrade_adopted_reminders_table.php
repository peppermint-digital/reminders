<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peppermint\Reminders\Models\Reminder;

/**
 * Bring a table that predates this package up to its shape.
 *
 * Two products carry a reminder table from before the extraction, where a
 * reminder hung on exactly one kind of thing and the column said so:
 * `task_reminders.task_id`, a real foreign key with `cascadeOnDelete`.
 *
 * ## What this migration does, and what it deliberately does not
 *
 * It ONLY ADDS. Columns that are missing get added, the subject pair gets
 * filled from the old column, and that is the end of it.
 *
 * It does not drop `task_id`, it does not relax it, and it does not touch a
 * row whose subject is already set. As long as the old column keeps its
 * values, the way back is a config change rather than a restore — and these
 * tables hold live rows in production.
 *
 * Relaxing the old column is the next migration, on purpose: it is the first
 * step that changes an existing constraint, and it deserves to be read and
 * run as its own decision. Dropping the column is not scheduled at all.
 *
 * ## Why it is guarded by config and not by a table name
 *
 * `reminders.legacy_subject` names the column and the class it implied. A
 * product with a fresh table leaves it null and this migration does nothing —
 * no guessing from table names, which is exactly how a migration once nearly
 * dropped 4357 real contacts in a product whose own table happened to carry
 * the package's default name.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('reminders.tables.reminders', 'reminders');

        if (! Schema::hasTable($table)) {
            return;
        }

        $this->ergaenzeSpalten($table);
        $this->fuelleGegenstandNach($table);
        $this->fuelleStatusNach($table);
    }

    /**
     * Add what the older shape is missing. Each one guarded individually: a
     * product may have added some of them itself — the CRM shipped its own
     * `delivered_at` in 10/2026, before this package existed.
     */
    private function ergaenzeSpalten(string $table): void
    {
        $fehlt = fn (string $spalte): bool => ! Schema::hasColumn($table, $spalte);

        if ($fehlt('subject_type') || $fehlt('subject_id')) {
            Schema::table($table, function (Blueprint $blueprint) use ($fehlt) {
                // Nullable, unlike the fresh table: adding a NOT NULL column to
                // a populated table needs a default, and a default subject type
                // would be a lie about every existing row until the backfill
                // runs. They are filled below, in the same migration.
                if ($fehlt('subject_type')) {
                    $blueprint->string('subject_type')->nullable();
                }

                if ($fehlt('subject_id')) {
                    $blueprint->unsignedBigInteger('subject_id')->nullable();
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->index(['subject_type', 'subject_id']);
            });
        }

        if ($fehlt('delivered_at')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dateTime('delivered_at')->nullable();
            });
        }

        if ($fehlt('source')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('source')->nullable();
            });
        }

        // Schlummern (#8766). Ein Produkt kann eine eigene Fassung davon
        // mitbringen — das CRM fuehrt seit 07/2025 `snoozed_until` samt einem
        // `is_snoozed` daneben. Das Bool wird NICHT uebernommen und nicht
        // geloescht: Das Paket leitet den Zustand aus dem Zeitstempel ab, und
        // was das Produkt sonst noch fuehrt, ist seine Sache.
        if ($fehlt('snoozed_until')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dateTime('snoozed_until')->nullable();
            });
        }

        if ($fehlt('snooze_count')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedInteger('snooze_count')->default(0);
            });
        }

        // Wiederkehr (#8767). Ein Produkt, das eine eigene Fassung mitbringt,
        // nennt seine Spalte womoeglich anders — das CRM fuehrt
        // `parent_reminder_id`. Dafuer ist die Spalten-Abbildung in der
        // Konfiguration da; hier entstehen die Namen des Pakets, und ein
        // Produkt, das abbildet, bekommt sie gar nicht erst.
        if ($fehlt('status')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('status')->nullable();
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->index(['status', 'remind_at']);
            });
        }

        if ($fehlt('recurrence_pattern')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('recurrence_pattern')->nullable();
            });
        }

        if ($fehlt(Reminder::column('parent_id'))) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger(Reminder::column('parent_id'))->nullable();
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->index([Reminder::column('parent_id'), Reminder::column('remind_at')]);
            });
        }
    }

    /**
     * Fill the subject pair from the single-purpose column it replaces.
     *
     * Only where the subject is still empty. Running twice changes nothing,
     * and a row someone already pointed somewhere else stays pointed there.
     */
    private function fuelleGegenstandNach(string $table): void
    {
        $alt = config('reminders.legacy_subject');

        if (! is_array($alt) || blank($alt['column'] ?? null) || blank($alt['type'] ?? null)) {
            return;
        }

        $spalte = (string) $alt['column'];

        if (! Schema::hasColumn($table, $spalte) || ! Schema::hasColumn($table, 'subject_id')) {
            return;
        }

        DB::table($table)
            ->whereNull('subject_id')
            ->whereNotNull($spalte)
            ->update([
                // Den MORPH-TYP schreiben, nicht den Klassennamen. Wer eine
                // Morph-Map fuehrt, speichert deren Alias — in AI Brain steht
                // dort `task`, nicht `App\Models\Task`. Der Klassenname
                // erzeugte zwei Werte fuer dieselbe Sache, und ein `morphTo`
                // darauf wirft bei erzwungener Map.
                'subject_type' => class_exists((string) $alt['type'])
                    ? Reminder::morphTypFuer((string) $alt['type'])
                    : (string) $alt['type'],
                'subject_id' => DB::raw($spalte),
            ]);
    }

    /**
     * Fill `status` from the boolean it replaces.
     *
     * Only when the product NAMED its two words. The migration cannot guess
     * them: a wrong guess means either a handled reminder starts waiting again
     * or — worse — a waiting one counts as done and reaches nobody. That is
     * Bug #880 in a new wrapper, so without the configuration this does
     * nothing at all.
     *
     * `is_dismissed` keeps its values. The package goes on writing it and
     * `scopeActive` uses it as a veto, so the products that have not moved yet
     * are unaffected — and the way back stays a config change.
     */
    private function fuelleStatusNach(string $table): void
    {
        $alt = config('reminders.legacy_status');

        if (! is_array($alt) || blank($alt['waiting'] ?? null) || blank($alt['dismissed'] ?? null)) {
            return;
        }

        if (! Schema::hasColumn($table, 'status') || ! Schema::hasColumn($table, 'is_dismissed')) {
            return;
        }

        // Nur wo noch nichts steht. Zweimal laufen aendert nichts, und eine
        // Zeile, der jemand schon einen Status gegeben hat, behaelt ihn.
        DB::table($table)->whereNull('status')->where('is_dismissed', true)
            ->update(['status' => (string) $alt['dismissed']]);

        DB::table($table)->whereNull('status')->where('is_dismissed', false)
            ->update(['status' => (string) $alt['waiting']]);
    }

    /**
     * Only the additions come back out, and only the ones this package owns.
     *
     * The backfilled values are left in place: they are correct, and a product
     * that rolls back wants its rows intact, not emptied.
     */
    public function down(): void
    {
        $table = (string) config('reminders.tables.reminders', 'reminders');

        if (! Schema::hasTable($table) || ! is_array(config('reminders.legacy_subject'))) {
            return;
        }

        // Der Index zuerst. Er zeigt auf beide Spalten, und SQLite bricht das
        // Loeschen einer Spalte ab, solange ein Index sie nennt: „1 error in
        // index … after drop column: no such column". MySQL raeumt den Index
        // still mit auf — ein `down()`, das nur dort geprueft wurde, waere
        // genau in der Umgebung heil, in der es niemand braucht.
        if (Schema::hasIndex($table, ['subject_type', 'subject_id'])) {
            Schema::table($table, fn (Blueprint $b) => $b->dropIndex(['subject_type', 'subject_id']));
        }

        // Beide in EINEM Aufruf: auf SQLite ist jedes Spalten-Loeschen ein
        // Neubau der Tabelle, zwei Aufrufe sind zwei Neubauten.
        $vorhanden = array_values(array_filter(
            ['subject_type', 'subject_id'],
            fn (string $spalte): bool => Schema::hasColumn($table, $spalte)
        ));

        if ($vorhanden !== []) {
            Schema::table($table, fn (Blueprint $b) => $b->dropColumn($vorhanden));
        }
    }
};
