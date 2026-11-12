<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMPONENT RULE MAY BE SCOPED BY ROLE, NOT JUST DESIGNATION.
 *
 * "Role" here is the same functional-role tag `designations.role` already
 * carries (Individual Contributor, People Manager, Field, Support) and the
 * same scope dimension `PayCycles::SCOPES` already resolves for Pay Cycles
 * and Payslip Format — the Salary Structure screen just never had its own
 * column for it (owner request, 18 Sep 2026: "add Role filter as well in
 * the list after Branch column"). Nullable, ladder position unchanged
 * (company/branch/team/designation/employee): a rule published before this
 * column existed has scope_role blank, which `resolveFor()` already treats
 * as "any", so nothing in force today changes who it covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('salary_component_rules') && !Schema::hasColumn('salary_component_rules', 'scope_role')) {
            Schema::table('salary_component_rules', function (Blueprint $table): void {
                $table->string('scope_role', 128)->nullable()->after('scope_branch');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a rule published against this scope is not undone by a rollback.
    }
};
