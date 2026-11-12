<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMPANY'S INDUCTION TRAINING (owner, 15 Oct): training start and end date and whether it is paid.
 * A joiner in the batch is paid — and their salary structure takes effect — from the date of joining when
 * the training is paid, else from the first working day after it ends (SalaryEffective::payStart).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        foreach (['training_start_date' => 'date', 'training_end_date' => 'date', 'paid_during_training' => 'string'] as $column => $type) {
            if (!Schema::hasColumn('companies', $column)) {
                Schema::table('companies', function (Blueprint $table) use ($column, $type): void {
                    $type === 'date' ? $table->date($column)->nullable() : $table->string($column, 8)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
