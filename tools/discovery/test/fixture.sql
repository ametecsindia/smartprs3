-- Test fixture: a miniature SmartPRS 2 install carrying the pathologies Part XXIX
-- describes. Used by test/smoke-test.php. NOT part of the collector.
DROP DATABASE IF EXISTS smartprs_fixture;
CREATE DATABASE smartprs_fixture DEFAULT CHARACTER SET utf8mb4;
USE smartprs_fixture;

CREATE TABLE migrations (id INT AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255), batch INT);
INSERT INTO migrations (migration, batch) VALUES
 ('2026_01_01_000100_create_employees_table',1),('2026_08_27_000100_retire_pcc_overdue',3);

CREATE TABLE tenants (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255),
  subdomain VARCHAR(64), status VARCHAR(32), deleted_at TIMESTAMP NULL, created_at TIMESTAMP NULL);
INSERT INTO tenants (name,subdomain,status,created_at) VALUES
 ('Acme Security','acme','active',NOW()),('Demo Co','demo','active',NOW());

CREATE TABLE companies (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255), deleted_at TIMESTAMP NULL);
INSERT INTO companies (tenant_id,name) VALUES (1,'Acme HQ'),(2,'Demo HQ');

CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255),
  email VARCHAR(255), password VARCHAR(255), tenant_id BIGINT UNSIGNED NULL,
  status VARCHAR(32), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL);
-- bcrypt of 'password'
INSERT INTO users (name,email,password,tenant_id,status) VALUES
 ('On-Prem Admin','admin@smartprs.local','$2y$12$rji52u2Yty6lag1OCiDPR.w5CPtcYUypnHsaFkgiFC1xKsUM.qyky',NULL,'active'),
 ('Rita HR','rita@acme.test','$2y$10$abcdefghijklmnopqrstuv',1,'active'),
 ('Ghost','ghost@nowhere.test','$2y$10$abcdefghijklmnopqrstuv',99,'active');

-- employees: dob/doj are VARCHAR here (the runtime-created variant), pcc_deadline VARCHAR too
CREATE TABLE employees (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NULL, department_id BIGINT UNSIGNED NULL, reporting_manager_id BIGINT UNSIGNED NULL,
  emp_code VARCHAR(64), name VARCHAR(255), email VARCHAR(255), mobile VARCHAR(32), pan VARCHAR(20),
  dob VARCHAR(255) NULL, doj VARCHAR(255) NULL, pcc_deadline VARCHAR(255) NULL,
  pcc_status ENUM('pending','clear','rejected') NULL, dra_status VARCHAR(32) NULL,
  geo_outside ENUM('strict','allow') NULL, ctc DECIMAL(12,2) NULL,
  archived_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL);
INSERT INTO employees (tenant_id,company_id,department_id,emp_code,name,email,mobile,pan,dob,doj,pcc_deadline,pcc_status,dra_status,geo_outside,ctc,archived_at) VALUES
 (1,1,7,'EMP001','Asha Rao','asha@acme.test','9000000001','ABCDE1111F','1990-04-01','2020-01-01','2026-01-01','pending','Valid',NULL,600000,NULL),
 (1,1,NULL,'EMP002','Bala N','bala@acme.test','9000000002','ABCDE1111F','1988-02-02','2021-06-01',NULL,'clear','valid','strict',480000,NULL),
 (1,1,NULL,'EMP003','Chitra S','chitra@acme.test','9000000003','ZZZZZ9999Z','1992-09-09','2022-03-01','2025-01-01',NULL,'expired',NULL,360000,NOW()),
 (2,2,NULL,'EMP900','Demo Person','demo@demo.test','9999999999','QQQQQ0000Q','1995-01-01','2024-01-01',NULL,'pending','Valid',NULL,240000,NULL);

CREATE TABLE departments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED, name VARCHAR(255), deleted_at TIMESTAMP NULL);
INSERT INTO departments (tenant_id,name) VALUES (1,'Operations');

CREATE TABLE settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NULL,
  `key` VARCHAR(190), `value` LONGTEXT, updated_at TIMESTAMP NULL);
