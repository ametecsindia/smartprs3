<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOMEWHERE TO PUT THE FIELDS THE REGISTER ALREADY DECLARES.
 *
 * `config/employee-fields.json` lists what HR keeps on an employee. About thirty
 * of those had no column on `employees` — a mother's name, a grade, a cost
 * centre, the whole education section — so the register showed them greyed with
 * "needs a column" and refused to capture them. That refusal is right (the
 * application must never add a column at runtime, which is law 2) but the answer
 * to it is this file: the columns arrive with a migration, in daylight, and the
 * register lights them up on its own because it asks the LIVE table what exists
 * rather than trusting a list.
 *
 * NOTHING HERE IS REQUIRED AND NOTHING HERE HAS A DEFAULT. Every column is
 * nullable: a field this customer does not capture must not force a value onto
 * every existing employee, and a default would be an invented fact about people
 * who were hired before anybody asked the question.
 *
 * The names match the register's field codes exactly, because the two lists
 * disagreeing is the failure this migration exists to end.
 */
return new class extends Migration
{
    /** @var array<string, string> column => kind */
    private const COLUMNS = [
        'first_name' => 'string',
        'middle_name' => 'string',
        'last_name' => 'string',
        'mother_name' => 'string',
        'spouse_name' => 'string',
        'physically_challenged' => 'short',
        'personal_email' => 'string',
        'alternate_number' => 'short',
        'emergency_relation' => 'short',
        'grade_band' => 'short',
        'work_location' => 'string',
        'business_unit' => 'string',
        'cost_center' => 'short',
        'probation_end' => 'date',
        'confirmation_date' => 'date',
        'notice_period_days' => 'int',
        'exit_date' => 'date',
        'exit_reason' => 'string',
        'rehire_eligible' => 'short',
        'passport_no' => 'short',
        'passport_expiry' => 'date',
        'pf_no' => 'short',
        'bgv_status' => 'short',
        'medical_fitness' => 'short',
        'payment_mode' => 'short',
        'insurance_applicable' => 'short',
        'gratuity_applicable' => 'short',
        'highest_qualification' => 'short',
        'university' => 'string',
        'year_of_passing' => 'int',
        'specialization' => 'string',
        'certifications' => 'string',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column => $kind) {
                // COLUMN BY COLUMN, GUARDED. A migration that assumes the shape
                // of the table it is altering is one that fails halfway on the
                // installation where somebody added one of these by hand — and
                // half an ALTER is a table nobody can describe.
                if (Schema::hasColumn('employees', $column)) {
                    continue;
                }

                match ($kind) {
                    'date' => $table->date($column)->nullable(),
                    'int' => $table->unsignedInteger($column)->nullable(),
                    'short' => $table->string($column, 64)->nullable(),
                    default => $table->string($column, 255)->nullable(),
                };
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped. These columns hold what people typed about
        // themselves, and rolling back a schema change is not a reason to delete
        // somebody's emergency contact.
    }
};
