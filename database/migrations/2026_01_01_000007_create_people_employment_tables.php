<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part V §V.6 — the effective-dated employment model, and Law 3's first runtime
 * expression: Person → Employment → EmploymentAssignment, nothing overwritten.
 *
 * SmartPRS 2 keeps branch, department, designation, grade and manager as mutable
 * columns on `employees`, so a transfer is `UPDATE employees SET …` and "what
 * was this employee's branch on 15 May?" has no answer at all. Here the
 * placement is never a column on the employment: it is a dated row, and the
 * question is one indexed query (V.6.3).
 *
 * WHY THERE IS NO `reporting_lines` TABLE. The reporting manager is a column on
 * the assignment, not a register of its own, for three reasons:
 *   1. R-V-030 puts the relationship fields on the assignment on purpose — one
 *      dated row carries every dimension, so "the assignment in force on date D"
 *      stays one row and not a twenty-way join.
 *   2. A manager change has exactly the shape of a transfer: close the current
 *      row, open a successor carrying the other dimensions forward, with one
 *      reason code and one provenance. A separate table would need its own
 *      open-row constraint, its own contiguity assertion and its own overlap
 *      story, for a dimension that changes no more often than a department does.
 *   3. A decision trace then names ONE version id for "where they sat and who
 *      they reported to", which is what makes a re-opened May approval route to
 *      May's manager rather than today's (R-XXII-043).
 * A separate table earns its place only if reporting acquires an edge type or a
 * cardinality the assignment cannot carry — a dotted line to two managers with
 * independent dates. Functional and team-leader managers are additional columns
 * when they arrive, not additional tables.
 *
 * HOW "AT MOST ONE OPEN ROW" IS A CONSTRAINT AND NOT A HOPE (V.6.4).
 * `open_marker` is a stored generated column that is 1 while `effective_to` is
 * NULL and NULL once the row is closed. MySQL and MariaDB ignore NULLs in a
 * unique index, so UNIQUE (tenant_id, employment_id, open_marker) permits any
 * number of closed rows and exactly one open one. The mechanism V.6.4 describes
 * works, and it is implemented verbatim.
 *
 * WHAT IT DOES NOT DO, said plainly. It is not a range-exclusion constraint.
 * With UNIQUE (tenant_id, employment_id, effective_from) it forbids two open
 * rows and two rows starting on the same day — but two CLOSED rows can still
 * overlap (2026-01-01→2026-12-31 and 2026-02-01→2026-03-31 collide on neither
 * index). No engine-level constraint closes that gap on the target platform:
 * MySQL 8 has no EXCLUDE and no multi-row CHECK, and MariaDB's
 * `UNIQUE (… , period WITHOUT OVERLAPS)` is both MariaDB-only and requires a
 * NOT NULL end date, which would replace "in force until superseded" with a
 * 9999-12-31 sentinel. So the remaining guarantee is V.6.4's mechanisms 3 and 4
 * — ordered closure inside one transaction with the employment locked FOR
 * UPDATE, and a contiguity assertion after every write plus nightly — and those
 * belong to the write service, which is the next brick. The two indexes below
 * are what makes that service's failure mode a loud duplicate-key error rather
 * than a silent double answer to "which row was in force?".
 *
 * ponytail: the org dimension columns are the seven the S3.1 ladder's scope
 * types actually resolve against (DataScope::TYPES) plus cost centre. The other
 * five — establishment, state, zone, sub_department, business_unit,
 * client_process — are a migration away and go in the day a scope type or a
 * policy dimension needs them. Adding a column is cheap; guessing which of
 * twenty a customer uses is not.
 *
 * ponytail: `people` carries a display name and nothing else. The identity
 * matching of V.6.6 (PAN, Aadhaar, UAN — never name) belongs to the People
 * module with its own encryption and masking rules; do not grow identifier
 * columns here in the meantime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('display_name', 191)->comment('as displayed; identity is matched on declared identifiers, never on this');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'uuid'], 'people_tenant_uuid_unique');
        });

        Schema::create('employments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('legal_entity_org_unit_id')->constrained('org_units')->restrictOnDelete()
                ->comment('V.6.1: an employment is a relationship with ONE legal entity');
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('employee_code', 64);
            $table->date('joined_on');
            $table->date('exited_on')->nullable()->comment('NULL while the employment is live; never a status flag');
            $table->string('exit_reason_code', 64)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'uuid'], 'employments_tenant_uuid_unique');
            // D-V-03, recommended default: employee codes are unique per legal
            // entity, because a group with three companies commonly runs three
            // independent series. Tenant-wide is the stricter variant and is a
            // field-metadata declaration materialised as this index.
            $table->unique(
                ['tenant_id', 'legal_entity_org_unit_id', 'employee_code'],
                'employments_tenant_entity_code_unique',
            );
            $table->index(['tenant_id', 'person_id'], 'employments_tenant_person_index');
        });

        Schema::create('employment_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employment_id')->constrained('employments')->cascadeOnDelete()
                ->comment('CASCADE: an assignment has no meaning without its employment');
            $table->binary('uuid', length: 16, fixed: true);

            $table->date('effective_from');
            $table->date('effective_to')->nullable()->comment('inclusive, as V.5.3 persists it; NULL = in force until superseded');
            $table->unsignedInteger('open_marker')
                ->storedAs('CASE WHEN `effective_to` IS NULL THEN 1 ELSE NULL END')
                ->comment('V.6.4 mechanism 1 — NULL once closed, so the unique index below counts only open rows');

            // Org placement. Every one is a foreign key into org_units, never a
            // name (R-XXII-045). RESTRICT: an org unit that somebody was ever
            // assigned to cannot be deleted out from under history.
            foreach ([
                'legal_entity',
                'company',
                'branch',
                'department',
                'team',
                'location',
                'cost_centre',
            ] as $dimension) {
                $table->foreignId($dimension . '_org_unit_id')->nullable()
                    ->constrained('org_units')->restrictOnDelete();
            }

            // Classifications: orthogonal attributes, not tree nodes (spine S3).
            foreach (['designation', 'grade', 'employee_category', 'employment_type'] as $dimension) {
                $table->foreignId($dimension . '_id')->nullable()
                    ->constrained('classifications')->restrictOnDelete();
            }

            $table->foreignId('reporting_manager_employment_id')->nullable()
                ->constrained('employments')->restrictOnDelete()
                ->comment('R-XXII-043: the reporting line is dated here, never a mutable column on the employment');

            $table->string('source', 32)->comment('hire|transfer|promotion|restructure|correction|import — data, never an ENUM');
            $table->string('reason_code', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('FK added with the identity migration');
            $table->dateTime('created_at')->nullable();

            $table->unique(['tenant_id', 'uuid'], 'employment_assignments_tenant_uuid_unique');
            // V.6.4 mechanism 1: at most one open row per employment.
            $table->unique(
                ['tenant_id', 'employment_id', 'open_marker'],
                'employment_assignments_one_open_unique',
            );
            // V.6.4 mechanism 2: no two rows start on the same day.
            $table->unique(
                ['tenant_id', 'employment_id', 'effective_from'],
                'employment_assignments_start_unique',
            );
            $table->index(
                ['tenant_id', 'employment_id', 'effective_from', 'effective_to'],
                'employment_assignments_as_at_index',
            );
            $table->index(
                ['tenant_id', 'reporting_manager_employment_id', 'effective_from'],
                'employment_assignments_manager_index',
            );

            // ponytail: no per-dimension composite index. InnoDB already indexes
            // every foreign key column, which is what the org-unit scope
            // subquery joins on. Add (tenant_id, <dimension>_org_unit_id,
            // effective_from) when a scope proves slow on real cardinality —
            // not before, and one at a time.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_assignments');
        Schema::dropIfExists('employments');
        Schema::dropIfExists('people');
    }
};
