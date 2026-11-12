<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMPONENT RULE, AND WHO IT APPLIES TO.
 *
 * `config/salary-structure.json` declares components for the whole tenant. The
 * configuration screen this table backs asks the question that file cannot: WHO
 * does this component apply to — which company, branch, team, designation, or
 * one named employee. That is a real requirement and not a nicety: conveyance
 * differs by branch in most Indian companies, and a structure that cannot say so
 * is one every customer works around with a spreadsheet.
 *
 * THE SCOPE COLUMNS ARE THE PLATFORM'S OWN LADDER, in its order — company,
 * branch, team, designation, employee — so that `Platform\Support\Scope` and
 * `Platform\Config` resolve a rule the same way they resolve every other
 * configurable artefact. Empty means "any": a rule with nothing set applies to
 * everybody, and each value set narrows it. Specificity is what breaks a tie,
 * and it is the count of set columns, so a designation rule beats a branch rule
 * beats a tenant-wide one.
 *
 * EFFECTIVE-DATED, LIKE EVERYTHING ELSE THAT DECIDES MONEY. A rule is never
 * edited in place: a change closes the old row and opens a new one, so a payslip
 * reprinted for March is computed from the rule that was in force in March. That
 * is law 3 and it is the only way a reprint can agree with the original.
 *
 * THE DETAIL IS JSON ON PURPOSE. A formula's terms, a slab's ranges and a status
 * rule's field are three shapes, and giving each its own columns would put
 * `slab_from_3` on a table that mostly does not use it. What is NOT in the JSON
 * is anything a query needs to filter or join on — the scope, the dates, the
 * component and the calculation are all real columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('salary_component_rules')) {
            return;
        }

        Schema::create('salary_component_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('component_code', 64)
                ->comment('a code from config/salary-components.json, never free text');
            $table->string('kind', 16)
                ->comment('earning, deduction or employer_cost — which side of the payslip');
            $table->string('calculation', 32)
                ->comment('percent_of, flat, monthly, manual, balance, slab, employee_status');

            // The scope ladder, in the platform's order. NULL is "any".
            $table->string('scope_company', 128)->nullable();
            $table->string('scope_branch', 128)->nullable();
            $table->string('scope_team', 128)->nullable();
            $table->string('scope_designation', 128)->nullable();
            $table->string('scope_employee', 64)->nullable()
                ->comment('an employment code, for the one-person exception every customer eventually needs');
            $table->unsignedTinyInteger('specificity')->default(0)
                ->comment('how many scope columns are set — what breaks a tie between two rules');

            $table->string('basis_column', 16)->nullable()
                ->comment('CTC or Basic, for a percentage — asked rather than assumed, because 40% of each is a different amount');
            // DECIMAL, NEVER FLOAT (law 7). A percentage multiplies a salary.
            $table->decimal('percent_value', 9, 4)->nullable();
            $table->decimal('flat_amount', 18, 4)->nullable();

            $table->json('detail')->nullable()
                ->comment('formula terms, slab ranges or the status rule — three shapes, none of which a query filters on');

            $table->date('effective_from');
            $table->date('effective_to')->nullable()
                ->comment('NULL is in force. A change closes this row and opens another; nothing is edited in place');
            $table->string('published_by', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->index(['tenant_id', 'effective_from', 'effective_to'],
                'salary_component_rules_in_force');
            $table->index(['tenant_id', 'component_code'], 'salary_component_rules_component');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped. These rows are how a payslip issued last
        // March was computed, and a rollback of a schema change is not a reason
        // to make a closed month unreproducible.
    }
};
