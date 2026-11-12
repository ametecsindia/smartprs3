<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE A CONFIGURED FIELD GOES WHEN `employees` HAS NO COLUMN FOR IT.
 *
 * The employee field register lets a customer capture anything they keep. Most
 * of it has a column; a customer's own field does not, and never will until
 * somebody writes a migration for it. Refusing those was the product telling a
 * customer what they may know about their own staff, so they are kept here
 * instead — narrow rows rather than a JSON blob, because "which employees have
 * no PAN" is a query somebody asks on the first day.
 *
 * A column, where one exists, is still the better home: the register writes
 * there and this table is not used for that field.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_field_values')) {
            return;
        }

        Schema::create('employee_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('employment_code', 64)
                ->comment('the employment this value is about, by the code every other table uses');
            $table->string('field_code', 64)
                ->comment('a field code from config/employee-fields.json');
            $table->text('value')->nullable();
            $table->string('updated_by', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'employment_code', 'field_code'],
                'employee_field_values_one_per_field');
            $table->index(['tenant_id', 'field_code'], 'employee_field_values_by_field');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped. These rows are what people typed about
        // themselves, and rolling back a schema change is not a reason to delete
        // somebody's emergency contact.
    }
};
