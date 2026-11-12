<?php

declare(strict_types=1);

/**
 * WHERE THE WRITE PATHS HAD NOWHERE TO PUT SOMETHING.
 *
 * Auditing every `put()` against the real schema (the `employee_code` defect,
 * found for the sixth time) split into two kinds of fault. Most were NAMES: the
 * code said `from`, the table says `from_date`, and the fix is in the code —
 * translate at the boundary and write the schema's name. Those are fixed in
 * `Leave`, `Attendance`, `Regularisation`, `Register` and `Settlement`, and no
 * column is added for them.
 *
 * The rest are facts the ported table has NO HOME for, and this is that list.
 * Same situation as `letters.body` (2026_03_01_000400): SmartPRS 2 records that
 * a thing happened, SmartPRS 3 records what it said, and dropping the difference
 * to fit the old shape would lose the evidence the feature exists for.
 *
 * `leaves`: SmartPRS 2 stores `approver_id` and nothing else about the decision.
 * A rejection with no note is a refusal an approver cannot account for six
 * months later, and the note is shown on the leave screen beside the decider.
 * `decided_by` is a LABEL (this build signs decisions with the account's email);
 * `approver_id` stays for the day accounts and employees are joined up.
 *
 * `payroll_runs`: SmartPRS 2's row is a schedule lot — cycle, pay date, employee
 * count, net total. SmartPRS 3's run additionally carries the FINGERPRINT and
 * the inputs and per-employment rows it was computed from, which is what makes a
 * closed month reproducible instead of merely asserted; `Register::verify()` and
 * `Register::correct()` both recompute from them. There is nowhere in the ported
 * shape to put any of it.
 *
 * `run_rows` / `run_inputs` rather than `rows` / `inputs`: ROWS is reserved in
 * MySQL 8, and a column that only works while every writer remembers to quote it
 * is a trap with a delay on it.
 *
 * Money stays DECIMAL(18,4), the schema's one convention — never the formatted
 * string the screen shows, which carries thousands separators and would arrive
 * as a truncated number or a refusal.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leaves') && !Schema::hasColumn('leaves', 'decision_note')) {
            Schema::table('leaves', function (Blueprint $t): void {
                $t->string('decided_by', 128)->nullable()
                    ->comment('who decided, as a label — `approver_id` is a users.id this build has no join for yet');
                $t->string('decision_note', 500)->nullable()
                    ->comment('the reason the decision went the way it did; a rejection without one cannot be reviewed');
            });
        }

        if (Schema::hasTable('payroll_runs') && !Schema::hasColumn('payroll_runs', 'fingerprint')) {
            Schema::table('payroll_runs', function (Blueprint $t): void {
                $t->string('closed_by', 255)->nullable();
                $t->string('approved_by_name', 255)->nullable()
                    ->comment('the approver as a label; `approved_by` is a users.id, and a name in it is a silent 0');
                $t->dateTime('approved_at')->nullable();
                $t->string('approval_note', 500)->nullable();
                $t->char('fingerprint', 64)->nullable()
                    ->comment('the identity a re-run must reproduce — stored so the claim can be tested, not asserted');
                $t->date('structure_effective_from')->nullable();
                $t->string('shift', 32)->nullable();
                $t->decimal('earnings_total', 18, 4)->nullable();
                $t->decimal('deductions_total', 18, 4)->nullable();
                $t->decimal('arrears_amount', 18, 4)->nullable();
                $t->decimal('payable_amount', 18, 4)->nullable()
                    ->comment('the month plus arrears — kept beside net_total, because "what did this month cost" and "what do we pay" are different questions');
                $t->json('run_inputs')->nullable();
                $t->json('run_rows')->nullable();
                $t->json('arrears')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped. `run_rows` and `fingerprint` are the only
        // record of how a closed month was computed; a rollback of a schema
        // change is not a reason to make every historic run unreproducible.
    }
};
