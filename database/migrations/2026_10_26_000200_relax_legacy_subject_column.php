<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let the old single-purpose column be empty.
 *
 * `task_reminders.task_id` is NOT NULL. That is correct for a table where
 * every reminder is about a task and wrong the moment an appointment wants one
 * in the same table — the insert would fail on a column that no longer applies.
 *
 * ## Why this is opt-in and not part of the upgrade
 *
 * It is the first step that changes an EXISTING constraint rather than adding
 * something. Everything before it only added columns and filled them, so
 * running it was free. This one alters a live column on a table with live rows
 * in production, and on SQLite Laravel implements `change()` by rebuilding the
 * table — which is exactly the operation one wants to have read before it runs
 * rather than discovered in a deploy log.
 *
 * So it waits for `reminders.legacy_subject.relax`, which ships as false. A
 * deploy cannot trigger it by accident; a person has to mean it.
 *
 * ## What it does not do
 *
 * It does not drop the column and it does not empty it. Widening a constraint
 * cannot lose a value: every row keeps its `task_id`, and the way back is
 * still a config change. Dropping it is not scheduled — and should not be
 * until something has run on the new shape long enough to trust it.
 *
 * ## The guard that matters
 *
 * It refuses while any row still lacks a subject. Relaxing the old column
 * before the backfill finished would leave rows that point at nothing on
 * either reference, and nothing would ever find them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $alt = config('reminders.legacy_subject');

        if (! is_array($alt) || ($alt['relax'] ?? false) !== true) {
            return;
        }

        $table = (string) config('reminders.tables.reminders', 'reminders');
        $spalte = (string) ($alt['column'] ?? '');

        if ($spalte === '' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $spalte)) {
            return;
        }

        $ohneGegenstand = DB::table($table)->whereNull('subject_id')->count();

        if ($ohneGegenstand > 0) {
            throw new RuntimeException(
                "Abgebrochen: {$ohneGegenstand} Zeile(n) in [{$table}] haben noch keinen Gegenstand. "
                ."Erst die Uebernahme-Migration laufen lassen, dann diese — sonst entstehen Zeilen, "
                ."die auf keiner der beiden Referenzen mehr auffindbar sind."
            );
        }

        Schema::table($table, function (Blueprint $blueprint) use ($spalte) {
            $blueprint->unsignedBigInteger($spalte)->nullable()->change();
        });
    }

    /**
     * Deliberately empty.
     *
     * The reverse — making the column NOT NULL again — fails on exactly the
     * rows this migration was run to allow: the ones with no task. A `down()`
     * that breaks when it is needed is worse than one that says so.
     */
    public function down(): void {}
};
