# SmartPRS 3 enforcement gates

> A law without a mechanical gate is a wish.

Spine S1 declares seven laws. Part IV §IV.10 says each one gets a gate, and that
building them is Phase 1 work, not a later nicety. This directory is that
toolchain.

Every gate here exists because its absence produced a **confirmed defect in
SmartPRS 2**. None of them is a style preference. The defect is named in each
row below and in the docblock at the top of each check.

## Running them

```bash
php tools/gates/gate.php                      # everything, exit 1 on any violation
php tools/gates/gate.php --only=no-empty-catch
php tools/gates/gate.php --only=no-float-money,tenant-column
php tools/gates/gate.php --fix-hint           # add "how to fix" for what failed
php tools/gates/gate.php --format=json        # for CI annotations
php tools/gates/gate.php --root=/path/to/checkout
php tools/gates/gate.php --help

php tools/gates/checks/no-empty-catch.php     # any check, on its own
php tools/gates/self-test.php                 # prove the gates still work
```

Requirements: **PHP 8.3+, ext-tokenizer, ext-pdo_mysql** (the last only for
`db-privilege`). No Composer, no packages, no framework. That is deliberate: the
gates must run on a bare checkout, in a container with no egress, and in under a
second, or they will be skipped when they are most needed.

Exit codes: `0` clean, `1` violations, `2` bad usage.

## Law 5's check is not in this directory

Spine S1's seven laws each get a mechanical check. Six of them are here, because
a tokeniser over `src/` can answer them. Law 5 — authorisation is server-side and
fails closed — cannot be answered that way: it needs the live route list and the
permission catalogue, so its check is
`src/Platform/Access/OperationSweep.php`, exercised by
`tests/operation_registry_test.php`.

It runs the ten assertions of Part XXII R-XXII-074/075/076: every route resolves
to a registered operation; the live unauthenticated surface equals the reviewed
allow-list exactly; every named permission exists; a required data scope has a
subject to resolve; scope declaration agrees with the catalogue in both
directions; a CSRF exemption names its compensating control; a privileged
permission is not reachable with `assurance = none`; a maker-checker operation
binds a workflow; and an operation that writes declares an audit class.

The first of those — route coverage — reports NOT CHECKED until the HTTP layer
exists and can hand it the routes. That is on purpose and it is loud: it is the
assertion Law 5 rests on, and a sweep that reported success without it would be
the same lie as a gate reading no files.


## A gate that reads nothing says so

Four checks select `src/<Module>/Domain/`. No such directory exists yet — Phase 2
built the Platform kernel, whose namespaces are not the module/layer convention
of Part IV §IV.2.1 — so `no-business-literal`, `no-clock-in-domain` and
`no-framework-in-domain` had been **passing over zero files since they were
written**, and `no-float-money` was reading one file out of 116.

A green gate that read nothing is worse than a missing one, because it is
evidence. So:

* the three domain-only checks now emit a visible **suppression** — `SKIPPED: no
  src/<Module>/Domain/ directory exists yet` — printed on every run, the same
  shape `db-privilege` already used for an unconfigured database;
* `no-float-money` scans all of `src/`, because a name-shaped filter cannot know
  that `Rational` is the money arithmetic, and no float has a legitimate use in
  this codebase anyway;
* `self-test.php` asserts the suppression appears, so the guard is itself proved.

The suppressions clear as Part XXXII Phase 3 moves each concern out of
`src/Platform/` into its module's `Domain/`. Until then, read the suppression
block at the end of every gate run: it is the list of checks that are asleep.

## The gates

