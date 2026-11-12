<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORM 130 (OLD FORM 16) PART A (owner, 15 Oct) — the TDS certificate the employer downloads from
 * TRACES after filing the quarterly returns. SmartPRS 3 cannot make Part A; it keeps the PDF HR
 * uploads, one live row per employee per financial year. A re-upload is a NEW row and the earlier
 * one is retired with deleted_at (law 3). The file itself sits under storage/, never public/.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('form16_part_a')) {
            Schema::create('form16_part_a', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('financial_year', 7)->index();
                $t->string('pan', 10)->nullable();
                $t->string('file_path');
                $t->string('file_name')->nullable();
                $t->string('sha256', 64)->nullable();
                $t->string('uploaded_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
