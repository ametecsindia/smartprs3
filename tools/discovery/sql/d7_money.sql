-- =====================================================================
-- D7 — loan and advance balance reconciliation.  READ-ONLY.
--
-- Nothing in SmartPRS 2 ever writes loans.outstanding. Two live failure modes:
--   outstanding = 0  -> the EMI was NEVER recovered
--   outstanding > 0  -> the EMI is recovered EVERY MONTH FOREVER
-- R-XXIX-024: the stored value MUST NOT become an opening balance. The opening
-- balance is a customer-stated figure reconciled against D7.2, variance by variance.
-- =====================================================================

-- D7.1  Loan population and the two failure modes, counted.
SELECT COUNT(*) loans_total,
       SUM(outstanding = 0 OR outstanding IS NULL) AS never_recovered_candidates,
       SUM(outstanding > 0)                        AS forever_recovered_candidates,
       SUM(status = 'closed')                      AS closed,
       SUM(emi IS NULL OR emi = 0)                 AS no_emi,
       SUM(tenure IS NULL OR tenure = 0)           AS no_tenure,
       SUM(amount)                                 AS sanctioned_total
FROM loans;

-- D7.2  Per-loan truth table. loan_recoveries is the ONLY reliable evidence of what
--       was actually recovered — a runtime-only table with NO INDEX ON ANY COLUMN,
--       whose rows for a regenerated run were hard-deleted.
SELECT l.id, l.tenant_id, l.employee_id, e.emp_code, e.name,
       l.amount      AS sanctioned,
       l.emi         AS emi_declared,
       l.tenure      AS tenure_declared,
       l.outstanding AS outstanding_stored,
       l.status,
       COUNT(lr.id)  AS recovery_rows,
       COALESCE(SUM(lr.amount), 0)            AS recovered_total,
       l.amount - COALESCE(SUM(lr.amount), 0) AS derived_outstanding,
       MIN(lr.created_at) AS first_recovery,
       MAX(lr.created_at) AS last_recovery
FROM loans l
  LEFT JOIN employees       e  ON e.id = l.employee_id
  LEFT JOIN loan_recoveries lr ON lr.loan_id = l.id
GROUP BY l.id
ORDER BY ABS(l.amount - COALESCE(SUM(lr.amount), 0)) DESC;

-- D7.3  Over-recovery: each of these is a potential repayment owed TO an employee.
--       R-XXIX-025: the migration must not proceed for these until the Product Owner
--       records a remedy.
SELECT l.id, e.emp_code, e.name, l.amount, SUM(lr.amount) recovered,
       SUM(lr.amount) - l.amount AS over_recovered
FROM loans l JOIN loan_recoveries lr ON lr.loan_id = l.id
  LEFT JOIN employees e ON e.id = l.employee_id
GROUP BY l.id HAVING SUM(lr.amount) > l.amount
ORDER BY over_recovered DESC;

-- D7.4  Recoveries pointing at a run that no longer exists. The instalment counter
--       for these loans is permanently wrong.
SELECT lr.loan_id, COUNT(*) orphan_recoveries, SUM(lr.amount) orphan_amount
FROM loan_recoveries lr LEFT JOIN payroll_runs r ON r.id = lr.run_id
WHERE r.id IS NULL GROUP BY lr.loan_id;

-- D7.5  installments_paid is created at run time, so it is absent on some installs.
SELECT COUNT(*) AS installments_paid_present FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'loans' AND COLUMN_NAME = 'installments_paid';

-- D7.6  Advances: recovered in one month keyed on created_at, no ledger, per_month
--       and balance ignored, shortfalls silently dropped. A non-zero balance is an
--       unresolved liability.
SELECT a.id, a.tenant_id, a.employee_id, e.emp_code, a.amount, a.per_month, a.balance,
       a.status, a.created_at,
       (SELECT COUNT(*) FROM deductions d
         WHERE d.employee_id = a.employee_id AND d.type LIKE '%advance%') AS deduction_rows
FROM advances a LEFT JOIN employees e ON e.id = a.employee_id
ORDER BY a.balance DESC;

-- D7.7  Clawbacks and their run keying.
SELECT id, tenant_id, employee_id, amount, month, recovered_run_id, status
FROM clawbacks ORDER BY tenant_id, month;
