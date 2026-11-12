-- =====================================================================
-- D4 — orphan scans and the duplicates the missing constraints allow.
-- READ-ONLY. Zero foreign keys exist on business tables, so orphans are a
-- certainty, not a risk.
--
-- COST: O5 is a full join over attendance_logs. Run it against a replica if the
-- table is large. Everything else is small.
-- =====================================================================

-- O1  A payslip that names nobody.
SELECT COUNT(*) FROM payslips p LEFT JOIN employees e ON e.id = p.employee_id WHERE e.id IS NULL;

-- O2  A payslip whose run was hard-deleted on regenerate.
SELECT COUNT(*) FROM payslips p LEFT JOIN payroll_runs r ON r.id = p.run_id WHERE r.id IS NULL;

-- O3  Loan recoveries pointing at a deleted run / loan / employee. The instalment
--     counter for these loans is permanently wrong.
SELECT SUM(r.id IS NULL) missing_run, SUM(l.id IS NULL) missing_loan, SUM(e.id IS NULL) missing_emp
FROM loan_recoveries lr
  LEFT JOIN payroll_runs r ON r.id = lr.run_id
  LEFT JOIN loans        l ON l.id = lr.loan_id
  LEFT JOIN employees    e ON e.id = lr.employee_id;

-- O4  Commission ledger orphans — the class RequestController.php:1246 exists to sweep.
SELECT (SELECT COUNT(*) FROM commissions c LEFT JOIN employees e ON e.id = c.employee_id WHERE e.id IS NULL) AS comm_no_emp,
       (SELECT COUNT(*) FROM commission_payments cp LEFT JOIN commissions c ON c.id = cp.commission_id WHERE c.id IS NULL) AS pay_no_comm,
       (SELECT COUNT(*) FROM commission_logs cl LEFT JOIN commissions c ON c.id = cl.commission_id WHERE c.id IS NULL) AS log_no_comm;

-- O5  Attendance joins employees by emp_code STRING, not id. A punch whose code
--     matches no employee is invisible to payroll and unattributable.  [EXPENSIVE]
SELECT COUNT(*) FROM attendance_logs a
  LEFT JOIN employees e ON e.emp_code = a.emp_code AND (e.tenant_id <=> a.tenant_id)
 WHERE e.id IS NULL;

-- O5b USER-<id> fallback rows written by the in-app punch when a login cannot be
--     resolved to an employee. These count as PRESENT DAYS for a code no employee holds.
SELECT COUNT(*) c, MIN(log_date) lo, MAX(log_date) hi
FROM attendance_logs WHERE emp_code LIKE 'USER-%';

-- O6  Employee -> org. Deleting a department leaves employees pointing at nothing.
SELECT SUM(c.id IS NULL) no_company, SUM(d.id IS NULL) no_dept, SUM(g.id IS NULL) no_desig,
       SUM(b.id IS NULL) no_branch,  SUM(t.id IS NULL) no_team
FROM employees e
  LEFT JOIN companies    c ON c.id = e.company_id
  LEFT JOIN departments  d ON d.id = e.department_id
  LEFT JOIN designations g ON g.id = e.designation_id
  LEFT JOIN branches     b ON b.id = e.branch_id
  LEFT JOIN teams        t ON t.id = e.team_id;

-- O6b Reporting-manager cycles: the self-referencing column has no constraint.
SELECT e1.id, e1.emp_code
FROM employees e1
  JOIN employees e2 ON e2.id = e1.reporting_manager_id
  JOIN employees e3 ON e3.id = e2.reporting_manager_id
WHERE e3.id = e1.id;

-- O7  Documents with no employee, and documents with no tenant (invisible to their owner).
SELECT SUM(e.id IS NULL) no_emp, SUM(d.tenant_id IS NULL) no_tenant, COUNT(*) total
FROM documents d LEFT JOIN employees e ON e.id = d.employee_id;

-- O8  employee_references has NO tenant column: an orphan here is also unscopeable.
SELECT COUNT(*) FROM employee_references r
  LEFT JOIN employees e ON e.id = r.employee_id WHERE e.id IS NULL;

-- O9  The duplicates the constraints would have prevented.
SELECT 'payroll_runs(tenant,company,cycle_label)' k, COUNT(*) dup_groups FROM (
  SELECT tenant_id, company_id, cycle_label FROM payroll_runs
   GROUP BY tenant_id, company_id, cycle_label HAVING COUNT(*) > 1) x
UNION ALL SELECT 'payslips(employee,month)', COUNT(*) FROM (
  SELECT employee_id, month FROM payslips GROUP BY employee_id, month HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees.pan', COUNT(*) FROM (
  SELECT tenant_id, pan FROM employees WHERE pan IS NOT NULL AND pan <> ''
   GROUP BY tenant_id, pan HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees.uan', COUNT(*) FROM (
  SELECT tenant_id, uan FROM employees WHERE uan IS NOT NULL AND uan <> ''
   GROUP BY tenant_id, uan HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees.bank_acc', COUNT(*) FROM (
  SELECT tenant_id, bank_acc FROM employees WHERE bank_acc IS NOT NULL AND bank_acc <> ''
   GROUP BY tenant_id, bank_acc HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees.esic_no', COUNT(*) FROM (
  SELECT tenant_id, esic_no FROM employees WHERE esic_no IS NOT NULL AND esic_no <> ''
   GROUP BY tenant_id, esic_no HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees.email', COUNT(*) FROM (
  SELECT tenant_id, email FROM employees WHERE email IS NOT NULL AND email <> ''
   GROUP BY tenant_id, email HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees.mobile', COUNT(*) FROM (
  SELECT tenant_id, mobile FROM employees WHERE mobile IS NOT NULL AND mobile <> ''
   GROUP BY tenant_id, mobile HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees.emp_code', COUNT(*) FROM (
  SELECT tenant_id, emp_code FROM employees GROUP BY tenant_id, emp_code HAVING COUNT(*) > 1) x
UNION ALL SELECT 'self_onboarding.temp_emp_code', COUNT(*) FROM (
  SELECT temp_emp_code FROM self_onboarding WHERE temp_emp_code IS NOT NULL
   GROUP BY temp_emp_code HAVING COUNT(*) > 1) x
UNION ALL SELECT 'holidays(tenant,date)', COUNT(*) FROM (
  SELECT tenant_id, date FROM holidays GROUP BY tenant_id, date HAVING COUNT(*) > 1) x
UNION ALL SELECT 'salary_components(tenant,scope,code)', COUNT(*) FROM (
  SELECT tenant_id, scope, code FROM salary_components
   GROUP BY tenant_id, scope, code HAVING COUNT(*) > 1) x
UNION ALL SELECT 'settings(tenant,key)', COUNT(*) FROM (
  SELECT tenant_id, `key` FROM settings GROUP BY tenant_id, `key` HAVING COUNT(*) > 1) x;
