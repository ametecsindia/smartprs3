-- =====================================================================
-- D3 — enum violation scans.  READ-ONLY.
-- Run D3.0 first for the declared sets, then D3.1 for the observed values.
-- A GROUP BY on an unindexed column is a full scan: check the table size first.
-- =====================================================================

-- D3.0  Declared enum sets ON THIS INSTALL. They differ between installs.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :db AND DATA_TYPE = 'enum'
ORDER BY TABLE_NAME, COLUMN_NAME;

-- D3.0b Generate an observed-value query for EVERY enum column, so nothing is
--       missed on an install that has extra ones:
SELECT CONCAT('SELECT ''', TABLE_NAME, '.', COLUMN_NAME, ''' col, `', COLUMN_NAME,
              '` v, COUNT(*) c FROM `', TABLE_NAME, '` GROUP BY `', COLUMN_NAME, '` UNION ALL')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :db AND DATA_TYPE = 'enum'
ORDER BY TABLE_NAME, COLUMN_NAME;

-- D3.1  The twelve known live cases (J-… §6.6.2 E1–E12).
SELECT 'deductions.type'            AS col, type       AS v, COUNT(*) c FROM deductions        GROUP BY type
UNION ALL SELECT 'deductions.status',       status,       COUNT(*) FROM deductions        GROUP BY status
UNION ALL SELECT 'point_rules.category',    category,     COUNT(*) FROM point_rules       GROUP BY category
UNION ALL SELECT 'points_ledger.category',  category,     COUNT(*) FROM points_ledger     GROUP BY category
UNION ALL SELECT 'salary_components.ctype', ctype,        COUNT(*) FROM salary_components GROUP BY ctype
UNION ALL SELECT 'devices.status',          status,       COUNT(*) FROM devices           GROUP BY status
UNION ALL SELECT 'devices.direction',       direction,    COUNT(*) FROM devices           GROUP BY direction
UNION ALL SELECT 'employees.geo_outside',   geo_outside,  COUNT(*) FROM employees         GROUP BY geo_outside
UNION ALL SELECT 'employees.pcc_status',    pcc_status,   COUNT(*) FROM employees         GROUP BY pcc_status
UNION ALL SELECT 'employees.dra_status',    dra_status,   COUNT(*) FROM employees         GROUP BY dra_status
UNION ALL SELECT 'commissions.status',      status,       COUNT(*) FROM commissions       GROUP BY status
UNION ALL SELECT 'payroll_runs.status',     status,       COUNT(*) FROM payroll_runs      GROUP BY status
ORDER BY col, c DESC;

-- D3.2  E8: the Employee form's "+1 km"/"+2 km" geofence options cannot be saved
--       and become NULL silently. A high NULL count means the grace band was never recorded.
SELECT COUNT(*) total, SUM(geo_outside IS NULL) nulls, SUM(geo_outside = '') blanks
FROM employees;

-- D3.3  E9: the form still offers PCC 'Overdue', which the 27 Aug migration retired.
SELECT pcc_status, COUNT(*) c,
       SUM(pcc_deadline IS NOT NULL) with_deadline,
       SUM(pcc_deadline IS NOT NULL AND pcc_deadline < CURDATE()) deadline_passed
FROM employees GROUP BY pcc_status;

-- D3.4  Case normalisation: case-inconsistent vocabularies that case-sensitive
--       filters miss today.
SELECT dra_status, COUNT(*) c, (BINARY dra_status = LOWER(dra_status)) is_lower
  FROM employees GROUP BY dra_status;
SELECT status, COUNT(*) c, (BINARY status = LOWER(status)) is_lower
  FROM training_programs GROUP BY status;
