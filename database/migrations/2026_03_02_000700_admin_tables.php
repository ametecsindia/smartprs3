<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADMINISTRATION at SmartPRS 2 parity.
 *
 * SmartPRS 2's Banks register carries the company, account holder, account
 * number, IFSC, branch and purpose; the ported `banks` table has only a name.
 * Its Email / SMTP screen keeps one mail server per company; nothing was
 * ported for it (`company_emails` is the FROM-identity register, not the
 * server). Its Mobile Devices screen ties a phone to a person; the ported
 * `mobile_devices` row knows only a user id. All nullable; the new table
 * carries the tenant FK exactly like the ported attendance tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('smtp_settings')) {
            Schema::create('smtp_settings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->string('host'); // NOT NULL: a mail server with no host is not one
                $t->unsignedInteger('port')->nullable();
                $t->string('encryption', 16)->nullable();
                $t->string('username')->nullable();
                $t->text('password')->nullable();
                $t->string('from_name')->nullable();
                $t->string('from_email')->nullable();
                $t->string('status', 64)->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (Schema::hasTable('banks')) {
            Schema::table('banks', function (Blueprint $t): void {
                foreach ([
                    'employer_company_id' => 'unsignedBigInteger',
                    'account_holder' => 'string',
                    'account_number' => 'string',
                    'ifsc' => 'string',
                    'bank_branch' => 'string',
                    'purpose' => 'string',
                    'status' => 'string',
                    'deleted_at' => 'dateTime',
                ] as $column => $type) {
                    if (!Schema::hasColumn('banks', $column)) {
                        $t->{$type}($column)->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('mobile_devices')) {
            Schema::table('mobile_devices', function (Blueprint $t): void {
                foreach ([
                    'employee_id' => 'unsignedBigInteger',
                    'model' => 'string',
                    'os_version' => 'string',
                    'deleted_at' => 'dateTime',
                ] as $column => $type) {
                    if (!Schema::hasColumn('mobile_devices', $column)) {
                        $t->{$type}($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};
