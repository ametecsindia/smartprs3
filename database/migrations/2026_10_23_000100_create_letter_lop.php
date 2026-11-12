<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOSS OF PAY CARRIED BY AN ISSUED LETTER (owner, 23 Oct 2026): a half-day deduction letter, an LOP letter
 * for N days. One row per letter that charges LOP; PayrollPeriod takes the active rows off the days payable
 * of the pay period their date falls in. Days are held in HALVES (integer) — never a float. A wrong charge
 * is WITHDRAWN (status), never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_lop')) {
            return;
        }

        Schema::create('letter_lop', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('employee_code', 64)->index();
            $t->unsignedBigInteger('letter_id')->index();
            $t->date('on_date')->index();
            $t->unsignedInteger('lop_halves');   // 1 = half a day, 2 = one day
            $t->string('reason')->nullable();
            $t->string('status', 16)->default('active');   // active | withdrawn
            $t->string('created_by')->nullable();
            $t->string('withdrawn_by')->nullable();
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
