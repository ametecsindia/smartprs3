<?php

declare(strict_types=1);

/**
 * THE COLUMNS SmartPRS 2 ADDS AT RUNTIME.
 *
 * The same law broken a second way. Beyond creating whole tables inside
 * controllers, SmartPRS 2 ALTERS them there too — an `addCols()` helper that adds
 * a column if it is not there, called on every request. `recruitment` is created
 * with four columns and then grown to thirty-four by the page that lists it.
 *
 * So the candidate table's real shape is not in any migration, and neither is the
 * offer: `offered_ctc`, `offer_doj`, `offer_status`, `expected_ctc`, `skills`,
 * `resume_url` all arrive this way. A schema that depends on which pages have run
 * cannot be reviewed, diffed or restored.
 *
 * Ported here as one migration. Money arrives at the schema's own DECIMAL(18,4)
 * rather than the precision it happened to have.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $t): void {
                $t->string('prev_hash')->nullable();
                $t->string('row_hash')->nullable();
        });

        Schema::table('attendance_logs', function (Blueprint $t): void {
                $t->string('geo_status')->nullable();
        });

        Schema::table('clawbacks', function (Blueprint $t): void {
                $t->decimal('recovered_amount', 18, 4)->nullable();
                $t->dateTime('recovered_at')->nullable();
                $t->unsignedBigInteger('recovered_run_id')->nullable();
        });

        Schema::table('commissions', function (Blueprint $t): void {
                $t->decimal('base_amount', 18, 4)->nullable();
        });

        Schema::table('documents', function (Blueprint $t): void {
                $t->unsignedBigInteger('file_size')->nullable();
        });

        Schema::table('employees', function (Blueprint $t): void {
                $t->string('photo_path')->nullable();
        });

        Schema::table('invoices', function (Blueprint $t): void {
                $t->date('paid_on')->nullable();
        });

        Schema::table('job_openings', function (Blueprint $t): void {
                $t->string('approval_status')->nullable();
                $t->dateTime('approved_at')->nullable();
                $t->string('approved_by')->nullable();
                $t->decimal('ctc_max', 18, 4)->nullable();
                $t->decimal('ctc_min', 18, 4)->nullable();
                $t->string('engagement')->nullable();
                $t->integer('filled')->nullable();
                $t->text('justification')->nullable();
                $t->string('location')->nullable();
                $t->string('priority')->nullable();
                $t->string('reject_reason')->nullable();
                $t->string('req_code')->nullable();
                $t->string('requested_by')->nullable();
                $t->date('target_date')->nullable();
        });

        Schema::table('leaves', function (Blueprint $t): void {
                $t->dateTime('decided_at')->nullable();
        });

        Schema::table('licences', function (Blueprint $t): void {
                $t->dateTime('last_mismatch_at')->nullable();
                $t->integer('server_limit')->nullable();
        });

        Schema::table('loans', function (Blueprint $t): void {
                $t->integer('installments_paid')->nullable();
        });

        Schema::table('overtime', function (Blueprint $t): void {
                $t->unsignedBigInteger('paid_run_id')->nullable();
        });

        Schema::table('payment_gateways', function (Blueprint $t): void {
                $t->integer('companies')->nullable();
        });

        Schema::table('payslips', function (Blueprint $t): void {
                $t->text('calc_note')->nullable();
        });

        Schema::table('recruitment', function (Blueprint $t): void {
                $t->date('applied_on')->nullable();
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('company_name')->nullable();
                $t->string('current_company')->nullable();
                $t->decimal('current_ctc', 18, 4)->nullable();
                $t->string('current_designation')->nullable();
                $t->string('email')->nullable();
                $t->decimal('expected_ctc', 18, 4)->nullable();
                $t->decimal('experience_years', 18, 4)->nullable();
                $t->text('interview_remarks')->nullable();
                $t->unsignedBigInteger('job_id')->nullable();
                $t->decimal('joining_bonus', 18, 4)->nullable();
                $t->string('location')->nullable();
                $t->string('mobile')->nullable();
                $t->string('name')->nullable();
                $t->text('notes')->nullable();
                $t->date('offer_doj')->nullable();
                $t->decimal('offer_incentive', 18, 4)->nullable();
                $t->text('offer_notes')->nullable();
                $t->string('offer_status')->nullable();
                $t->decimal('offered_ctc', 18, 4)->nullable();
                $t->integer('pool')->nullable();
                $t->string('position')->nullable();
                $t->decimal('proposed_ctc', 18, 4)->nullable();
                $t->integer('rating')->nullable();
                $t->string('reject_reason')->nullable();
                $t->string('resume_url')->nullable();
                $t->text('skills')->nullable();
                $t->string('source')->nullable();
                $t->string('stage')->nullable();
        });

        Schema::table('renewals', function (Blueprint $t): void {
                $t->string('coupon_code')->nullable();
                $t->decimal('coupon_discount', 18, 4)->nullable();
        });

        Schema::table('signups', function (Blueprint $t): void {
                $t->string('coupon_code')->nullable();
                $t->decimal('coupon_discount', 18, 4)->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $t): void {
                $t->integer('companies')->nullable();
        });

        Schema::table('tenants', function (Blueprint $t): void {
                $t->string('custom_domain')->nullable();
                $t->string('state')->nullable();
        });

        Schema::table('users', function (Blueprint $t): void {
                $t->string('photo_path')->nullable();
        });

    }
};
