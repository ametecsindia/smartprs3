<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE FIELDS SMARTPRS 2's REGISTERS HAD THAT THE PORTED TABLES LACKED — measured
 * mechanically against MASTER_MAP on 9 Sep: branches (manager, address),
 * companies (master flag, grievance officer), devices (communication key),
 * letters (title), overtime (multiplier), salary schedules (pay day), background
 * verification (the six checks, verified-on, revalidation), late policy (scope
 * and the slab cuts), salary components (scope, sequence, category, base). All
 * nullable; nothing existing changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = [
            'branches' => ['manager_id' => 'bigint', 'address' => 'text'],
            'companies' => ['is_master' => 'bool', 'grievance_officer' => 'string', 'grievance_phone' => 'string', 'grievance_email' => 'string'],
            'devices' => ['comm_key' => 'string'],
            'letters' => ['title' => 'string'],
            'overtime' => ['multiplier' => 'decimal'],
            'salary_schedules' => ['pay_day' => 'int'],
            'bgv' => ['chk_identity' => 'short', 'chk_address' => 'short', 'chk_education' => 'short', 'chk_employment' => 'short',
                'chk_criminal' => 'short', 'chk_references' => 'short', 'verified_on' => 'date', 'revalidate_months' => 'int', 'next_due' => 'date'],
            'late_policy' => ['scope' => 'short', 'scope_target' => 'string', 'mode' => 'short', 'shift_start' => 'short', 'shift_end' => 'short',
                'half_day_hours' => 'decimal', 'cut_mode' => 'short', 'cut_n' => 'decimal', 'l1_min' => 'int', 'l1_cut' => 'decimal',
                'l2_min' => 'int', 'l2_cut' => 'decimal', 'l3_min' => 'int', 'l3_cut' => 'decimal', 'break_budget' => 'int', 'break_cut' => 'short'],
            'salary_components' => ['employer_company_id' => 'bigint', 'scope' => 'short', 'scope_target' => 'string', 'seq' => 'int', 'category' => 'short', 'base' => 'short'],
        ];

        foreach ($add as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column => $kind) {
                    if (Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    match ($kind) {
                        'decimal' => $table->decimal($column, 18, 4)->nullable(),
                        'int' => $table->integer($column)->nullable(),
                        'bigint' => $table->unsignedBigInteger($column)->nullable(),
                        'bool' => $table->boolean($column)->nullable(),
                        'date' => $table->date($column)->nullable(),
                        'text' => $table->text($column)->nullable(),
                        'short' => $table->string($column, 32)->nullable(),
                        default => $table->string($column, 191)->nullable(),
                    };
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