| Gate | Law | Enforces | The SmartPRS 2 defect it exists for |
|---|---|---|---|
| `no-business-literal` | 1 | No string or numeric literal carrying business meaning in a comparison, assignment or return inside `src/*/Domain/` | Component names, role names, leave-type codes and statutory ceilings are literals in controllers and services. Renaming a component in configuration silently stops matching the code that looks for it; a ceiling change is a code release. |
| `no-runtime-ddl` | 2 | No `Schema::*`, no `CREATE`/`ALTER`/`DROP TABLE`, no DDL in `DB::statement`/`DB::unprepared`, outside `database/migrations/` | ~49 tables and ~60 columns are created by controllers, so the production schema depends on which screens somebody opened. `dra_expiry` and `pcc_deadline` become `VARCHAR` on installs where a runtime helper wins the race. |
| `db-privilege` | 2 | The application database account holds no `ALTER`, `CREATE`, `DROP` or `INDEX` | The application account *is* the schema owner. Nothing at the database stops the code above from succeeding. |
| `no-float-money` | 7 | No `float`/`double` declaration, `(float)` cast, `floatval`, money-rounding or float-literal `==`/`!=` **anywhere in `src/`** | A float comparison decides whether a loan instalment is deducted. When the residual balance lands a fraction of a paisa away, recovery silently stops. |
| `no-empty-catch` | 4, 5 | No `catch` that is empty, comment-only, or `return null` / `return false` / `return;` | **426 of 843** catch blocks destroy the exception. A failed statutory calculation and a successful one that produced nothing are indistinguishable, and an authorisation failure becomes "no result" instead of "denied". |
| `no-clock-in-domain` | 3, 4 | No `now()`, `time()`, `date()`, `new DateTime`, `Carbon::*`, `rand`, `mt_rand`, `random_int`, `uniqid` in `src/*/Domain/` | Payroll and attendance derivations read the wall clock directly. A run cannot be reproduced, a re-run of a closed period gives different numbers, and no result can be replayed to defend an audit query. |
| `no-framework-in-domain` | — | No `Illuminate\`, `Laravel\`, `Http`, `Request`, `Response`, `DB::`, `Eloquent` or `Model` in `src/*/Domain/` | There is no domain layer. Calculation lives in controllers, reads the `Request`, writes with the query builder, returns a `Response`. No payroll rule can be unit-tested, reused by a job, or replayed. |
| `module-boundaries` | — | A module references another module only through `<Module>\Contracts\` | There are no modules. Payroll reads attendance tables, leave reads payroll tables, and any change is a whole-system change with no declared surface to keep stable. |
| `tenant-column` | 6 | Every created table declares a non-nullable `tenant_id` and leads every unique index with it | The declared tenancy scope is inert because the column it filters on does not exist on most tables — and four different names are used for it where it does. "Employee code is unique" quietly means "unique across all customers". |
| `no-pii-in-logs` | — | No logging call referencing a declared PII field name | Identity numbers, bank details and salary figures are written to application logs in plaintext, on installs where the log directory sits inside the web root. |
| `strict-types` | 1–7 | Every file under `src/`, `database/` and `tests/` declares `declare(strict_types=1)` | Without it PHP coerces: `'15000'` satisfies an int parameter and `'0.5'` a float, so a Money constructor meaning paise quietly accepts a string from a form. Every unit distinction the platform rests on is enforced by the engine only in strict mode, and one file without the declaration is an invisible hole in all of it. |
| `min-php-version` | — | Source honours the PHP floor `composer.json` declares | Not a SmartPRS 2 defect — found on the first machine that ran `composer install`. The tree required PHP ^8.4 for exactly one 8.4-only function used twice, while the target ran 8.3. One line of convenience had raised the prerequisite bar for the whole on-premise estate. |

Laws 3 and 5 also need runtime gates (an as-at date on every configuration read;
a policy binding on every route). Those are architecture tests against code that
does not exist yet, and belong with the first controller and the first
effective-dated repository. Part IV §IV.10 lists them; this toolchain covers the
eight static laws plus the database privilege check.

## Suppressing a false positive

Three of the gates have an allow-list. Two do not, on purpose.

| Gate | How to suppress | Where |
|---|---|---|
| `no-business-literal` | Add the value to the allow-list, with a note | `allow/literals.php` |
| `tenant-column` | Add the table name, asserting no tenant owns a row in it | `allow/platform-tables.php` |
| `no-pii-in-logs` | Narrow or extend the field list | `allow/pii-fields.php` |
| `min-php-version` | **None.** Raise the floor in `composer.json` deliberately, or rewrite | — |
| `no-empty-catch` | `// gate:allow-swallow <reason>` inside the catch body | at the site |
| `no-runtime-ddl` | **Nothing.** Zero tolerance. Write a migration. | — |
| `db-privilege` | **Nothing.** Revoke the privilege. | — |

`no-business-literal` will produce false positives. That is a design decision,
not a defect: the alternative is a gate narrow enough to miss the literals that
matter. Adding a line to `allow/literals.php` takes ten seconds, and the entry
carries a note explaining itself. **A gate people cannot live with gets
disabled**, and a disabled gate is worse than no gate, because the build still
looks green.

### Why every suppression is printed

A suppression is not an exemption; it is a decision that somebody made, and it
stays on screen so somebody else can disagree with it:

```
Suppressions (4 — visible on purpose, review them):
      database/migrations/..._create_tenant_tables.php:25  tenant-column  `tenants` allow-listed as platform-scoped
      src/Payroll/Application/RunPayrollService.php:19     no-empty-catch  swallowed exception, allowed: cache warm-up is an optimisation
```

Suppressions do not fail the build, and the summary line always names the count.
The moment that count grows without anyone noticing, the gate has stopped
working — which is exactly what happened to SmartPRS 2's `try { } catch { }`.
`no-empty-catch` also flags a `gate:allow-swallow` annotation on a catch that
does *not* swallow, so the annotations cannot quietly accumulate as decoration.

## Proof that the gates work

`fixtures/<gate>/bad/` and `fixtures/<gate>/good/` are two miniature checkouts
per gate: one deliberately violating, one deliberately clean. `self-test.php`
asserts every gate flags the first and passes the second.

```
$ php tools/gates/self-test.php
PASS  31 assertion(s), 0 failure(s)
```

Run it with `-v` to see exactly which lines each gate catches. The fixtures live
under `tools/`, which `GATE_EXCLUDE` skips, so a real run never sees them — and
the self-test asserts that too.

`db-privilege` has no file fixture because it is a runtime check. To prove it:

```bash
# with a correctly-scoped application account — passes
DB_HOST=127.0.0.1 DB_DATABASE=smartprs3 DB_USERNAME=prs3_app DB_PASSWORD=... \
  php tools/gates/checks/db-privilege.php

# with the schema-owning account SmartPRS 2 uses — fails, naming each privilege
DB_HOST=127.0.0.1 DB_DATABASE=smartprs3 DB_USERNAME=prs3_migrate DB_PASSWORD=... \
  php tools/gates/checks/db-privilege.php
```

With nothing configured it prints `SKIPPED:` and a reason, and does not fail the
build.

## Adding a gate

A check is a function that takes a root path and returns findings. That is the
whole contract. There is no plugin system, no base class and no registry beyond
one array.

1. `checks/<name>.php` — docblock naming the law and the defect; a function
   `gate_<name_with_underscores>(string $root): array`; `gate_cli_main(...)` at
   the bottom so it runs standalone.
2. Add the name to `GATE_CHECKS` in `gate.php` with its law, purpose and fix
   hint.
3. Add `fixtures/<name>/bad/` and `fixtures/<name>/good/`, and a line in
   `GATE_SELFTEST` in `self-test.php`.

Use `lib.php`'s tokeniser helpers rather than a regex whenever the question is
"what does this code mean" rather than "does this text appear". `gate_tokens()`,
`gate_sig()`, `gate_match_paren()` and `gate_text()` are the whole toolkit.

## Relationship to PHPStan and Deptrac

`phpstan.neon`, `deptrac.layers.yaml` and `deptrac.modules.yaml` at the repository root are configured for this
module structure and **have never been run** — `composer install` is not yet
possible. They add the type-level and transitive-graph half of the same rules.
They do not replace anything here: these gates must keep working on a checkout
with no `vendor/`, because that is the state a build is in when it fails.
