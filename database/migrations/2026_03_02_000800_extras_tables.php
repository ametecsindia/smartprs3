<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE LAST SmartPRS 2 SUB-MENUS: Roster and the Assets register.
 *
 * `rosters` — a Working Shift (`shifts` row) assigned to one employee for a
 * date range. SmartPRS 2 kept one row per employee per day; a range is the same
 * fact stated once. The most recent row covering a date wins, so a one-day
 * override is a one-day row written later. Never updated: a correction is a
 * newer row, and a cancelled one is status `inactive`.
 *
 * `assets` — ported with SmartPRS 2's six columns; the register here carries
 * the cost (DECIMAL 18,4, read as a string), make/model, serial, purchase date,
 * condition, notes and the who/when stamps of every issue and return. The
 * ported columns keep their meaning: `asset_id` is the asset code, `asset_type`
 * the category, `issued_to_employee_id` / `issue_date` / `return_date` the
 * holder and the dates. All nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rosters')) {
            Schema::create('rosters', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->unsignedBigInteger('shift_id')->nullable();
                $t->date('from_date')->nullable();
                $t->date('to_date')->nullable();
                $t->string('note')->nullable();
                $t->string('created_by')->nullable();
                $t->string('status', 64)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (Schema::hasTable('assets')) {
            Schema::table('assets', function (Blueprint $t): void {
                foreach ([
                    'make_model' => 'string',
                    'serial_no' => 'string',
                    'purchase_date' => 'date',
                    'condition' => 'string',
                    'notes' => 'text',
                    'issued_by' => 'string',
                    'issued_at' => 'dateTime',
                    'returned_by' => 'string',
                    'returned_at' => 'dateTime',
                    'deleted_at' => 'dateTime',
                ] as $column => $type) {
                    if (!Schema::hasColumn('assets', $column)) {
                        $t->{$type}($column)->nullable();
                    }
                }

                if (!Schema::hasColumn('assets', 'cost')) {
                    $t->decimal('cost', 18, 4)->nullable(); // money: DECIMAL(18,4), read as a string
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
