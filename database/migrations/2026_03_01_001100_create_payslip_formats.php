<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT A PAYSLIP LOOKS LIKE, AND WHOSE PAYSLIP IT IS.
 *
 * The scope columns are the platform's own ladder in its order — company,
 * branch, team, designation, employee — the same one `salary_component_rules`
 * uses, because two configuration screens resolving "who does this apply to" by
 * two different ladders is how a customer ends up unable to explain why a
 * payslip looks the way it does. Empty means any; specificity is the count of
 * columns actually set, and it is what breaks a tie.
 *
 * `lines` is JSON on purpose: which components are shown, in what order, and
 * what each is called on the printed slip. None of that is queried on. What IS
 * queried on — the scope and the name — are real columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payslip_formats')) {
            return;
        }

        Schema::create('payslip_formats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name', 160);

            $table->string('scope_company', 128)->nullable();
            $table->string('scope_branch', 128)->nullable();
            $table->string('scope_team', 128)->nullable();
            $table->string('scope_designation', 128)->nullable();
            $table->string('scope_employee', 64)->nullable();
            $table->unsignedTinyInteger('specificity')->default(0);

            $table->boolean('show_employer')->default(false)
                ->comment('whether the employer-cost block is printed — it never reduces net pay');
            $table->json('lines')->nullable()
                ->comment('component code => order, label, sample. Presentation only');

            $table->dateTime('retired_at')->nullable()
                ->comment('a retired format stops matching anybody; it is not deleted, because a '
                    . 'payslip was printed in it');
            $table->string('updated_by', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->index(['tenant_id', 'specificity'], 'payslip_formats_specificity');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: a format is how somebody's payslip was laid
        // out, and a rollback is not a reason to lose it.
    }
};
