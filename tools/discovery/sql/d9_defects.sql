-- =====================================================================
-- D9 — evidence for each confirmed live defect.  READ-ONLY.
-- Each block answers one contamination question with a count and a sample, so
-- blast radius is MEASURED rather than assumed. Feeds the Contamination Register.
-- =====================================================================

-- ============ DEF-P01 · fabricated 'paid' payroll ====================
-- ensurePayrollHistory() inserts 12 months of runs and payslips on every /app/data
-- load, status 'paid' for 11 of them, computed from full CTC with no LOP, no
-- attendance, no components and no recoveries.
SELECT r.id, r.tenant_id, r.company_id, r.month, r.cycle_label, r.status,
       r.employees_count, r.net_total, r.period_start, r.period_end, r.created_at,
       (SELECT COUNT(*) FROM payslips p WHERE p.run_id = r.id) AS slip_count,
       (SELECT COUNT(*) FROM employees e
         WHERE e.tenant_id = r.tenant_id AND e.company_id = r.company_id
           AND e.deleted_at IS NULL)                     AS live_headcount,
       (r.period_start IS NULL)                          AS h_no_period,
       (r.calc_note IS NULL OR r.calc_note = '')         AS h_no_calc_note,
       (SELECT COUNT(*) FROM payslips p
         WHERE p.run_id = r.id AND (p.earnings IS NULL OR JSON_LENGTH(p.earnings) <= 1))
                                                         AS h_thin_slips
FROM payroll_runs r
ORDER BY r.tenant_id, r.company_id, r.month;

-- DEF-P01b  The fabrication signature: net EXACTLY equal to ctc/12, no deductions.
SELECT p.id, p.employee_id, p.month, p.gross, p.net, e.ctc,
       ROUND(e.ctc / 12, 2) AS ctc_over_12,
       ABS(p.net - ROUND(e.ctc / 12, 2)) AS delta,
       JSON_LENGTH(p.earnings)   AS earning_lines,
       JSON_LENGTH(p.deductions) AS deduction_lines
FROM payslips p JOIN employees e ON e.id = p.employee_id
WHERE ABS(p.net - ROUND(e.ctc / 12, 2)) < 1.00
ORDER BY p.month, p.employee_id;

-- DEF-P01c  Runs that are 'paid' and therefore PERMANENTLY block a real run.
SELECT tenant_id, company_id, month, COUNT(*) runs, SUM(status='paid') paid_runs
FROM payroll_runs GROUP BY tenant_id, company_id, month
HAVING SUM(status='paid') > 0 ORDER BY tenant_id, company_id, month;

-- ============ DEF-LVE-02 · LOP leave paid in full ====================
-- See d8_leave.sql D8.2 / D8.3.

-- ============ DEF-P02 · loans ========================================
-- See d7_money.sql.

-- ============ DEF-P03 · commissions stranded by an enum mismatch =====
-- Migration declares ENUM('provisional','confirmed','reversed'); every code path
-- writes 'pending'/'approved'/'rejected'. On strict MySQL every insert throws; on
-- non-strict the status is silently blanked. Either way NO commission is ever
-- 'approved' and none reaches a payslip.
SELECT status, COUNT(*) c, MIN(created_at) lo, MAX(created_at) hi, SUM(amount) total_amount
FROM commissions GROUP BY status ORDER BY c DESC;

SELECT COLUMN_TYPE AS declared_enum FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'status';

SELECT c.id, c.employee_id, c.month, c.amount, c.gross_amount, c.status,
       (SELECT COUNT(*) FROM commission_payments cp WHERE cp.commission_id = c.id) AS payments
FROM commissions c
WHERE c.status NOT IN ('approved') OR c.status IS NULL OR c.status = ''
ORDER BY c.month;

-- DEF-P03c  amount = NET or GROSS? Rows predating rev 83 may hold a gross.
SELECT COUNT(*) rows_where_amount_equals_gross
FROM commissions WHERE gross_amount IS NOT NULL AND amount = gross_amount;

-- ============ Cross-midnight attendance counted as two days ==========
SELECT s.id, s.tenant_id, s.name, s.start_time, s.end_time
FROM shifts s WHERE s.end_time <= s.start_time;

-- The orphan-punch signature of a night shift.  [EXPENSIVE on a large table]
SELECT a.tenant_id, a.emp_code, a.log_date, MIN(a.punch_at) first_punch,
       MAX(a.punch_at) last_punch, COUNT(*) punches,
       SUM(a.direction = 'in')  ins,
       SUM(a.direction = 'out') outs
FROM attendance_logs a
GROUP BY a.tenant_id, a.emp_code, a.log_date
HAVING (ins = 0 AND outs > 0) OR (ins > 0 AND outs = 0)
ORDER BY a.log_date DESC;

