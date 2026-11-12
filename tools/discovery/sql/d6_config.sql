-- =====================================================================
-- D6 — statutory settings, late policy, shifts, credentials.  READ-ONLY.
-- Several of these tables are runtime-created and may be ABSENT here.
-- An "Unknown table" error is a RESULT, not a failure: record it.
-- =====================================================================

-- D6.1  The statutory blob, per tenant. No effective dating, no audit, no versioning.
SELECT * FROM statutory_settings ORDER BY tenant_id;

-- D6.2  The effective_from candidate: earliest payroll run per tenant/company.
SELECT tenant_id, company_id, MIN(period_start) earliest_period_start,
       MIN(month) earliest_month_label, MIN(created_at) earliest_created, COUNT(*) runs
FROM payroll_runs GROUP BY tenant_id, company_id ORDER BY tenant_id, company_id;

-- D6.3  Blob vs superseded tables. If BOTH hold rows, one of the two was the live
--       statutory config for past payrolls and the other is wrong: a COMPLIANCE
--       EXPOSURE (J-… PO-2), not a migration question.
SELECT 'pf_esi_settings' t, COUNT(*) c FROM pf_esi_settings
UNION ALL SELECT 'tds_rules',          COUNT(*) FROM tds_rules
UNION ALL SELECT 'pt_slabs',           COUNT(*) FROM pt_slabs
UNION ALL SELECT 'statutory_configs',  COUNT(*) FROM statutory_configs
UNION ALL SELECT 'statutory_settings', COUNT(*) FROM statutory_settings;

-- D6.4  statutory_configs — the one effective-dated, scoped, indexed config table.
SELECT id, tenant_id, kind, scope_type, scope_value, effective_from, effective_to,
       LEFT(payload, 2000) payload_head, created_at
FROM statutory_configs ORDER BY tenant_id, kind, effective_from;

-- D6.5  Late policy. If mode and cut_mode are unset everywhere, the entire
--       late-penalty engine is DEAD IN THE FIELD and SmartPRS 3's requirement is
--       greenfield, not migration.
SELECT id, tenant_id, company_name, scope, mode, cut_mode, cut_n,
       grace_min, l1_min, l1_cut, l2_min, l2_cut, break_budget, break_cut,
       full_day_hours, half_day_hours, shift_start, shift_end,
       weekoff_action, no_cut_on_weekoff
FROM late_policy ORDER BY tenant_id, scope;

-- D6.6  Shifts: "earlier than start = night shift" is encoded in STRING comparison.
--       Answers whether ANY customer runs a cross-midnight shift today.
SELECT id, tenant_id, company_name, name, start_time, end_time,
       grace_min, full_day_hours, half_day_hours,
       (end_time <= start_time) AS looks_overnight
FROM shifts ORDER BY tenant_id, name;

-- D6.7  Per-company SMTP and branding. SMTP PASSWORDS ARE STORED IN PLAINTEXT.
--       Record presence and length only — never copy the value into the pack.
SELECT id, tenant_id, ckey, LENGTH(cval) AS value_bytes, updated_at
FROM app_settings ORDER BY tenant_id, ckey;

-- D6.8  Pay cycles — a runtime-only table that defines the payroll period.
SELECT * FROM pay_cycles ORDER BY tenant_id, company_id;

-- D6.9  Configuration whose HARD DELETION makes past payslips unexplainable.
SELECT tenant_id, COUNT(*) c, MIN(date) lo, MAX(date) hi FROM holidays GROUP BY tenant_id;
SELECT tenant_id, id, name, quota, paid, carry_forward FROM leave_types ORDER BY tenant_id, name;
SELECT tenant_id, scope, seq, code, name, ctype, calc_type, calc_value, base
FROM salary_components ORDER BY tenant_id, scope, seq;

-- D6.9b Is calc_value still holding formulas here, or has DECIMAL already eaten them?
SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'salary_components' AND COLUMN_NAME = 'calc_value';
SELECT COUNT(*) rows_with_letters FROM salary_components WHERE calc_value REGEXP '[A-Za-z]';

-- D6.10 min_wages — the register with the right columns whose consumer ignores them.
SELECT * FROM min_wages ORDER BY tenant_id, state, effective_from;
