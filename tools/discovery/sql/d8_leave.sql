-- =====================================================================
-- D8 — leave balance derivation and the LOP blast radius.  READ-ONLY.
--
-- leaves.type_id is never written by any code path, so the paid/unpaid flag never
-- reached payroll and every approved leave — including leave filed as Loss of Pay —
-- was credited as a paid present day. That is a MISPAYMENT, not a migration task.
-- =====================================================================

-- D8.1  Confirm the defect on THIS install: type_id should be NULL on every row.
SELECT COUNT(*) total,
       SUM(type_id IS NULL)     null_type_id,
       SUM(type_id IS NOT NULL) populated_type_id,
       COUNT(DISTINCT type_name) distinct_type_names
FROM leaves;

-- D8.2  The mispayment population. The name match is deliberately broad; every hit
--       is triaged by a human.
SELECT l.id, l.tenant_id, l.employee_id, e.emp_code, e.name AS employee,
       l.type_name, l.from_date, l.to_date, l.days, l.status, l.decided_at,
       DATE_FORMAT(l.from_date, '%Y-%m') AS period_label
FROM leaves l LEFT JOIN employees e ON e.id = l.employee_id
WHERE l.status = 'approved'
  AND (  LOWER(l.type_name) LIKE '%loss of pay%'
      OR LOWER(l.type_name) LIKE '%lop%'
      OR LOWER(l.type_name) LIKE '%unpaid%'
      OR LOWER(l.type_name) LIKE '%without pay%'
      OR LOWER(l.type_name) LIKE '%lwp%' )
ORDER BY l.from_date;

-- D8.3  Which of those overlap a GENERATED payslip — i.e. money actually moved.
SELECT p.id AS payslip_id, p.employee_id, e.emp_code, p.month, p.gross, p.net,
       SUM(l.days) AS lop_days_credited_as_paid
FROM leaves l
  JOIN employees e ON e.id = l.employee_id
  JOIN payslips  p ON p.employee_id = l.employee_id
                  AND p.month = DATE_FORMAT(l.from_date, '%Y-%m')
WHERE l.status = 'approved'
  AND (LOWER(l.type_name) LIKE '%loss of pay%' OR LOWER(l.type_name) LIKE '%lop%'
    OR LOWER(l.type_name) LIKE '%unpaid%'      OR LOWER(l.type_name) LIKE '%without pay%'
    OR LOWER(l.type_name) LIKE '%lwp%')
GROUP BY p.id
ORDER BY p.month, e.emp_code;

-- D8.4  Leave type names that no longer resolve — the per-tenant manual reconciliation.
SELECT l.tenant_id, l.type_name, COUNT(*) c
FROM leaves l
  LEFT JOIN leave_types t ON t.tenant_id = l.tenant_id AND t.name = l.type_name
WHERE t.id IS NULL
GROUP BY l.tenant_id, l.type_name ORDER BY c DESC;

-- D8.5  The naive derived balance, on the calendar-year basis the code uses today.
--       R-XXIX-026: this is EVIDENCE for the customer, never an opening balance.
SELECT l.tenant_id, l.employee_id, e.emp_code, l.type_name,
       YEAR(l.from_date) AS leave_year,
       SUM(CASE WHEN l.status='approved' THEN l.days ELSE 0 END) AS approved_days,
       SUM(CASE WHEN l.status='pending'  THEN l.days ELSE 0 END) AS pending_days,
       t.quota AS declared_quota, t.paid AS declared_paid, t.carry_forward
FROM leaves l
  LEFT JOIN employees   e ON e.id = l.employee_id
  LEFT JOIN leave_types t ON t.tenant_id = l.tenant_id AND t.name = l.type_name
GROUP BY l.tenant_id, l.employee_id, l.type_name, YEAR(l.from_date)
ORDER BY l.tenant_id, e.emp_code, l.type_name, leave_year;

-- D8.6  Calendar-days-charged vs working-days-credited divergence: a Fri–Mon leave
--       costs 4 days of balance and credits 2 days of attendance.
SELECT COUNT(*) spanning_a_weekend
FROM leaves
WHERE status='approved' AND DATEDIFF(to_date, from_date) >= 2
  AND (WEEKDAY(from_date) >= 4 OR WEEKDAY(to_date) <= 1);