-- ============ PCC 'overdue' destroyed by migration 2026_08_27_000100 ==
SELECT pcc_status, COUNT(*) c,
       SUM(pcc_deadline IS NOT NULL) with_deadline,
       SUM(pcc_deadline IS NOT NULL AND pcc_deadline < CURDATE()) deadline_passed
FROM employees GROUP BY pcc_status;

SELECT migration, batch FROM migrations WHERE migration LIKE '%2026_08_27_000100%';

-- ============ Audit chain broken =====================================
-- verify() treats a null row_hash as legacy, SKIPS it and RESETS the chain to
-- GENESIS, destroying tamper-evidence for everything after it.
SELECT COUNT(*) total,
       SUM(row_hash IS NULL OR row_hash = '')   AS unchained_rows,
       SUM(prev_hash IS NULL OR prev_hash = '') AS no_prev,
       MIN(id) lo, MAX(id) hi
FROM activity_logs;

SELECT id, created_at, action, entity_type, entity_id
FROM activity_logs WHERE row_hash IS NULL OR row_hash = '' ORDER BY id;

-- Do the chain columns even exist here? They are added at run time.
SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'activity_logs'
   AND COLUMN_NAME IN ('prev_hash','row_hash');

-- ============ Demo / fabricated attendance ===========================
-- source='demo' is the ONLY discriminator, and it is incomplete: rows fabricated
-- before rev 51 are tagged 'biometric' and are indistinguishable from real punches.
SELECT source, COUNT(*) c, MIN(log_date) lo, MAX(log_date) hi
FROM attendance_logs GROUP BY source ORDER BY c DESC;

-- ============ Demo tenant: lockdown + destructive seeder target ======
SELECT id, name, subdomain, status, created_at FROM tenants
WHERE subdomain = 'demo' OR name LIKE '%demo%';

-- ============ Default / vendor credentials ===========================
SELECT id, name, email, tenant_id, status, created_at, updated_at
FROM users
WHERE email IN ('admin@smartprs.local','support@ametecsindia.com')
   OR email LIKE 'demo-%@smartprs.in';

-- ============ Archived employees consuming seats =====================
SELECT tenant_id, COUNT(*) archived_not_deleted
FROM employees WHERE archived_at IS NOT NULL AND deleted_at IS NULL
GROUP BY tenant_id;
SELECT tenant_id, seats FROM subscriptions ORDER BY tenant_id;

-- ============ NULL-tenant rows: invisible to every filter ============
SELECT 'attendance_logs' t, COUNT(*) c FROM attendance_logs     WHERE tenant_id IS NULL
UNION ALL SELECT 'biometric_configs', COUNT(*) FROM biometric_configs WHERE tenant_id IS NULL
UNION ALL SELECT 'documents',         COUNT(*) FROM documents         WHERE tenant_id IS NULL
UNION ALL SELECT 'kb_topics',         COUNT(*) FROM kb_topics         WHERE tenant_id IS NULL
UNION ALL SELECT 'notices',           COUNT(*) FROM notices           WHERE tenant_id IS NULL
UNION ALL SELECT 'settings',          COUNT(*) FROM settings          WHERE tenant_id IS NULL
UNION ALL SELECT 'settings#0',        COUNT(*) FROM settings          WHERE tenant_id = 0;

-- ============ Corrections approved against a locked period ===========
-- The employee was told the change "will be carried into the next adjustment",
-- and NO CODE CREATES SUCH AN ADJUSTMENT.
SELECT id, tenant_id, employee_id, log_date, status, payroll_locked,
       orig_in, orig_out, req_in, req_out, app_in, app_out, decided_at
FROM attendance_corrections
WHERE payroll_locked = 1 AND status = 'approved'
ORDER BY log_date;

-- ============ Arrears with no decrease path ==========================
SELECT id, tenant_id, employee_id, old_ctc, new_ctc, effective_from, status, created_at
FROM increments WHERE new_ctc < old_ctc ORDER BY effective_from;

-- ============ Invoice / payment over-credit (SaaS) ===================
SELECT i.id, i.number, i.amount, i.tax, SUM(p.amount) paid,
       SUM(p.amount) - (i.amount + i.tax) AS over_credit
FROM invoices i JOIN payments p ON p.invoice_id = i.id
GROUP BY i.id HAVING SUM(p.amount) > i.amount + i.tax;

-- ============ Tenants with no subscription: never gated, never billed =
SELECT t.id, t.name, t.subdomain FROM tenants t
  LEFT JOIN subscriptions s ON s.tenant_id = t.id WHERE s.id IS NULL;
