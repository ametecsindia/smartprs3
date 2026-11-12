<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOANS / SALARY ADVANCE RECOVERY (owner, 22 Sep).
 *
 * 1. `advances.recovery_mode` and `loans.recovery_mode` — Option 1 'emi'
 *    (tenure → EMI, recovered every month) or Option 2 'manual' (no EMI;
 *    payroll takes only what is recorded). NULL reads as 'emi', which is what
 *    every row before this migration was.
 *
 * 2. `loan_recovery_entries` — an amount recorded against one advance or loan:
 *    either deducted from a named month's payroll (mode 'payroll', becomes that
 *    month's ADV-<id> / LOAN-<id> line) or collected outside payroll (mode
 *    'collected', lowers the outstanding from the month received). APPEND-ONLY
 *    (law 3): a mistake is cancelled — status, who, when, why — never edited or
 *    deleted. Money is DECIMAL(18,4) (law 7).
 *
 * Until this runs, surface/RecoveryEntries.php keeps the entries in the
 * tenant's file store and the screen says so; Option 2 cannot be saved on a
 * database install until the recovery_mode column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['advances', 'loans'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'recovery_mode')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('recovery_mode', 16)->nullable()
                        ->comment("'emi' (tenure → EMI) or 'manual' (only what Loans / Salary Advance Recovery records); NULL is 'emi'");
                });
            }
        }

        if (Schema::hasTable('loan_recovery_entries')) {
            return;
        }

        Schema::create('loan_recovery_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->index();
            $t->string('kind', 16)->comment("'advance' or 'loan'");
            $t->string('obligation_id', 64)->comment('advances.id or loans.id');
            $t->string('line_code', 80)->comment('the payroll line it is read under: ADV-<id> / LOAN-<id>');
            $t->string('employee_id', 64);
            $t->string('mode', 16)->comment("'payroll' (deduct from that month) or 'collected' (received outside payroll)");
            $t->char('period', 7)->comment('YYYY-MM: the payroll month, or the month collected');
            $t->decimal('amount', 18, 4);
            $t->date('collected_on')->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('remarks', 255)->nullable();
            $t->string('status', 16)->default('active')->comment("'active' or 'cancelled' — never deleted");
            $t->string('created_by', 255)->nullable();
            $t->string('cancelled_by', 255)->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();

            $t->index(['tenant_id', 'line_code', 'period'], 'loan_recovery_entries_line');
            $t->index(['tenant_id', 'employee_id'], 'loan_recovery_entries_employee');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: recovery history is evidence.
    }
};