INSERT INTO settings (tenant_id,`key`,`value`,updated_at) VALUES
 (1,'app_state','{"roles":[{"name":"HR","perms":{"employees":["view","edit"],"payroll":["view"]}},{"name":"Admin","perms":{"employees":["view"]}}],"leaveTypes":[{"id":1,"name":"Casual","quota":12},{"id":2,"name":"LOP","quota":0}],"employees":[{"id":1,"name":"Asha Rao","pan":"ABCDE1111F"}],"complaints":[{"id":1,"text":"private"}],"holidays":[{"date":"2026-01-26","name":"Republic Day"},{"date":"2026-08-15"}]}',NOW()),
 (1,'app_state','{"roles":[],"leaveTypes":[]}',NOW()),
 (2,'app_state','{"roles":[{"name":"HR","perms":{}}],"leaveTypes":[{trunc',NOW()),
 (0,'landing','{}',NOW());

CREATE TABLE statutory_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  `key` VARCHAR(190), `value` LONGTEXT, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL);
INSERT INTO statutory_settings (tenant_id,`key`,`value`) VALUES (1,'rates','{"pf":12,"esi":0.75,"pt":200}');
CREATE TABLE pt_slabs (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED, upto DECIMAL(12,2), amount DECIMAL(12,2));
INSERT INTO pt_slabs (tenant_id,upto,amount) VALUES (1,15000,150),(1,999999,200);

CREATE TABLE salary_components (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  scope VARCHAR(64), seq INT, code VARCHAR(64), name VARCHAR(255),
  ctype ENUM('earning','deduction') NULL, calc_type VARCHAR(32), calc_value DECIMAL(12,2), base VARCHAR(64));
INSERT INTO salary_components (tenant_id,scope,seq,code,name,ctype,calc_type,calc_value) VALUES
 (1,'default',1,'BASIC','Basic','earning','formula',0.40),
 (1,'default',2,'HRA','HRA','earning','formula',0.20),
 (1,'default',2,'HRA','HRA duplicate','earning','formula',0.20);

CREATE TABLE payroll_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  company_id BIGINT UNSIGNED, month VARCHAR(7), cycle_label VARCHAR(255),
  status ENUM('draft','locked','paid') NULL, employees_count INT NULL, net_total DECIMAL(14,2) NULL,
  period_start DATE NULL, period_end DATE NULL, calc_note TEXT NULL, created_at TIMESTAMP NULL);
INSERT INTO payroll_runs (tenant_id,company_id,month,cycle_label,status,employees_count,net_total,period_start,calc_note,created_at) VALUES
 (1,1,'2026-01','Jan 2026','paid',3,120000,NULL,NULL,NOW()),
 (1,1,'2026-02','Feb 2026','paid',3,120000,NULL,NULL,NOW()),
 (1,1,'2026-03','Mar 2026','draft',3,118000,'2026-03-01','real run',NOW());

CREATE TABLE payslips (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  run_id BIGINT UNSIGNED NULL, employee_id BIGINT UNSIGNED, month VARCHAR(7),
  gross DECIMAL(12,2), net DECIMAL(12,2), earnings LONGTEXT NULL, deductions LONGTEXT NULL);
INSERT INTO payslips (tenant_id,run_id,employee_id,month,gross,net,earnings,deductions) VALUES
 (1,1,1,'2026-01',50000,50000,'[{"c":"CTC"}]',NULL),
 (1,1,2,'2026-01',40000,40000,'[{"c":"CTC"}]',NULL),
 (1,999,3,'2026-01',30000,30000,'[{"c":"CTC"}]',NULL),
 (1,3,1,'2026-03',50000,47230,'[{"c":"BASIC"},{"c":"HRA"}]','[{"c":"PF"}]'),
 (1,3,555,'2026-03',10000,9000,'[]',NULL);

CREATE TABLE commissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  employee_id BIGINT UNSIGNED NULL, month VARCHAR(7), amount DECIMAL(12,2), gross_amount DECIMAL(12,2) NULL,
  status VARCHAR(32) NULL, created_at TIMESTAMP NULL);
INSERT INTO commissions (tenant_id,employee_id,month,amount,gross_amount,status,created_at) VALUES
 (1,1,'2026-01',5000,5000,'pending',NOW()),(1,2,'2026-01',3000,NULL,'',NOW()),(1,404,'2026-02',2500,NULL,'approved',NOW());

CREATE TABLE loans (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  employee_id BIGINT UNSIGNED, amount DECIMAL(12,2), emi DECIMAL(12,2), tenure INT NULL,
  outstanding DECIMAL(12,2) DEFAULT 0, status VARCHAR(32), created_at TIMESTAMP NULL);
INSERT INTO loans (tenant_id,employee_id,amount,emi,tenure,outstanding,status) VALUES
 (1,1,60000,5000,12,0,'active'),(1,2,24000,2000,12,18000,'active');
