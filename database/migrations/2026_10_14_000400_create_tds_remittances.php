<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDS REMITTANCES (owner, 14 Oct): the TDS on salary deposited to the government — one line per
 * employee per wage month, with the challan (ITNS 281) it went in: BSR code, deposit date, challan
 * serial number. Exactly the deductee lines the quarterly return (Form 138, old 24Q) needs.
 * Nothing computed here: figures are as deposited, from the template or typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tds_remittances')) {
            return;
        }

        Schema::create('tds_remittances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('month', 7)->index();
            $t->string('pan', 10)->nullable();
            $t->decimal('amount_paid', 18, 4)->nullable();     // salary paid / credited in the month
            $t->decimal('tds_deducted', 18, 4)->nullable();
            $t->decimal('tds_deposited', 18, 4)->nullable();
            $t->string('section', 16)->nullable();             // 392 (old 192)
            $t->string('bsr_code', 7)->nullable();
            $t->date('challan_date')->nullable();
            $t->string('challan_serial', 5)->nullable();
            $t->string('cin', 32)->nullable();
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
