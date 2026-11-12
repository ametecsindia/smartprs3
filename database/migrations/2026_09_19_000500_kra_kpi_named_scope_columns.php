<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KRA/KPI TARGETS GET THE FULL SCOPE LADDER, AND A NAME.
 *
 * `kra_kpi_targets` shipped scoped to company/branch/role only
 * (2026_09_16_000100_create_kra_kpi_tables.php), reasoning at the time that a
 * KPI target is set for "a slice of the organisation, never one named
 * employee". The owner asked for exactly that on 19 Sep 2026 — the same
 * six-part ladder Payslip Format already resolves by (company, branch, team,
 * role, designation, employee) — plus an optional `scope_name` label so a
 * saved scope can be picked by name instead of reconstructed from six
 * dropdowns each time, the same convenience Payslip Format's own named,
 * listed formats already give.
 *
 * All four columns are nullable and NULL means "any", same convention as the
 * three that already exist: a target published before this migration ran has
 * all four blank, which `KraKpi::winningRows()` already treats as "matches
 * everybody" — nothing in force today changes who it covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kra_kpi_targets')) {
            return; // the base migration has not run yet either; nothing to add columns to
        }

        Schema::table('kra_kpi_targets', function (Blueprint $table): void {
            if (!Schema::hasColumn('kra_kpi_targets', 'scope_team')) {
                $table->string('scope_team', 128)->nullable()->after('scope_branch');
            }

            if (!Schema::hasColumn('kra_kpi_targets', 'scope_designation')) {
                $table->string('scope_designation', 128)->nullable()->after('scope_role');
            }

            if (!Schema::hasColumn('kra_kpi_targets', 'scope_employee')) {
                $table->string('scope_employee', 64)->nullable()->after('scope_designation');
            }

            if (!Schema::hasColumn('kra_kpi_targets', 'scope_name')) {
                $table->string('scope_name', 160)->nullable()->after('scope_employee')
                    ->comment('an optional label for this scope tuple, e.g. "Hyderabad Collections Team" — display only, never part of matching');
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: a target published against one of these
        // scopes is not undone by a rollback (same rule every migration in
        // this file follows for kra_kpi_targets).
    }
};
