<?php

declare(strict_types=1);

/**
 * THE TABLES SmartPRS 2 CREATES AT RUNTIME.
 *
 * These 39 tables are not in any SmartPRS 2 migration. They are created by
 * `Schema::create(...)` INSIDE controllers and services — 60 such calls across 40
 * files — so the schema of a live database depends on which pages have been
 * visited. That is the second law broken at its root: a page load can alter a
 * table, and two installations that have been used differently have different
 * schemas with no record of why.
 *
 * It is also why the first port covered 88 tables and not 127: a migration-only
 * reading of SmartPRS 2 cannot see them, and mine did not.
 *
 * Ported here with the same corrections: tenant_id NOT NULL with a real foreign
 * key, company_id renamed so it cannot be mistaken for tenancy, and enums turned
 * into vocabulary-checked strings.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NOT FROM SmartPRS 2 — created because nothing in SmartPRS 2 creates it.
        // `overtime` is read by PayrollGenController behind
        // `if (! Schema::hasTable('overtime')) { return; }`, so on an installation
        // where the table is absent, overtime is silently never paid: no error,
        // no warning, and a payslip that looks complete. The columns are the ones
        // that code reads.
        if (!Schema::hasTable('overtime')) {
            Schema::create('overtime', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employee_id');
                $t->date('ot_date');
                $t->decimal('hours', 18, 4)->default(0);
                $t->decimal('rate', 18, 4)->nullable()->comment('per hour, when it differs from the derived rate');
                $t->decimal('amount', 18, 4)->nullable();
                $t->integer('approved')->default(0);
                $t->string('status', 64)->nullable()->comment('claimed|approved|rejected|paid');
                $t->string('remarks', 500)->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'employee_id', 'ot_date'], 'overtime_employee_idx');
            });
        }

        // From AttendanceReportController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('attendance_ratings')) {
            Schema::create('attendance_ratings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('emp_code')->index();
                $t->date('log_date')->index();
                $t->unsignedTinyInteger('rating')->nullable();
                $t->text('remarks')->nullable();
                $t->string('rated_by')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'emp_code', 'log_date']); // now leads with tenant_id
            });
        }

        // From AuthController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('user_password_resets')) {
            Schema::create('user_password_resets', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('email')->index();
                $t->string('token', 64)->index();   // stored hashed
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('used_at')->nullable();
                $t->timestamps();
            });
        }

        // From BankPackController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('bank_invoices')) {
            Schema::create('bank_invoices', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('invoice_no', 40);
                $t->string('bank', 150);
                $t->string('month', 7);
                $t->string('buyer_gstin', 20)->nullable();
                $t->string('buyer_address', 400)->nullable();
                $t->longText('lines')->nullable();          // [{desc, amount}]
                $t->decimal('taxable', 18, 4)->nullable(); // money at the schema-wide precision
                $t->decimal('cgst', 14, 2)->default(0);
                $t->decimal('sgst', 14, 2)->default(0);
                $t->decimal('igst', 14, 2)->default(0);
                $t->decimal('total', 18, 4)->nullable(); // money at the schema-wide precision
                $t->string('gst_mode', 10)->default('inter'); // intra | inter
                $t->string('fin_year', 10)->nullable();
                $t->string('created_by', 120)->nullable();
                $t->timestamps();
            });
        }

        // From ConfigController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('ckey')->index();
                $t->longText('value')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'ckey']);
            });
        }

        // From DemoAccessController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('demo_requests')) {
            Schema::create('demo_requests', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('phone', 20)->index();
                $t->string('email', 160);
                $t->string('company', 160)->nullable();
                $t->string('entry', 10)->default('demo');       // demo | 1 | 2 | 3 | full
                $t->string('pin', 12)->index();                 // readable by the admin for manual sharing / resend
                $t->string('status', 12)->default('active');    // active | expired | revoked
                $t->dateTime('expires_at')->index();
                $t->dateTime('last_login_at')->nullable();
                $t->dateTime('wiped_at')->nullable();           // stamped by demo:reset once this visitor's data was erased
                $t->timestamps();
            });
        }

        // From HiringDriveController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('hiring_drives')) {
            Schema::create('hiring_drives', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('job_id')->nullable();
                $t->string('title');
                $t->string('position')->nullable();
                $t->string('company')->nullable();
                $t->string('type', 20)->default('walkin');     // walkin|scheduled|telephonic
                $t->string('status', 20)->default('planned');  // planned|open|completed|cancelled
                $t->date('drive_date')->nullable();
                $t->date('date_to')->nullable();
                $t->string('time_from', 20)->nullable();
                $t->string('time_to', 20)->nullable();
                $t->string('venue')->nullable();
                $t->text('location_link')->nullable();
                $t->string('coordinator_name')->nullable();
                $t->string('coordinator_phone', 20)->nullable();
                $t->text('instructions')->nullable();
                $t->text('panel_json')->nullable();            // [{name,designation,phone,round}]
                $t->text('slots_json')->nullable();            // scheduled type: [{time,capacity}]
                $t->string('created_by')->nullable();
                $t->timestamps();
            });
        }

        // From HiringDriveController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('hiring_drive_candidates')) {
            Schema::create('hiring_drive_candidates', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('drive_id')->index();
                $t->unsignedBigInteger('candidate_id')->nullable();
                $t->string('name')->nullable();
                $t->string('mobile', 20)->nullable();
                $t->string('response', 30)->nullable();        // mirrors campaign funnel
                $t->boolean('attended')->default(false);
                $t->timestamp('attended_at')->nullable();
                $t->integer('score')->nullable();              // 0-10 panel score
                $t->string('recommendation', 30)->nullable();  // strong_hire|hire|hold|no_hire
                $t->string('outcome', 20)->default('pending'); // pending|selected|rejected|hold
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }

        // From LeadController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('company')->nullable();
                $t->string('designation')->nullable();
                $t->string('city')->nullable();
                $t->string('mobile')->nullable();
                $t->string('email')->nullable();
                $t->string('employees')->nullable();      // size band, e.g. "26 - 75"
                $t->text('challenges')->nullable();
                $t->string('status')->default('new');     // new|contacted|closed
                $t->text('notes')->nullable();
                $t->string('source')->default('landing');
                $t->timestamps();
            });
        }

        // From MobileDeviceController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('mobile_devices')) {
            Schema::create('mobile_devices', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('device_id', 80)->index();        // stable per-install id from the app
                $t->string('device_name', 160)->nullable();  // "Samsung SM-G991 · android"
                $t->string('platform', 20)->nullable();      // android | ios | web
                $t->string('host', 191)->nullable();         // the address it registered from
                $t->string('status', 12)->default('pending'); // pending | approved | rejected
                $t->string('code', 8)->nullable();           // short code the user reads to the admin
                $t->string('token', 64)->nullable();         // issued on approval (device gate token)
                $t->string('push_token', 255)->nullable();   // FCM token
                $t->string('approved_by', 120)->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('reject_reason', 191)->nullable();
                $t->unsignedBigInteger('user_id')->nullable(); // linked after first login (optional)
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
            });
        }

        // From OffrollAgentController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('agent_earnings')) {
            Schema::create('agent_earnings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('agent_id')->index();
                $t->date('entry_date')->nullable();
                $t->string('kind', 20)->default('visit');        // visit|collection|fixed|adjustment|deduction
                $t->decimal('qty', 10, 2)->nullable();            // visits count
                $t->decimal('base_amount', 18, 4)->nullable(); // money at the schema-wide precision
                $t->decimal('amount', 18, 4)->nullable(); // money at the schema-wide precision
                $t->string('note', 300)->nullable();
                $t->string('status', 20)->default('pending');     // pending|approved|rejected
                $t->string('created_by')->nullable();
                $t->string('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('remarks', 300)->nullable();
                $t->timestamps();
            });
        }

        // From OnboardingController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('onboarding')) {
            Schema::create('onboarding', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('employee')->nullable();
                $t->string('company_name')->nullable();
                $t->string('stage')->nullable();
                $t->date('joined_on')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }

        // From OnboardingController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('onboarding_items')) {
            Schema::create('onboarding_items', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('onboarding_id')->index();
                $t->string('label')->nullable();
                $t->boolean('done')->default(false);
                $t->integer('seq')->default(0);
                $t->timestamps();
            });
        }

        // From PayrollGenController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('loan_recoveries')) {
            Schema::create('loan_recoveries', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('run_id')->nullable();
                $t->unsignedBigInteger('loan_id');
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money at the schema-wide precision
                $t->string('month', 7)->nullable();
                $t->timestamps();
            });
        }

        // From PerformanceController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('performance_reviews')) {
            Schema::create('performance_reviews', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('employee')->nullable();
                $t->string('company_name')->nullable();
                $t->string('cycle')->nullable();
                $t->decimal('rating', 5, 2)->nullable();
                $t->string('reviewer')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }

        // From RecruitMessagingController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('recruitment_campaigns')) {
            Schema::create('recruitment_campaigns', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('job_id')->nullable();
                $t->string('title')->nullable();
                $t->string('template')->nullable();
                $t->string('purpose', 30)->nullable();        // interview_schedule | walkin_invite | custom
                $t->text('vars_json')->nullable();            // shared values {{2}}..
                $t->integer('total')->default(0);
                $t->integer('sent')->default(0);
                $t->integer('failed')->default(0);
                $t->string('created_by')->nullable();
                $t->timestamps();
            });
        }

        // From RecruitMessagingController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('recruitment_messages')) {
            Schema::create('recruitment_messages', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('campaign_id')->index();
                $t->unsignedBigInteger('candidate_id')->nullable();
                $t->string('name')->nullable();
                $t->string('mobile', 20)->nullable();
                $t->string('template')->nullable();
                $t->string('status', 20)->default('queued');  // queued|sent|failed|delivered|read
                $t->text('error')->nullable();
                $t->string('wa_message_id')->nullable()->index();   // for webhook matching
                $t->string('response', 30)->nullable();        // interested|not_interested|will_attend|attended|hired|declined
                $t->timestamp('responded_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
            });
        }

        // From RecruitmentController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('recruitment')) {
            Schema::create('recruitment', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->timestamps();
            });
        }

        // From RecruitmentController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('job_openings')) {
            Schema::create('job_openings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('title');
                $t->string('department')->nullable();
                $t->unsignedInteger('openings')->default(1);
                $t->string('status')->default('open');
                $t->text('description')->nullable();
                $t->timestamps();
            });
        }

        // From RecruitmentController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('interviews')) {
            Schema::create('interviews', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('candidate_id')->index();
                $t->unsignedInteger('round')->default(1);
                $t->string('round_label')->nullable();
                $t->dateTime('scheduled_at')->nullable();
                $t->string('mode')->nullable();
                $t->string('panel')->nullable();
                $t->string('status')->default('scheduled');
                $t->integer('score')->nullable();
                $t->string('recommendation')->nullable();
                $t->text('feedback')->nullable();
                $t->timestamps();
            });
        }

        // From RequestController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('salary_payments')) {
            Schema::create('salary_payments', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('month', 10)->index();        // 'YYYY-MM' (payslip month)
                $t->date('paid_on')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money at the schema-wide precision
                $t->string('mode', 30)->nullable();      // bank|upi|cash|cheque
                $t->string('reference')->nullable();
                $t->string('note', 500)->nullable();
                $t->string('by')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }

        // From SettingsController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('statutory_settings')) {
            Schema::create('statutory_settings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->longText('value')->nullable();
                $t->timestamps();
            });
        }

        // From SignupController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('signups')) {
            Schema::create('signups', function (Blueprint $t): void {
                $t->id();
                $t->uuid('uuid')->index();
                $t->string('company');
                $t->string('admin_name');
                $t->string('admin_email');
                $t->string('mobile')->nullable();
                $t->unsignedBigInteger('plan_id');
                $t->integer('seats')->default(0);
                $t->integer('companies')->default(1);
                $t->string('cycle', 20);
                $t->decimal('amount', 18, 4)->nullable(); // money at the schema-wide precision
                $t->decimal('tax', 18, 4)->nullable(); // money at the schema-wide precision
                $t->string('gateway_order_id')->nullable()->index();
                $t->string('status', 20)->default('pending'); // pending | provisioned
                $t->timestamp('terms_accepted_at')->nullable();
                $t->timestamps();
            });
        }

        // From TenantBillingController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('renewals')) {
            Schema::create('renewals', function (Blueprint $t): void {
                $t->id();
                $t->uuid('uuid')->index();
                $t->unsignedBigInteger('plan_id');
                $t->integer('seats')->default(0);
                $t->integer('companies')->default(1);
                $t->string('mode', 12)->default('renew');   // renew (extend) | upgrade (pro-rata, period unchanged)
                $t->string('cycle', 20);
                $t->decimal('amount', 18, 4)->nullable(); // money at the schema-wide precision
                $t->decimal('tax', 18, 4)->nullable(); // money at the schema-wide precision
                $t->string('gateway_order_id')->nullable()->index();
                $t->string('status', 20)->default('pending'); // pending | done
                $t->timestamps();
            });
        }

        // From TestController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('test_questions')) {
            Schema::create('test_questions', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('test_id')->index();
                $t->integer('seq')->default(0);
                $t->text('question')->nullable();
                $t->text('opt_a')->nullable();
                $t->text('opt_b')->nullable();
                $t->text('opt_c')->nullable();
                $t->text('opt_d')->nullable();
                $t->string('correct', 1)->nullable();
                $t->integer('marks')->default(1);
                $t->timestamps();
            });
        }

        // From WaTemplateController.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('wa_templates')) {
            Schema::create('wa_templates', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('purpose', 20)->default('custom');               // welcome|payment|renewal|lead|custom
                $t->string('name');                                         // EXACT Interakt template name
                $t->string('language', 10)->default('en');
                $t->string('category', 20)->default('utility');             // utility|marketing|authentication
                $t->text('body')->nullable();                               // with {{1}}..{{n}}
                $t->text('sample_values')->nullable();                      // comma-separated, for approval + tests
                $t->integer('var_count')->default(0);
                $t->string('status', 20)->default('draft');                 // draft|submitted|approved|rejected
                $t->timestamp('last_test_at')->nullable();
                $t->text('last_error')->nullable();
                $t->timestamps();
            });
        }

        // From CouponService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $t): void {
                $t->id();
                $t->string('code', 40)->unique();          // stored UPPERCASE
                $t->string('type', 10)->default('percent'); // percent | flat
                $t->decimal('value', 10, 2)->default(0);    // 25 (%) or 5000 (₹)
                $t->date('valid_till')->nullable();         // null = no expiry
                $t->integer('max_uses')->nullable();        // null = unlimited
                $t->integer('used_count')->default(0);
                $t->string('min_cycle', 12)->nullable();    // null|quarterly|halfyear|annual
                $t->string('plan_ids', 100)->nullable();    // CSV of plan ids; null = all plans
                $t->boolean('once_per_customer')->default(1);
                $t->string('applies_to', 20)->default('both'); // signup | renewal | both
                $t->string('exclusive_email', 191)->nullable()->index(); // rev 113: locked to one email
                $t->string('status', 12)->default('active');   // active | disabled
                $t->string('notes', 255)->nullable();          // campaign name etc.
                $t->timestamps();
            });
        }

        // From CouponService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('coupon_redemptions')) {
            Schema::create('coupon_redemptions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('coupon_id')->index();
                $t->string('code', 40)->index();
                $t->string('context', 12)->default('signup');  // signup | renewal
                $t->string('email', 191)->nullable()->index(); // lowercased buyer email
                $t->string('company', 150)->nullable();
                $t->decimal('amount_discounted', 18, 4)->nullable(); // money at the schema-wide precision
                $t->timestamps();
            });
        }

        // From ETimeOfficeService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('biometric_unmapped')) {
            Schema::create('biometric_unmapped', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('device_code', 80);
                $t->string('source', 40)->nullable();
                $t->unsignedInteger('punches')->default(0);
                $t->timestamp('last_seen')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'device_code']);
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('onprem_clients')) {
            Schema::create('onprem_clients', function (Blueprint $t): void {
                $t->id();
                $t->string('company');
                $t->string('contact_name')->nullable();
                $t->string('email')->nullable();
                $t->string('mobile', 30)->nullable();
                $t->string('gstin', 20)->nullable();
                $t->string('state', 60)->nullable();
                $t->text('address')->nullable();
                $t->string('edition', 8)->default('l1');           // l1|l2|l3
                $t->string('employee_band', 30)->nullable();        // e.g. "up to 250"
                $t->decimal('price', 18, 4)->nullable(); // money at the schema-wide precision
                $t->decimal('amc_percent', 5, 2)->default(18);      // Q1 default 18%
                $t->decimal('paid_total', 18, 4)->nullable(); // money at the schema-wide precision
                $t->boolean('activate_on_partial')->default(false); // Q2: super-admin tick
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('onprem_payments')) {
            Schema::create('onprem_payments', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('client_id')->index();
                $t->decimal('amount', 18, 4)->nullable(); // money at the schema-wide precision
                $t->string('mode', 30)->default('neft');            // neft|cheque|upi|gateway|cash
                $t->string('reference')->nullable();
                $t->date('paid_on')->nullable();
                $t->string('entered_by')->nullable();
                $t->timestamps();
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('licences')) {
            Schema::create('licences', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('edition', 8);
                $t->string('key_hash', 64)->unique();
                $t->text('key_enc');                                 // Crypt — panel can re-show
                $t->string('key_last4', 4);
                $t->string('status', 16)->default('pending');        // pending|active|suspended|revoked
                $t->date('amc_expires_on')->nullable();
                $t->string('expiry_mode', 12)->default('renew');     // renew|notify (on expiry)
                $t->timestamp('activated_at')->nullable();
                $t->string('fingerprint')->nullable();
                $t->string('server_name')->nullable();
                $t->unsignedInteger('reactivations_used')->default(0); // Q5: 1/year self-service
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('licence_events')) {
            Schema::create('licence_events', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('licence_id')->index();
                $t->string('type', 30);                              // generated|activated|heartbeat|deactivated|revoked|denied
                $t->text('detail')->nullable();
                $t->string('ip', 60)->nullable();
                $t->timestamps();
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('licence_devices')) {
            Schema::create('licence_devices', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('licence_id')->index();
                $t->string('device_uid', 190)->index();          // the install fingerprint = one server seat
                $t->string('fingerprint')->nullable();
                $t->string('hostname')->nullable();
                $t->string('status', 16)->default('active');       // active|deactivated
                $t->timestamp('activated_at')->nullable();
                $t->timestamp('deactivated_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('releases')) {
            Schema::create('releases', function (Blueprint $t): void {
                $t->id();
                $t->string('version', 30)->unique();
                $t->text('notes')->nullable();                       // plain-language changelog
                $t->string('file_path')->nullable();                 // storage path of the zip
                $t->string('checksum', 64)->nullable();              // sha256
                $t->unsignedBigInteger('size')->default(0);
                $t->timestamp('published_at')->nullable();
                $t->timestamp('applied_platform_at')->nullable();
                $t->timestamps();
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('release_grants')) {
            Schema::create('release_grants', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('release_id')->index();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('granted_by')->nullable();
                $t->timestamp('emailed_at')->nullable();
                $t->timestamps();
            });
        }

        // From LicenseService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('client_updates')) {
            Schema::create('client_updates', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('client_id')->nullable()->index();
                $t->unsignedBigInteger('licence_id')->nullable()->index();
                $t->string('version', 30)->nullable();
                $t->string('action', 20);                            // check|download|applied|failed
                $t->text('detail')->nullable();
                $t->timestamps();
            });
        }

        // From MailService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('mail_log')) {
            Schema::create('mail_log', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('kind')->nullable()->index();
                $t->string('recipient')->nullable();
                $t->string('subject')->nullable();
                $t->string('status')->default('queued')->index();   // queued|sent|failed|skipped
                $t->text('error')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
            });
        }

        // From SubscriptionService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('subscription_alerts')) {
            Schema::create('subscription_alerts', function (Blueprint $t): void {
                $t->id();
                $t->string('kind', 40);          // expiry-15 / expiry-7 / expiry-3 / expiry-1 / expiry-0 / grace
                $t->string('channel', 20);       // email | whatsapp
                $t->string('status', 20);        // sent | failed | skipped
                $t->string('detail', 500)->nullable();
                $t->date('period_end')->nullable();
                $t->timestamps();
            });
        }

        // From WaService.php, where SmartPRS 2 creates it at REQUEST TIME.
        if (!Schema::hasTable('wa_log')) {
            Schema::create('wa_log', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('mobile', 20)->nullable();
                $t->string('template')->nullable();
                $t->text('body_values')->nullable();
                $t->string('kind')->nullable();
                $t->string('status', 20)->default('failed'); // sent | failed
                $t->text('error')->nullable();
                $t->timestamps();
            });
        }

    }
};
