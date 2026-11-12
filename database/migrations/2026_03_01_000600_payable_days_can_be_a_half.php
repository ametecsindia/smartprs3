<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DAY IS NOT ALWAYS A WHOLE NUMBER, and the column said it was.
 *
 * `settlements.payable_days` is `INT(11)`, which was a perfectly good decision
 * for as long as the product's only opinion about a day was "worked, or not".
 * The rule engine changed that: `config/attendance-rules.json` now classifies a
 * day PRESENT, HALF_DAY or ABSENT, an in-punch with no out-punch is half a day
 * rather than a full absence, and half a day is what the settlement has to be
 * able to record.
 *
 * WHAT THE INT COLUMN WOULD HAVE DONE IS THE POINT. MySQL does not refuse 17.5
 * into an INT — it stores 18, or 17 in strict mode's absence, and reports
 * success. Nothing would have failed, no screen would have said anything, and a
 * person would have been paid for a day they did not work or not paid for one
 * they did. That is the exact shape of the defects this rebuild exists to
 * remove, and it would have arrived through a column type rather than through
 * any line of code anybody wrote.
 *
 * DECIMAL, NOT FLOAT (law 7). Two places is enough for halves and quarters,
 * which is every attendance policy anyone has asked for; a third would be a day
 * measured in fractions nobody can explain to the person it belongs to.
 *
 * Widening only — every existing whole-number value survives unchanged, which is
 * why this needs no data migration and no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settlements') || !Schema::hasColumn('settlements', 'payable_days')) {
            return;
        }

        Schema::table('settlements', function (Blueprint $t): void {
            $t->decimal('payable_days', 6, 2)->nullable()->change()
                ->comment('exact, because the rule engine can say half a day and an INT would round it silently');
        });
    }

    public function down(): void
    {
        // Deliberately not narrowed back. Going DECIMAL(6,2) -> INT would round
        // every half day that has been recorded since, silently, in the
        // direction MySQL happens to choose — which is the entire reason this
        // migration exists. A rollback of a schema change is not a reason to
        // quietly restate what somebody was paid.
    }
};
