<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRAINING MOVES TO THE EMPLOYEE, TENURE BASIS TO THE COMPANY (owner, 15 Oct).
 *   employees.training_start_date / training_end_date — each joiner's own induction training (Directory).
 *   companies.tenure_basis — Date of joining / Date of production / Date of confirmation.
 * Whether training is paid stays on the company (paid_during_training). The company's old training_start_date
 * / training_end_date columns are kept, not dropped (law 3), and are no longer read or shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['employees' => ['training_start_date' => 'date', 'training_end_date' => 'date'], 'companies' => ['tenure_basis' => 'string']] as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $type) {
                if (!Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $t) use ($column, $type): void {
                        $type === 'date' ? $t->date($column)->nullable() : $t->string($column, 24)->nullable();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
