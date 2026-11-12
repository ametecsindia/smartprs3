<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDS DECLARATIONS AND INVESTMENT PROOFS (owner, 14 Oct).
 *
 * tax_declarations — one employee's declaration for one financial year: the regime, every
 *   amount declared (`items`, JSON code => rupees, so a Budget that adds a section changes
 *   config/income-tax.json and never this schema) and the rent for HRA (`rent`, JSON). A
 *   re-declaration is a NEW row; the earlier one is retired, never overwritten (law 3).
 * tax_proofs — one uploaded proof against one declared item, with HR's verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tax_declarations')) {
            Schema::create('tax_declarations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('financial_year', 7)->index();
                $t->string('regime', 8);
                $t->text('items')->nullable();
                $t->text('rent')->nullable();
                $t->string('parents_senior', 3)->nullable();
                $t->string('status', 16)->default('submitted');
                $t->dateTime('submitted_at')->nullable();
                $t->string('submitted_by')->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('tax_proofs')) {
            Schema::create('tax_proofs', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('financial_year', 7)->index();
                $t->string('item_code', 32);
                $t->decimal('amount_claimed', 18, 4)->nullable();
                $t->string('file_path')->nullable();
                $t->string('file_name')->nullable();
                $t->string('status', 16)->default('pending');
                $t->decimal('approved_amount', 18, 4)->nullable();
                $t->string('reviewed_by')->nullable();
                $t->dateTime('reviewed_at')->nullable();
                $t->string('review_note')->nullable();
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
