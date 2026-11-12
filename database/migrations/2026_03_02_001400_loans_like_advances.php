<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOANS FOLLOW THE SALARY ADVANCE FLOW (owner, 10 Sep): requested → sanctioned →
 * Manager / Director / HR Admin decisions → tenure → EMI derived → EMI start →
 * disbursed → recovered on the payslip. The loans table gains the advance's
 * column names; the legacy emi / tenure / outstanding / start_month columns are
 * kept in step by the save so the recovery engine reads either.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('loans')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table): void {
            foreach ([
                'amount_requested' => 'decimal', 'per_month' => 'decimal', 'balance' => 'decimal', 'tenure_months' => 'int', 'emi_start' => 'string',
                'requested_on' => 'date', 'disbursed_on' => 'date',
                'manager_id' => 'bigint', 'manager_status' => 'string', 'manager_at' => 'datetime',
                'director_id' => 'bigint', 'director_status' => 'string', 'director_at' => 'datetime',
                'hr_id' => 'bigint', 'hr_status' => 'string', 'hr_at' => 'datetime',
            ] as $column => $kind) {
                if (Schema::hasColumn('loans', $column)) {
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
