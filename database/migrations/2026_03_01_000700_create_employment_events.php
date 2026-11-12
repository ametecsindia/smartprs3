<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEAVING IS AN EVENT WITH A DATE, NOT A FLAG.
 *
 * `employees` carries `doj` and it carries `status` and `employment_stage`, and
 * it carries NO EXIT DATE AT ALL. So the product knows somebody has left and
 * does not know when — which is the shape `Platform\Lifecycle\EmploymentTimeline`
 * was written against, and its docblock names the consequence exactly: SmartPRS 2
 * "issues a payslip for somebody who left in March because leaving is a flag
 * rather than an event with a date".
 *
 * A column per event is the other wrong answer, and it is the one that looks
 * tidier. `confirmed_on`, `promoted_on`, `resigned_on`, `exited_on` answers "when
 * did they leave" and cannot answer "what was their grade in August?" — a column
 * holds today's value, a timeline holds what was true on a date. It also cannot
 * hold two transfers.
 *
 * NOT `probation_events`, WHICH ALREADY EXISTS AND HAS EXACTLY THESE COLUMNS.
 * The ported table is employee_id / action / details / effective / by / at, which
 * is a generic dated event log wearing one event's name. Reusing it would have
 * cost nothing today and left every future reader of the schema believing the
 * confirmations, transfers and exits in it were something to do with probation.
 * A table whose name misdescribes its contents is a defect with a long fuse.
 *
 * The ORDERING is enforced in code, not here: an employee cannot be promoted
 * after they exit, confirmed before they join, or resigned twice, and those are
 * sequence rules over rows rather than constraints on one. `EmploymentTimeline`
 * holds them and refuses; the unique index below only stops the same event being
 * recorded twice for the same day, which is the duplicate a double-click makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employment_events')) {
            return;
        }

        Schema::create('employment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('employment_id', 64)->index()
                ->comment('the employment CODE, as every other surface table keys people');
            $table->string('kind', 32)
                ->comment('joined, probation_started, confirmed, increment, promoted, transferred, resigned, exited');
            $table->date('effective_on')
                ->comment('THE DATE THE EVENT HAPPENED, not the date it was typed in — a back-dated exit is the normal case');
            $table->string('reason', 500)->nullable();
            $table->json('detail')->nullable()
                ->comment('what changed: the new grade, the new branch, the notice period');
            $table->string('recorded_by', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'employment_id', 'kind', 'effective_on'],
                'employment_events_once');
            $table->index(['tenant_id', 'employment_id', 'effective_on'],
                'employment_events_timeline');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped. This table is the only record of when
        // somebody joined, was confirmed and left; a rollback of a schema change
        // is not a reason to lose the dates a payslip was prorated against.
    }
};
