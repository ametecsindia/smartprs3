-- =====================================================================
-- D1 — schema capture.  READ-ONLY.
-- Replace :db with the application database name, e.g. 'smartprs'.
-- Safe on a live server during business hours: information_schema only.
-- =====================================================================

-- D1.1  Table inventory with engine, collation, row estimate and size.
--       TABLE_ROWS is an ESTIMATE on InnoDB. Never cite it in reconciliation.
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH,
       DATA_LENGTH + INDEX_LENGTH AS TOTAL_BYTES, CREATE_TIME, UPDATE_TIME, TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME;

-- D1.2  Full column definition for every table. THIS is the authority, not the migrations.
SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE,
       CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
       IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :db
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- D1.3  Every index, so a missing composite index is visible per install.
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
       SUB_PART, NULLABLE, INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = :db
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- D1.4  Foreign keys. Expect four, all Spatie. Anything else is worth knowing.
SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- D1.5  The migration ledger: which migrations this install BELIEVES it ran,
--       which is NOT the same as which schema it has.
SELECT id, migration, batch FROM migrations ORDER BY id;

-- D1.6  The type-divergence probes of XXIX.1.2, answered directly.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :db
  AND (
    (TABLE_NAME = 'employees'         AND COLUMN_NAME IN ('dob','doj','dra_expiry','pcc_expiry','pcc_deadline','geo_outside','pcc_status','dra_status','aadhaar','national_id','employment_type','employment_stage','archived_at','backup_due_at','deleted_at','probation_months','geo_radius_km'))
 OR (TABLE_NAME = 'salary_components' AND COLUMN_NAME IN ('calc_value','ctype','scope','seq'))
 OR (TABLE_NAME = 'settings'          AND COLUMN_NAME IN ('value','tenant_id','key'))
 OR (TABLE_NAME = 'attendance_pending')
 OR (TABLE_NAME = 'attendance_logs'   AND COLUMN_NAME IN ('tenant_id','emp_code','direction','source','punch_at','log_date','external_id','device_sn','device_user_id','verify_mode'))
 OR (TABLE_NAME = 'incentive_schemes')
 OR (TABLE_NAME = 'offroll_agents')
 OR (TABLE_NAME = 'documents'         AND COLUMN_NAME IN ('tenant_id','status','kind','category','file_path','file_size'))
 OR (TABLE_NAME = 'code_of_conduct_ack')
 OR (TABLE_NAME = 'training_subjects')
 OR (TABLE_NAME = 'messages_log'      AND COLUMN_NAME = 'sent_at')
 OR (TABLE_NAME IN ('letters','loans','commissions','shifts','late_policy','faqs','test_attempts'))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;

-- D1.7  Which of the runtime-only tables exist here.
--       PRESENT means application code created it; the migrations do not define it,
--       so its schema exists nowhere but in this database.
SELECT t.n AS expected_table,
       CASE WHEN i.TABLE_NAME IS NULL THEN 'ABSENT' ELSE 'PRESENT' END AS state
FROM (
  SELECT 'statutory_settings' n UNION ALL SELECT 'pay_cycles'      UNION ALL
  SELECT 'roster'               UNION ALL SELECT 'overtime'        UNION ALL
  SELECT 'salary_payments'      UNION ALL SELECT 'loan_recoveries' UNION ALL
  SELECT 'min_wages'            UNION ALL SELECT 'dra_certs'       UNION ALL
  SELECT 'posh_complaints'      UNION ALL SELECT 'user_password_resets' UNION ALL
  SELECT 'policies'             UNION ALL SELECT 'awards'          UNION ALL
  SELECT 'notices'              UNION ALL SELECT 'feature_flags'   UNION ALL
  SELECT 'transfers'            UNION ALL SELECT 'onboarding'      UNION ALL
  SELECT 'onboarding_items'     UNION ALL SELECT 'offroll_agents'  UNION ALL
  SELECT 'agent_earnings'       UNION ALL SELECT 'biometric_configs' UNION ALL
  SELECT 'biometric_unmapped'   UNION ALL SELECT 'attendance'      UNION ALL
  SELECT 'attendance_pending'   UNION ALL SELECT 'employee_company' UNION ALL
  SELECT 'notifications_feed'   UNION ALL SELECT 'company_emails'  UNION ALL
  SELECT 'pf_esi_settings'      UNION ALL SELECT 'tds_rules'       UNION ALL
  SELECT 'pt_slabs'             UNION ALL SELECT 'password_reset_tokens' UNION ALL
  SELECT 'salary_runs'          UNION ALL SELECT 'salary_slips'    UNION ALL
  SELECT 'leave_applications'   UNION ALL SELECT 'mobile_devices'  UNION ALL
  SELECT 'licences'             UNION ALL SELECT 'licence_events'  UNION ALL
  SELECT 'licence_devices'      UNION ALL SELECT 'releases'        UNION ALL
  SELECT 'release_grants'       UNION ALL SELECT 'client_updates'  UNION ALL
  SELECT 'onprem_clients'       UNION ALL SELECT 'onprem_payments'
) t
LEFT JOIN information_schema.TABLES i
       ON i.TABLE_SCHEMA = :db AND i.TABLE_NAME = t.n
ORDER BY state DESC, expected_table;

-- D1.8  attendance_pending discriminator: classify by which columns are present.
--         bulk       -> in_at, out_at, batch, log_date        (AttendanceBulkController)
--         quarantine -> punch_at, device_code, external_id, source
--         mixed      -> both sets  -> R-XXIX-011: route every row individually, never guess
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'attendance_pending'
ORDER BY ORDINAL_POSITION;

-- D1.9  Server facts the whole pack is interpreted against.
--       sql_mode decides whether the enum violations threw (data missing) or
--       silently blanked (data corrupt).
SELECT VERSION() AS mysql_version, @@SESSION.sql_mode AS session_sql_mode,
       @@GLOBAL.sql_mode AS global_sql_mode, @@SESSION.time_zone AS session_tz,
       @@GLOBAL.time_zone AS global_tz, @@system_time_zone AS system_tz,
       @@character_set_server AS charset, @@collation_server AS collation;

-- D1.10 SHOW CREATE TABLE — the faithful record. Run per table (mysql client):
--         SELECT CONCAT('SHOW CREATE TABLE `', TABLE_NAME, '`;')
--           FROM information_schema.TABLES
--          WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME;
--       Or, from a shell, the whole schema in one file:
--         mysqldump --no-data --routines --triggers --skip-add-drop-table \
--                   --single-transaction -u ro_user -p DBNAME > schema.sql
