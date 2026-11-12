# PERFORMANCE & REWARDS and LEARNING & KNOWLEDGE — port notes

Section keys `performance` and `learning`. All files are named `perf-learn`.

## What SmartPRS 2 had

PERFORMANCE: `performance` (register: employee, cycle, 1-5 rating, reviewer, status),
`points-rules`, `points-ledger` (registers), `points-scores` (computed leaderboard +
"Auto-apply rules" for a month), `awards`, `tests` (list + question bank + take-test with
server-side scoring), `test-results`, `test-reports` (attempts / pass rate per test),
`attrition` (a list of who is on notice / exited, with an active count), `increments`.

LEARNING: `training-programs`, `training-records`, `training-content`, `kb`, `faqs`,
`code-of-conduct` (text + "I acknowledge" + acknowledgement register), `policies`,
`helpdesk` (tickets; employees see their own; HR sets priority / status / reply). `posh`
is statutory's.

## What is built here

Registers (`config/modules.d/perf-learn.json`, superseding the shipped declarations where
PRS2 has richer fields): `point-rules`, `points`, `awards` (new table), `tests`,
`test-questions` (new register over the ported `test_questions`), `test-attempts` (Test
Results), `training-programs`, `training-records`, `training-content` (over the ported
`training_subjects`), `kb`, `faqs`, `policies` (new table). Employee and programme /
test references are picked, never typed.

Hand-built screens (`surface/screens/`) with their actions (`surface/actions/`):

* `performance` — one review per employee per period. Self rating (employee or HR),
  manager rating (the reporting manager on the employee record, or HR), finalise (HR:
  default from config, override visible). PRG band = recommended increment % from
  `config/performance.json`. A finalised review is closed; correction = new period.
* `points-scores` — Leaderboard: net points per employee for a period (month / year /
  ALL), ties share a rank, org filter bar. Auto-apply for a month writes the ledger via
  `Surface\Points`: counts come from the muster (late days, ABSENT days, perfect month,
  on-time streak), the awards register and passed test attempts — which source feeds
  which event is `config/points.json`; the amounts are only ever the rule rows. Rows say
  `Auto: Late arrival × 3 @ -2 = -6` and carry `source_ref = auto:YYYY-MM`; a month is
  refused for anybody in view who already has one. A company's rule beats the ALL rule.
* `tests` — list with the employee's best result; take a test (radio A-D per question);
  `test-submit` scores server-side in whole marks (`Surface\Assessments`): percent =
  intdiv(scored × 100, total); pass = scored/total ≥ pass mark compared as a Rational, so
  2/3 passes 66.66 and fails 66.67. Answers are stored on the attempt.
* `test-reports` — pass rate per test and per department, exact fraction + working.
* `attrition` — joiners / leavers per month from DOJ and `exit_date` (else the timeline's
  `exited` event); headcount on a day = joined ≤ day and not exited ≤ day, so closing =
  opening + joiners − leavers by construction; rate = leavers ÷ ((opening + closing) ÷ 2)
  as a Rational with the working; "who" per month; leavers without a date are listed as
  not counted. Reads `$store->all('employees')` (not `liveRows`) because leavers are
  inactive rows — `deleted_at` rows are excluded. Permission `employee.list`.
* `code-of-conduct` — the current version's text from `config/code-of-conduct.json`
  (newest `effective_from` not in the future), a checkbox + button for the employee,
  the acknowledgement register for HR (per version, who, when). Once per version.
* `helpdesk` — employees raise and see their own; HR works the queue (priority,
  status, assignee, reply), one-click Resolve per row; `resolved_at` stamped from the
  configured closed statuses (`config/helpdesk.json`).

Migration `2026_03_02_000400_perf_learn_tables.php` (applied): `awards`, `policies`;
columns on `helpdesk` (description, hr_comment, resolved_at), `test_attempts` (answers,
total_marks, marks_scored, submitted_at), `code_of_conduct_ack` (version,
acknowledged_by, acknowledged_at), `performance_reviews` (reviewer_id, self / manager /
final rating, comments, submitted_at, finalised_at, finalised_by), `training_programs`
(description).

## Deliberately not ported / different

* PRS2's attrition screen was a list of notice/exited employees; the brief asked for the
  analytics, so the list is the per-month "who" drill-down instead.
* PRS2's `performance` was a single typed rating; here the reviewer is never typed and a
  legacy text `reporting_manager` name is shown but grants no manager-step rights.
* KB is a register, not PRS2's card reader view — the register lists title / category /
  body and searches all three; a reader layout is a customisation of the same module.
* No "Target achieved" auto-count: PRS2 had no source for it either; it is a manual ledger
  entry (declared as `source: manual` in config so the rule still exists).
* Points rules are per company or ALL; PRS2's `company_name` text became
  `employer_company_id` (Organization Setup).
* Test targeting (`target_type` / `target`) is recorded and shown but not enforced —
  PRS2 did not enforce it either; everybody with questions can take a test.

## TODO

* Increments: the PRG band is shown on the review; the compensation agent's increments
  module reads it as a suggestion if it chooses to — nothing here writes to it.
* An employee account must be linked to an employee (`users.employee_id`) to take a test,
  acknowledge the code or raise a ticket; the screen says so rather than failing.
