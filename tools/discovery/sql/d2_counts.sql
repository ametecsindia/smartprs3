-- =====================================================================
-- D2 — exact row counts and date ranges.  READ-ONLY.
--
-- COST WARNING: COUNT(*) on a large attendance_logs is the expensive part of
-- the whole pack. On InnoDB it is a full index scan. Run these against a
-- REPLICA or a restored backup if the table exceeds a few million rows.
-- R-XXIX-012 forbids citing the information_schema estimate in reconciliation.
-- =====================================================================

-- D2.1  Generate the exact-count statements for every table:
SELECT CONCAT('SELECT ''', TABLE_NAME, ''' AS t, COUNT(*) AS n FROM `', TABLE_NAME, '` UNION ALL')
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME;
-- (strip the trailing UNION ALL and run the result)

-- D2.2  Reconciliation baselines. These are the numbers the customer signs.
SELECT
  (SELECT COUNT(*) FROM tenants)                                        AS tenants,
  (SELECT COUNT(*) FROM tenants  WHERE deleted_at IS NULL)              AS tenants_live,
  (SELECT COUNT(*) FROM companies)                                      AS companies,
  (SELECT COUNT(*) FROM employees)                                      AS employees_total,
  (SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL)             AS employees_live,
  (SELECT COUNT(*) FROM employees WHERE archived_at IS NOT NULL)        AS employees_archived,
  (SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL AND archived_at IS NULL) AS employees_live_unarchived,
  (SELECT COUNT(*) FROM users)                                          AS users,
  (SELECT COUNT(*) FROM payroll_runs)                                   AS payroll_runs,
  (SELECT COUNT(*) FROM payslips)                                       AS payslips,
  (SELECT COUNT(*) FROM leaves)                                         AS leaves,
  (SELECT COUNT(*) FROM documents)                                      AS documents,
  (SELECT COUNT(*) FROM activity_logs)                                  AS activity_logs;

-- attendance_logs separately: this is the one that costs.
SELECT COUNT(*) AS attendance_logs FROM attendance_logs;

-- D2.3  Per-tenant counts. NULL and 0 are BOTH live platform sentinels, and a
--       NULL-tenant row is invisible to every ->when($tid,…) filter and survives
--       every per-tenant purge. They must be reported apart.
SELECT tenant_id, COUNT(*) FROM employees      GROUP BY tenant_id ORDER BY tenant_id;
SELECT tenant_id, COUNT(*) FROM payslips       GROUP BY tenant_id ORDER BY tenant_id;
SELECT tenant_id, COUNT(*) FROM attendance_logs GROUP BY tenant_id ORDER BY tenant_id;
SELECT tenant_id, COUNT(*) FROM settings       GROUP BY tenant_id ORDER BY tenant_id;
SELECT tenant_id, COUNT(*) FROM documents      GROUP BY tenant_id ORDER BY tenant_id;

-- D2.4  Date ranges: sizes the retention cut-off and proves which months exist.
SELECT MIN(log_date) lo, MAX(log_date) hi, COUNT(DISTINCT log_date) days FROM attendance_logs;
SELECT MIN(month) lo, MAX(month) hi, COUNT(DISTINCT month) months FROM payslips;
SELECT MIN(from_date) lo, MAX(to_date) hi FROM leaves;
SELECT MIN(created_at) lo, MAX(created_at) hi FROM activity_logs;

-- D2.5  Generate range queries for every date-typed column:
SELECT CONCAT('SELECT ''', TABLE_NAME, '.', COLUMN_NAME, ''' c, MIN(`', COLUMN_NAME,
              '`) lo, MAX(`', COLUMN_NAME, '`) hi FROM `', TABLE_NAME, '`;')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :db AND DATA_TYPE IN ('date','datetime','timestamp')
ORDER BY TABLE_NAME, COLUMN_NAME;
