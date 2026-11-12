<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE FIELDS SMARTPRS 2'S SETUP FORMS ASK FOR — MADE REAL.
 *
 * SmartPRS 2's department form has four inputs: name, code, description and an
 * Active/Inactive status. Its `departments` table has two columns: name and
 * head_employee_id. Three of those four inputs go nowhere — typed, submitted,
 * and dropped, which is the exact defect this rebuild exists to remove: a field
 * that appears to save and does not is discovered months later by whoever needed
 * it.
 *
 * So the fields are ported, and the columns to hold them come with them. A code
 * and a description on a department are worth having (a payslip, a letter and an
 * export all want the short form), and Active/Inactive is how a customer retires
 * a branch without deleting the history of everybody who worked in it.
 *
 * Everything is nullable and nothing is defaulted except `is_active`, which
 * starts at 1 — every row that already exists IS active, and saying so is more
 * honest than leaving it null and making every screen guess.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => column => kind */
    private const COLUMNS = [
        'departments' => [
            'code' => 'short',
            'description' => 'string',
            'is_active' => 'flag',
        ],
        'designations' => [
            'code' => 'short',
            'description' => 'string',
            'is_active' => 'flag',
        ],
        'branches' => [
            'code' => 'short',
            'state' => 'short',
            'phone' => 'short',
            'email' => 'string',
            'is_active' => 'flag',
        ],
        'companies' => [
            'code' => 'short',
            'legal_name' => 'string',
            'state' => 'short',
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column => $kind) {
                    if (Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    match ($kind) {
                        'flag' => $blueprint->boolean($column)->default(true),
                        'short' => $blueprint->string($column, 64)->nullable(),
                        default => $blueprint->string($column, 255)->nullable(),
                    };
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a customer's branch codes and descriptions
        // are theirs, and a rollback of a schema change is not a reason to lose
        // them.
    }
};