CREATE TABLE loan_recoveries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  loan_id BIGINT UNSIGNED, run_id BIGINT UNSIGNED NULL, employee_id BIGINT UNSIGNED, amount DECIMAL(12,2), created_at TIMESTAMP NULL);
INSERT INTO loan_recoveries (tenant_id,loan_id,run_id,employee_id,amount,created_at) VALUES
 (1,2,1,2,20000,NOW()),(1,2,2,2,6000,NOW()),(1,1,999,1,5000,NOW());

CREATE TABLE advances (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  employee_id BIGINT UNSIGNED, amount DECIMAL(12,2), per_month DECIMAL(12,2), balance DECIMAL(12,2),
  status VARCHAR(32), created_at TIMESTAMP NULL);
INSERT INTO advances (tenant_id,employee_id,amount,per_month,balance,status) VALUES (1,1,10000,2000,8000,'active');

CREATE TABLE leave_types (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  name VARCHAR(255), quota INT, paid TINYINT, carry_forward TINYINT);
INSERT INTO leave_types (tenant_id,name,quota,paid,carry_forward) VALUES (1,'Casual',12,1,0),(1,'Loss of Pay',0,0,0);
CREATE TABLE leaves (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  employee_id BIGINT UNSIGNED, type_id BIGINT UNSIGNED NULL, type_name VARCHAR(255),
  from_date DATE, to_date DATE, days DECIMAL(5,2), status VARCHAR(32), decided_at TIMESTAMP NULL);
INSERT INTO leaves (tenant_id,employee_id,type_id,type_name,from_date,to_date,days,status) VALUES
 (1,1,NULL,'Loss of Pay','2026-01-08','2026-01-09',2,'approved'),
 (1,2,NULL,'LWP','2026-01-15','2026-01-19',5,'approved'),
 (1,1,NULL,'Casual','2026-03-02','2026-03-02',1,'approved'),
 (1,3,NULL,'Sick (retired type)','2026-02-01','2026-02-01',1,'approved');

CREATE TABLE attendance_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NULL,
  emp_code VARCHAR(64), log_date DATE, punch_at DATETIME NULL, direction VARCHAR(8), source VARCHAR(32));
INSERT INTO attendance_logs (tenant_id,emp_code,log_date,punch_at,direction,source) VALUES
 (1,'EMP001','2026-01-05','2026-01-05 09:00:00','in','biometric'),
 (1,'EMP001','2026-01-05','2026-01-05 18:00:00','out','biometric'),
 (1,'EMP002','2026-01-05','2026-01-05 22:00:00','in','biometric'),
 (1,'EMP002','2026-01-06','2026-01-06 06:00:00','out','biometric'),
 (1,'GONE99','2026-01-05','2026-01-05 09:00:00','in','biometric'),
 (1,'USER-42','2026-01-05','2026-01-05 09:05:00','in','app'),
 (1,'EMP001','2026-01-07','2026-01-07 09:00:00','in','demo'),
 (NULL,'EMP001','2026-01-08','2026-01-08 09:00:00','in','demo');

CREATE TABLE attendance_pending (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NULL,
  emp_code VARCHAR(64), log_date DATE, in_at VARCHAR(8), out_at VARCHAR(8), batch VARCHAR(64),
  punch_at DATETIME NULL, device_code VARCHAR(64) NULL, external_id VARCHAR(64) NULL, source VARCHAR(32) NULL);

CREATE TABLE attendance_corrections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  employee_id BIGINT UNSIGNED, log_date DATE, status VARCHAR(32), payroll_locked TINYINT,
  orig_in TIME NULL, orig_out TIME NULL, req_in TIME NULL, req_out TIME NULL, app_in TIME NULL, app_out TIME NULL,
  decided_at TIMESTAMP NULL);
INSERT INTO attendance_corrections (tenant_id,employee_id,log_date,status,payroll_locked,req_in,decided_at) VALUES
 (1,1,'2026-01-05','approved',1,'09:00:00',NOW());

CREATE TABLE shifts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  company_name VARCHAR(255) NULL, name VARCHAR(255), start_time VARCHAR(255), end_time VARCHAR(255),
  grace_min INT NULL, full_day_hours DECIMAL(4,2) NULL, half_day_hours DECIMAL(4,2) NULL);
INSERT INTO shifts (tenant_id,name,start_time,end_time) VALUES (1,'General','09:00','18:00'),(1,'Night','22:00','06:00');

