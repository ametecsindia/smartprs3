<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO RETURNED A UNIT (owner, 29 Sep): Exit & FnF waits until everything an
 * employee held is returned AND every Damaged/Lost return is recovered or
 * waived. A return clears `issued_to_employee_id`, so the last holder is kept
 * here — the one column that says who owes a damaged or lost unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('assets') || Schema::hasColumn('assets', 'returned_from_employee_id')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('returned_from_employee_id')->nullable()
                ->comment('Employee who last returned this unit (employees.id); owes a damaged/lost return until settled');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
