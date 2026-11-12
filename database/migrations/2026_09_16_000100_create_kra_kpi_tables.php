<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KRAs AND KPIs — Organization Setup → KRAs and KPIs, and Performance & Rewards
 * → KPI Achievements.
 *
 * THE CATALOGUE (which KRA, which KPI, its description and formula) is
 * config/kra-kpi.json — pure data, no business literal in code. These two
 * tables hold the two things that ARE tenant data: what TARGET a KPI carries
 * for whom, and what an employee actually ACHIEVED against it.
 *
 * `kra_kpi_targets` FOLLOWS THE SAME SCOPE-AND-SPECIFICITY LADDER as
 * `salary_component_rules` (see 2026_03_01_000800_create_salary_component_rules.php)
 * and Pay Cycle's own rules: company, branch, role — narrower than that ladder
 * on purpose, because a KPI target is set by Organization Setup for a slice of
 * the organisation, never for one named employee (that is what the ladder's
 * `scope_employee` step is for elsewhere, and this screen was not asked for
 * it). NULL means "any" on every scope column; specificity is the count of
 * columns set, derived and stored, never typed twice. EFFECTIVE-DATED, LIKE
 * EVERY OTHER ARTEFACT THAT DECIDES A NUMBER (law 3): a target is never
 * edited in place — publishing closes whatever was open for that exact scope
 * tuple and opens a new row, so an achievement recorded in September stays
 * explained by September's target even after October's is published.
 *
 * `kra_kpi_achievements` IS AN APPEND-ONLY LEDGER, exactly like
 * `points_ledger` — recording the same employee/KPI/period again adds a row
 * rather than replacing one, so a correction is visible as a correction. Each
 * row FREEZES the target it was measured against (`target_value`) and the
 * percentage worked out from it (`percent_achieved`, plus `working` in
 * words) at the moment it was recorded — law 4, every number explains
 * itself, and a target changed later must never silently rewrite what an
 * earlier month's percentage meant.
 *
 * BOTH TABLES SHIP WITH THIS RELEASE; THE APPLICATION CANNOT CREATE THEM
 * (law 2) — its own database account holds no DDL. Until this migration is
 * run, `surface/KraKpi.php` falls back to the same tenant-scoped JSON file
 * store every other not-yet-migrated feature in this codebase falls back to,
 * and the screen says so on-screen, every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kra_kpi_targets')) {
            Schema::create('kra_kpi_targets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->index();
                $table->string('kpi_code', 128)
                    ->comment('a code from config/kra-kpi.json, never free text');

                // The scope ladder for this screen: company, branch, role. NULL is "any".
                $table->string('scope_company', 128)->nullable();
                $table->string('scope_branch', 128)->nullable();
                $table->string('scope_role', 128)->nullable();
                $table->unsignedTinyInteger('specificity')->default(0)
                    ->comment('how many scope columns are set — what breaks a tie between two targets');

                // DECIMAL, NEVER FLOAT (law 7). A KPI target can be a rupee amount, a
                // count or a percentage — one exact-decimal column serves all three,
                // same as salary_component_rules' flat_amount/percent_value split
                // would, without a column per KPI shape this catalogue never fixes.
                // NULLABLE: a row can exist to mean "this KPI is kept for this scope"
                // before anyone has agreed a number for it — the Organization Setup
                // screen's checkbox and its target box are two separate facts.
                $table->decimal('target_value', 18, 4)->nullable();

                $table->date('effective_from');
                $table->date('effective_to')->nullable()
                    ->comment('NULL is in force. A change closes this row and opens another; nothing is edited in place');
                $table->string('published_by', 255)->nullable();
                $table->string('note', 500)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent();

                $table->index(['tenant_id', 'kpi_code', 'effective_from', 'effective_to'],
                    'kra_kpi_targets_in_force');
            });
        }

        if (!Schema::hasTable('kra_kpi_achievements')) {
            Schema::create('kra_kpi_achievements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->index();
                $table->string('employment_id', 64)->nullable()
                    ->comment('the employees row id — a string because the file-store fallback mints hex ids, never an int-only FK');
                $table->string('employee_code', 32);
                $table->string('employee_name', 255);
                $table->string('kpi_code', 128)
                    ->comment('a code from config/kra-kpi.json, never free text');
                $table->date('period_from');
                $table->date('period_to');

                // DECIMAL, NEVER FLOAT (law 7).
                $table->decimal('achieved_value', 18, 4);
                // FROZEN AT RECORDING TIME — see this file's own class doc-comment.
                $table->decimal('target_value', 18, 4);
                $table->decimal('percent_achieved', 9, 4);
                $table->text('working')
                    ->comment('the exact arithmetic and which scope the target came from, in words — law 4');

                $table->string('recorded_by', 255)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent();

                $table->index(['tenant_id', 'employee_code'], 'kra_kpi_achievements_employee');
                $table->index(['tenant_id', 'kpi_code'], 'kra_kpi_achievements_kpi');
                $table->index(['tenant_id', 'period_from', 'period_to'], 'kra_kpi_achievements_period');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped. An achievement row is how a KPI review last
        // quarter was justified, and a rollback of a schema change is not a
        // reason to make a closed period unreproducible.
    }
};
