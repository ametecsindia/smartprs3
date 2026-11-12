<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROFESSIONAL TAX REMITTANCES (owner, 15 Oct): PT paid to the state — one line per employee per wage
 * month, the state it is paid to, and the challan it went in. Same shape as PF & ESI and TDS remittances.
 * Plus the company's PT registration (enrolment / PTRC) number, printed beside its remittances.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pt_remittances')) {
            Schema::create('pt_remittances', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('month', 7)->index();
                $t->string('state', 64)->nullable();
                $t->decimal('gross_wages', 18, 4)->nullable();
                $t->decimal('pt_deducted', 18, 4)->nullable();
                $t->decimal('pt_paid', 18, 4)->nullable();
                $t->string('challan_no', 64)->nullable();
                $t->date('challan_date')->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'pt_registration_no')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->string('pt_registration_no', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
