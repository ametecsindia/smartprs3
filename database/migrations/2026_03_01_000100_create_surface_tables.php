<?php

declare(strict_types=1);

/**
 * THE TABLES SmartPRS 2 HAS NO EQUIVALENT FOR.
 *
 * Every table here exists because the rebuild does something SmartPRS 2 does
 * not, and each one is listed with that reason. Nothing here duplicates a ported
 * table: where SmartPRS 2 already has the concept, the surface uses ITS table
 * (`users`, `tenants`, `payroll_runs`, `employees`, `attendance_logs`, `leaves`,
 * `attendance_corrections`), because two homes for the same data is the defect
 * this whole rebuild is meant to remove, not one to introduce.
 *
 * `enrolment` is the only PLATFORM-scoped table here: it hands out the one-time
 * token that creates the first account, which necessarily happens before there
 * is a tenant to scope it by.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A one-time enrolment token, so the first account is created by
        // somebody holding a secret rather than by a default credential
        // shipped in the source. SmartPRS 2 has no equivalent.
        if (!Schema::hasTable('enrolment')) {
            Schema::create('enrolment', function (Blueprint $t): void {
                $t->id();
                $t->string('token', 64);
                $t->boolean('used')->default(false);
                $t->dateTime('issued_at')->nullable();
                $t->timestamps();
                $t->unique(['token'], 'enrolment_token_unique');
            });
        }

        // The leave LEDGER. SmartPRS 2 stores a balance; this stores every
        // credit and debit with the date it takes effect, so the balance is
        // computed at the date asked about and can be taken apart line by line.
        if (!Schema::hasTable('leave_entries')) {
            Schema::create('leave_entries', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('employment_id', 64);
                $t->string('leave_type', 32);
                $t->string('kind', 32)->comment('accrual|opening|consumed|lapsed|encashed|reversal|adjustment');
                $t->decimal('days', 18, 4);
                $t->date('effective_on')->comment('the date the entry takes effect, not the date it was written');
                $t->string('reason', 255)->nullable();
                $t->string('source_reference', 128)->nullable()
                    ->comment('names what caused it — makes accrual idempotent and a debit traceable');
                $t->timestamps();
                $t->index(['tenant_id', 'employment_id', 'leave_type'], 'leave_entries_balance_idx');
                $t->unique(['tenant_id', 'source_reference'], 'leave_entries_source_unique');
            });
        }

        // A correction to a CLOSED month, stored beside the original run rather
        // than replacing it. SmartPRS 2 re-runs the month, which destroys the
        // difference — the only figure anyone acts on.
        if (!Schema::hasTable('payroll_corrections')) {
            Schema::create('payroll_corrections', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('period', 7);
                $t->string('corrects', 64)->comment('the run this corrects — never modified');
                $t->string('original_fingerprint', 64);
                $t->string('new_fingerprint', 64);
                $t->dateTime('raised_at')->nullable();
                $t->string('raised_by', 255)->nullable();
                $t->string('reason', 500);
                $t->bigInteger('net_delta_minor')->default(0);
                $t->json('deltas')->nullable();
                $t->string('settled_in', 7)->nullable()->comment('the period that paid it as arrears');
                $t->dateTime('settled_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'period'], 'payroll_corrections_period_idx');
            });
        }

        // Effective-dated salary structure versions. SmartPRS 2 edits components
        // in place, which makes every historic payslip unreproducible.
        if (!Schema::hasTable('structures')) {
            Schema::create('structures', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->date('effective_from');
                $t->string('note', 500)->nullable();
                // Nullable in the COLUMN, refused in the publish path: a version
                // with no components is meaningless, and `Structure::publish()`
                // rejects it with the reason. A NOT NULL json column adds a
                // validity constraint that any generic writer (the isolation
                // suite included) trips over, without catching anything the
                // publish path does not already catch.
                $t->json('components')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'effective_from'], 'structures_effective_unique');
            });
        }

        // Full and final. `exits` was ported from SmartPRS 2 and records THAT
        // somebody left; this records what they were PAID on leaving, with the
        // encashment that was debited from the ledger.
        if (!Schema::hasTable('settlements')) {
            Schema::create('settlements', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('employment_id', 64);
                $t->string('name', 255)->nullable();
                $t->date('last_working_day');
                $t->integer('payable_days')->default(0);
                $t->integer('month_days')->default(0);
                $t->decimal('final_pay', 18, 4)->default(0);
                $t->decimal('total', 18, 4)->default(0);
                $t->json('encashments')->nullable();
                $t->json('concerns')->nullable();
                $t->dateTime('settled_at')->nullable();
                $t->string('settled_by', 255)->nullable();
                $t->string('note', 500)->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'employment_id'], 'settlements_once_unique');
            });
        }

        // The HASH-CHAINED trail. `activity_logs` was ported and records what
        // happened; this records it in a chain, so a row edited or deleted after
        // the fact is detectable rather than merely absent.
        if (!Schema::hasTable('audit')) {
            Schema::create('audit', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('seq');
                $t->string('action', 128);
                $t->string('entity_type', 64);
                $t->string('entity_id', 64)->nullable();
                $t->string('actor_id', 64)->nullable();
                $t->string('actor', 255)->nullable();
                $t->string('reason', 500)->nullable();
                $t->dateTime('occurred_at')->nullable();
                $t->json('payload')->nullable();
                $t->char('prev_hash', 64)->nullable();
                $t->char('hash', 64);
                $t->timestamps();
                $t->unique(['tenant_id', 'seq'], 'audit_seq_unique');
            });
        }
    }
};
