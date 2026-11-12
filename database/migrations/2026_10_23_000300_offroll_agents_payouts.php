<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFF-ROLL AGENTS, BUILT OUT (owner, 23 Oct 2026: "the complete pay structure, their pay report,
 * their logs or entries pending for payment … this module needs to be properly built").
 *
 *   offroll_agents        + identity, deployment, supervisor (a Directory employee), bank, PAN, TDS,
 *                           DRA / PCC certificate numbers with issue and expiry dates
 *   offroll_vendors       the agencies agents come through; a vendor-paid agent's payout goes here
 *   offroll_pay_plans     the pay structure per agent, effective-dated — a revision is a new row
 *   offroll_activity      one row per agent per day: attendance + field work; manual, upload or app
 *   offroll_visits        the app's visit log (each visit), rolled up into that day's activity
 *   offroll_payouts       a month's payout run per company: draft → approved
 *   offroll_payout_lines  one line per agent (or vendor) in a run, with its derivation and payment
 *   agent_earnings        + payout_id, so an adjustment counted in a run is not counted again
 *   agent_authorizations  + agent_id, so a bank's letter can be issued to an off-roll agent too
 *
 * All new columns nullable; nothing existing changes meaning. Never dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = [
            'offroll_agents' => [
                'agent_code' => 'string', 'vendor_id' => 'bigint', 'paid_to' => 'string', 'supervisor_employee_id' => 'bigint',
                'client_bank' => 'string', 'portfolio' => 'string', 'joining_date' => 'date', 'exit_date' => 'date',
                'dob' => 'date', 'gender' => 'string', 'pan' => 'string', 'aadhaar_last4' => 'string', 'address' => 'text', 'city' => 'string',
                'bank_name' => 'string', 'bank_account' => 'string', 'ifsc' => 'string', 'entity_type' => 'string', 'tds_section' => 'string',
                'dra_cert_no' => 'string', 'dra_issue_date' => 'date', 'dra_expiry' => 'date',
                'pcc_cert_no' => 'string', 'pcc_issue_date' => 'date', 'pcc_expiry' => 'date', 'deleted_at' => 'datetime',
            ],
            'agent_earnings' => ['payout_id' => 'bigint'],
            'agent_authorizations' => ['agent_id' => 'bigint'],
        ];

        foreach ($add as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column => $kind) {
                    if (Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    match ($kind) {
                        'datetime' => $table->dateTime($column)->nullable(),
                        'date' => $table->date($column)->nullable(),
                        'bigint' => $table->unsignedBigInteger($column)->nullable(),
                        'text' => $table->text($column)->nullable(),
                        default => $table->string($column, 191)->nullable(),
                    };
                }
            });
        }

        if (!Schema::hasTable('offroll_vendors')) {
            Schema::create('offroll_vendors', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employer_company_id')->nullable();
                $t->string('name')->nullable();
                $t->string('contact_person')->nullable();
                $t->string('mobile')->nullable();
                $t->string('email')->nullable();
                $t->string('pan')->nullable();
                $t->string('gstin')->nullable();
                $t->string('entity_type', 32)->nullable();
                $t->string('bank_name')->nullable();
                $t->string('bank_account')->nullable();
                $t->string('ifsc')->nullable();
                $t->date('agreement_from')->nullable();
                $t->date('agreement_to')->nullable();
                $t->string('tds_section', 32)->nullable();
                $t->string('status', 64)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('offroll_pay_plans')) {
            Schema::create('offroll_pay_plans', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('agent_id')->index();
                $t->date('effective_from')->nullable();
                $t->decimal('fixed_monthly', 18, 4)->nullable();
                $t->decimal('per_day_rate', 18, 4)->nullable();
                $t->decimal('per_visit_rate', 18, 4)->nullable();
                $t->decimal('per_ptp_rate', 18, 4)->nullable();
                $t->decimal('per_paid_account_rate', 18, 4)->nullable();
                $t->decimal('collection_percent', 18, 4)->nullable();
                $t->text('collection_slabs')->nullable();
                $t->decimal('monthly_cap', 18, 4)->nullable();
                $t->string('note', 300)->nullable();
                $t->string('created_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('offroll_activity')) {
            Schema::create('offroll_activity', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('agent_id')->index();
                $t->date('activity_date')->index();
                $t->string('day_status', 20)->nullable();
                $t->string('check_in', 8)->nullable();
                $t->string('check_out', 8)->nullable();
                $t->string('check_in_geo', 64)->nullable();
                $t->string('check_out_geo', 64)->nullable();
                $t->unsignedInteger('allocated_accounts')->nullable();
                $t->unsignedInteger('visits')->nullable();
                $t->unsignedInteger('ptp_count')->nullable();
                $t->unsignedInteger('paid_accounts')->nullable();
                $t->decimal('amount_collected', 18, 4)->nullable();
                $t->string('remarks', 300)->nullable();
                $t->string('source', 20)->nullable();
                $t->string('approval', 20)->nullable();
                $t->string('created_by')->nullable();
                $t->string('decided_by')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->string('decision_remarks', 300)->nullable();
                $t->unsignedBigInteger('payout_id')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('offroll_visits')) {
            Schema::create('offroll_visits', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('agent_id')->index();
                $t->unsignedBigInteger('activity_id')->nullable();
                $t->dateTime('visit_at')->nullable();
                $t->string('account_ref', 64)->nullable();
                $t->string('outcome', 32)->nullable();
                $t->decimal('amount', 18, 4)->nullable();
                $t->string('geo', 64)->nullable();
                $t->string('note', 300)->nullable();
                $t->string('external_id', 96)->nullable()->index();
                $t->string('source', 20)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('offroll_payouts')) {
            Schema::create('offroll_payouts', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employer_company_id')->nullable();
                $t->string('period', 7)->index();
                $t->string('status', 20)->nullable();
                $t->text('settings')->nullable();
                $t->unsignedInteger('lines')->nullable();
                $t->decimal('gross', 18, 4)->nullable();
                $t->decimal('tds', 18, 4)->nullable();
                $t->decimal('net', 18, 4)->nullable();
                $t->string('created_by')->nullable();
                $t->string('approved_by')->nullable();
                $t->dateTime('approved_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('offroll_payout_lines')) {
            Schema::create('offroll_payout_lines', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('payout_id')->index();
                $t->unsignedBigInteger('agent_id')->index();
                $t->unsignedBigInteger('vendor_id')->nullable();
                $t->string('paid_to', 20)->nullable();
                $t->decimal('days_present', 8, 2)->nullable();
                $t->decimal('days_basis', 8, 2)->nullable();
                $t->unsignedInteger('visits')->nullable();
                $t->unsignedInteger('ptp_count')->nullable();
                $t->unsignedInteger('paid_accounts')->nullable();
                $t->decimal('amount_collected', 18, 4)->nullable();
                $t->decimal('fixed_amount', 18, 4)->nullable();
                $t->decimal('day_amount', 18, 4)->nullable();
                $t->decimal('visit_amount', 18, 4)->nullable();
                $t->decimal('ptp_amount', 18, 4)->nullable();
                $t->decimal('paid_account_amount', 18, 4)->nullable();
                $t->decimal('collection_amount', 18, 4)->nullable();
                $t->decimal('adjustments', 18, 4)->nullable();
                $t->decimal('deductions', 18, 4)->nullable();
                $t->decimal('gross', 18, 4)->nullable();
                $t->string('tds_section', 32)->nullable();
                $t->decimal('tds_rate', 8, 4)->nullable();
                $t->decimal('tds_amount', 18, 4)->nullable();
                $t->decimal('net', 18, 4)->nullable();
                $t->text('explain')->nullable();
                $t->string('flags', 300)->nullable();
                $t->string('payment_status', 20)->nullable();
                $t->date('paid_on')->nullable();
                $t->string('payment_mode', 20)->nullable();
                $t->string('payment_ref', 64)->nullable();
                $t->string('paid_by')->nullable();
                $t->string('hold_reason', 300)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: payouts are money that was paid.
    }
};
