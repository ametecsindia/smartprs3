<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PF & ESI REMITTANCES (owner, 14 Oct): what the company actually submitted to
 * EPFO (the ECR member line + TRRN) and to ESIC (the monthly contribution line +
 * challan), one row per employee per wage month, kept for the record. Nothing
 * here is computed — the figures are as filed, bulk-uploaded or typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pf_esi_remittances')) {
            return;
        }

        Schema::create('pf_esi_remittances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->unsignedBigInteger('employee_id')->index(); // NOT NULL: a remittance line is somebody's
            $t->string('month', 7)->index();               // wage month, YYYY-MM
            // EPFO — the ECR member line
            $t->string('uan', 32)->nullable();
            $t->decimal('gross_wages', 18, 4)->nullable();
            $t->decimal('epf_wages', 18, 4)->nullable();
            $t->decimal('eps_wages', 18, 4)->nullable();
            $t->decimal('edli_wages', 18, 4)->nullable();
            $t->decimal('epf_ee_share', 18, 4)->nullable();   // employee share
            $t->decimal('eps_er_share', 18, 4)->nullable();   // employer, to pension
            $t->decimal('epf_er_share', 18, 4)->nullable();   // employer, EPF difference
            $t->decimal('ncp_days', 18, 4)->nullable();
            $t->decimal('refund_of_advances', 18, 4)->nullable();
            $t->string('pf_trrn', 64)->nullable();
            $t->date('pf_paid_on')->nullable();
            // ESIC — the monthly contribution line
            $t->string('esi_ip_number', 32)->nullable();
            $t->decimal('esi_days', 18, 4)->nullable();
            $t->decimal('esi_wages', 18, 4)->nullable();
            $t->decimal('esi_ee_share', 18, 4)->nullable();
            $t->decimal('esi_er_share', 18, 4)->nullable();
            $t->string('esi_reason_code', 64)->nullable();
            $t->date('esi_last_working_day')->nullable();
            $t->string('esi_challan_no', 64)->nullable();
            $t->date('esi_paid_on')->nullable();
            $t->string('remarks')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