CREATE TABLE late_policy (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  company_name VARCHAR(255) NULL, scope VARCHAR(64), mode VARCHAR(32) NULL, cut_mode VARCHAR(32) NULL,
  cut_n INT NULL, grace_min INT NULL, shift_start VARCHAR(255) NULL, shift_end VARCHAR(255) NULL,
  full_day_hours DECIMAL(4,2) NULL, half_day_hours DECIMAL(4,2) NULL);
INSERT INTO late_policy (tenant_id,scope,mode,cut_mode) VALUES (1,'global',NULL,NULL);

CREATE TABLE documents (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NULL,
  employee_id BIGINT UNSIGNED NULL, kind VARCHAR(64), status VARCHAR(32) NULL, file_path VARCHAR(500), file_size INT NULL);
INSERT INTO documents (tenant_id,employee_id,kind,status,file_path) VALUES
 (1,1,'aadhaar','ok','employee-docs/EMP001-aadhaar.pdf'),
 (NULL,2,'pan','ok','employee-docs/EMP002-pan.pdf'),
 (1,777,'pcc','ok','employee-docs/GONE-pcc.pdf');

CREATE TABLE activity_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NULL,
  action VARCHAR(190), entity_type VARCHAR(190) NULL, entity_id BIGINT UNSIGNED NULL,
  prev_hash VARCHAR(64) NULL, row_hash VARCHAR(64) NULL, created_at TIMESTAMP NULL);
INSERT INTO activity_logs (tenant_id,action,entity_type,entity_id,prev_hash,row_hash,created_at) VALUES
 (1,'employee.create','employee',1,'GENESIS','aaa',NOW()),
 (1,'employee.update','employee',1,'aaa','bbb',NOW()),
 (1,'orphan.sweep','commission',NULL,NULL,NULL,NOW()),
 (1,'employee.update','employee',2,'bbb','ccc',NOW());

CREATE TABLE deductions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  employee_id BIGINT UNSIGNED, month VARCHAR(7), type ENUM('advance','loan','other') NULL,
  status ENUM('pending','applied') NULL, amount DECIMAL(12,2));
INSERT INTO deductions (tenant_id,employee_id,month,type,status,amount) VALUES (1,1,'2026-01','advance','applied',2000);

CREATE TABLE increments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  employee_id BIGINT UNSIGNED, old_ctc DECIMAL(12,2), new_ctc DECIMAL(12,2), effective_from DATE,
  status VARCHAR(32), created_at TIMESTAMP NULL);
INSERT INTO increments (tenant_id,employee_id,old_ctc,new_ctc,effective_from,status) VALUES
 (1,1,600000,660000,'2026-04-01','approved'),(1,2,480000,450000,'2026-02-01','approved');

CREATE TABLE subscriptions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED, seats INT);
INSERT INTO subscriptions (tenant_id,seats) VALUES (1,25);

CREATE TABLE employee_references (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED, name VARCHAR(255), mobile VARCHAR(32), pan VARCHAR(20));
INSERT INTO employee_references (employee_id,name,mobile,pan) VALUES (1,'Ravi Ref','9111111111','REFPA1111R'),(888,'Orphan Ref','9222222222',NULL);

CREATE TABLE jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255));
CREATE TABLE failed_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payload LONGTEXT);
INSERT INTO failed_jobs (payload) VALUES ('{}'),('{}');
CREATE TABLE app_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED,
  ckey VARCHAR(190), cval TEXT, updated_at TIMESTAMP NULL);
INSERT INTO app_settings (tenant_id,ckey,cval) VALUES (1,'smtp_host','smtp.acme.test'),(1,'smtp_password','S3cretPlainText');

-- A legacy NON-STRICT write: the value the code sent was not in the ENUM, so MySQL
-- blanked it silently. This is what an E1–E12 violation looks like on disk.
SET SESSION sql_mode='';
INSERT INTO deductions (tenant_id,employee_id,month,type,status,amount) VALUES (1,2,'2026-02','commission','sent',1500);
INSERT INTO employees (tenant_id,company_id,emp_code,name,email,pan,dob,doj,pcc_status,dra_status,geo_outside,ctc)
 VALUES (1,1,'EMP004','Deepa K','deepa@acme.test','ABCDE2222F','1991-01-01','2023-01-01','overdue','Valid','allow_1km',500000);
SET SESSION sql_mode=DEFAULT;
