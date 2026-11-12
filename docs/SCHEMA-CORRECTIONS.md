# Schema corrections — SmartPRS 2 → SmartPRS 3

Captured by **executing** SmartPRS 2's migrations against a recording stub, not by parsing them:
their filenames do not match their contents (`create_departments_table.php` creates `attendance`,
`devices`, `holidays`, `leave_types` and `leaves`).

SmartPRS 2 was **read only**. Files were copied out; nothing in it was written, edited or deleted.

**88 business tables · 246 corrections, every one listed · 5,495 assertions passing.**

| Correction | Count |
|---|---|
| nullable → NOT NULL | 80 |
| renamed to employer_company_id | 44 |
| money → DECIMAL(18,4), matching the existing convention | 43 |
| free string → vocabulary-checked status | 39 |
| enum → vocabulary-checked string | 33 |
| ADDED, not null | 5 |
| DATETIME → DATE | 2 |

## 1. Tenancy

SmartPRS 2 declares `tenant_id`/`company_id` nullable in **43 places**, 5 business tables have no
tenant column at all, and there are **5 foreign keys in the entire 96-table schema** — referential
integrity lives only in application code. A NULL `tenant_id` matches no tenant's filter, so in
practice the row is visible to all of them.

Applied: `tenant_id` NOT NULL with a real foreign key and **RESTRICT** on delete across 85 tables.
Restrict, never cascade — deleting a tenant must not silently take its payroll history with it.
The original nullable column is dropped, not left beside its own fix.

**`company_id` renamed to `employer_company_id`** (44 tables). SmartPRS 2 carries both `tenant_id`
and `company_id` and uses them inconsistently, so neither is reliably the tenancy filter. The
rename makes it impossible to mistake one for the other.

Platform-scoped (no tenant owns a row): `tenants`, `plans`, `payment_gateways`.

## 2. Money — 43 columns

**I got this wrong first.** I converted every money column to integer paise from first principles,
then the schema test failed: SmartPRS 3's own foundation and its `Money` value object already agree
on `DECIMAL(18,4)`, read as a string and never as a float. Two money representations in one schema
is worse than either, so the established convention won.

## 3. Status vocabulary — 72 columns

33 were **enums**, carrying allowed values in the DDL — adding a state means an `ALTER TABLE` on a
live table, a schema change to record a business decision, which the second law forbids. The other
39 were free strings, spelled differently in different screens so no report could group by them.

## 4. What the spine caught in my port

These are checks SmartPRS 3 already had, doing exactly the job they exist for:

| Caught | What I had done |
|---|---|
| S16 foreign keys | `constrained()` with no ON DELETE behaviour |
| S16 timestamps | `TIMESTAMP` for `created_at` — 2038 limit, silent timezone conversion |
| `no-runtime-ddl` gate | `Schema::create` in `database/ported/`, outside `database/migrations/` |
| `tenant-column` gate | a variable table name the gate could not verify statically |
| Schema test | money as bigint paise, against the existing DECIMAL(18,4) convention |
| Isolation generator | 3 ported tables with no writable column — widened the accepted types rather than lowering the expected count |

## 5. Still open — stated, not skipped

- **Spatie permission tables** not ported; SmartPRS 3 has its own catalogue and access decider.
- **Data migration.** This ports the shape. Live rows need a decision first: rows whose `tenant_id`
  is NULL cannot become NOT NULL until somebody says which tenant owns them.
- **Nullability.** Every non-key column is nullable — SmartPRS 2's constraints were not recoverable
  from the stub, so tightening is a per-table review.
- **232 indexes and 28 unique constraints** not yet ported; they need review against the new
  tenant-first key order rather than copying.
- **`Session::PASSWORD_FLOOR` is temporarily 8**, at the owner's instruction. Back to 12 before this
  instance is used by anyone else. The application shows a standing warning while it is lowered.
---

## 6. The 39 tables SmartPRS 2 creates at RUNTIME

**This section exists because my first port was wrong.** It covered 88 tables. SmartPRS 2 has 127.

The other 39 are not in any migration. They are created by `Schema::create(...)` **inside**
controllers and services — 60 such calls across 40 files, including `RecruitmentController`,
`SelfOnboardingController` and `LicenseService`. A migration-only reading of SmartPRS 2 cannot see
them, and mine did not.

That is the second law broken at its root: **a page load can create a table**. The schema of a live
database depends on which screens have been visited, so two installations used differently have
different schemas and no record of why. It is also why `recruitment`, `job_openings` and
`interviews` were missing — the entire recruitment module, 32 routes, backed by tables nothing
declares.

Recovered and ported with the same corrections, plus two the spine caught:

| Correction | Count |
|---|---|
| nullable → NOT NULL | 22 |
| money → DECIMAL(18,4), one precision for the schema | 14 |
| renamed to employer_company_id | 5 |
| unique index now leads with tenant_id | 1 |
| ADDED, not null | 1 |

**Unique indexes that did not lead with `tenant_id`.** `unique(emp_code, log_date)` means two
tenants cannot both have an employee `E001` on the same day. One customer's data silently blocking
another's is the worst way for a tenancy defect to surface, because it looks like a validation
error and gets 'fixed' by renaming the employee.

**Three money precisions for one kind of number.** These runtime tables use `decimal(12,2)` and
`decimal(14,2)`; the migrations use `decimal(18,4)`. The same rupee rounds differently depending on
which table it passes through.

**Now: 169 tables applying cleanly, 147 of them proving tenant isolation on the write path.**
