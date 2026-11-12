<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SALARY ADVANCE IS REQUESTED, SANCTIONED, APPROVED AT THREE LEVELS, AND
 * RECOVERED OVER A TENURE. SmartPRS 2's advance carried one amount and one
 * approver; the owner's flow (9 Sep) is: amount requested → amount sanctioned →
 * Manager / Director / HR Admin each Approved / Rejected / On Hold → tenure in
 * months → EMI computed, never typed → EMI start month. All nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('advances')) {
            return;
        }

        Schema::table('advances', function (Blueprint $table): void {
            foreach ([
                'amount_requested' => 'decimal', 'tenure_months' => 'int', 'emi_start' => 'string',
                'requested_on' => 'date', 'disbursed_on' => 'date',
                'manager_id' => 'bigint', 'manager_status' => 'string', 'manager_at' => 'datetime',
                'director_id' => 'bigint', 'director_status' => 'string', 'director_at' => 'datetime',
                'hr_id' => 'bigint', 'hr_status' => 'string', 'hr_at' => 'datetime',
            ] as $column => $kind) {
                if (Schema::hasColumn('advances', $column)) {
                    continue;
                }

                match ($kind) {
                    'decimal' => $table->decimal($column, 18, 4)->nullable(),
                    'int' => $table->integer($column)->nullable(),
                    'bigint' => $table->unsignedBigInteger($column)->nullable(),
                    'date' => $table->date($column)->nullable(),
                    'datetime' => $table->dateTime($column)->nullable(),
                    default => $table->string($column, 64)->nullable(),
                };
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
