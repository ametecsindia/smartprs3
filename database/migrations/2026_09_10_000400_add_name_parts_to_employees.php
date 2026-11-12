<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIRST / MIDDLE / LAST NAME, ALONGSIDE THE FULL NAME.
 *
 * `name` (the full name) is what the rest of the product prints and searches
 * by, and stays the record. These three were declared on the Employee fields
 * register with no column behind them — the Add form and the bulk sample
 * offered them and threw the value away. Nullable, and never derived from
 * `name` automatically: a name is split once, by a person or an import, not
 * guessed apart every time it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (!Schema::hasColumn('employees', 'first_name')) {
                $table->string('first_name')->nullable()->comment('split from the full name; not auto-derived');
            }

            if (!Schema::hasColumn('employees', 'middle_name')) {
                $table->string('middle_name')->nullable()->comment('split from the full name; not auto-derived');
            }

            if (!Schema::hasColumn('employees', 'last_name')) {
                $table->string('last_name')->nullable()->comment('split from the full name; not auto-derived');
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
