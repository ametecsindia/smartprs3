-- =====================================================================
-- D5 — the app_state blob. READ-ONLY.
--
-- PRIVACY: the blob carries employee records overlaid by the SPA. Do NOT copy it
-- off site without a written basis. checks/d5_app_state.php profiles it structurally
-- by default and dumps only configuration collections; these queries return the raw
-- document, so run them ON the customer's machine and keep the output there.
-- =====================================================================

-- D5.1  Locate the blobs. Expect one per tenant; MORE THAN ONE is a finding,
--       because the runtime settings table has no unique (tenant_id, key).
SELECT id, tenant_id, `key`, LENGTH(`value`) AS bytes, updated_at
FROM settings WHERE `key` = 'app_state'
ORDER BY tenant_id, id;

-- D5.2  Does it parse? On installs where settings.value is longText rather than json,
--       a truncated or invalid document can sit there with no error ever raised.
SELECT id, tenant_id, JSON_VALID(`value`) AS parses, LENGTH(`value`) AS bytes
FROM settings WHERE `key` = 'app_state';

-- D5.3  Top-level collections and their element counts, per tenant (MySQL 5.7.8+).
SELECT s.tenant_id,
       jt.collection,
       JSON_TYPE(JSON_EXTRACT(s.`value`, CONCAT('$."', jt.collection, '"'))) AS json_type,
       JSON_LENGTH(JSON_EXTRACT(s.`value`, CONCAT('$."', jt.collection, '"'))) AS elements,
       LENGTH(JSON_EXTRACT(s.`value`, CONCAT('$."', jt.collection, '"')))     AS bytes
FROM settings s
  JOIN JSON_TABLE(JSON_KEYS(s.`value`), '$[*]' COLUMNS (collection VARCHAR(190) PATH '$')) jt
WHERE s.`key` = 'app_state' AND JSON_VALID(s.`value`)
ORDER BY s.tenant_id, jt.collection;
-- (JSON_TABLE needs MySQL 8.0. On 5.7, use checks/d5_app_state.php instead.)

-- D5.4  The role permission matrix — the only access-control data that exists.
--       R-XXIX-020: extract to a staging table, treat as intent to be re-confirmed,
--       never apply silently. Enforcing it for the first time stops users who work today.
SELECT tenant_id, JSON_EXTRACT(`value`, '$.roles') AS roles
FROM settings WHERE `key` = 'app_state' AND JSON_VALID(`value`);

-- D5.5  The whole blob, verbatim, per tenant. Never re-encode it: re-encoding loses
--       key order, numeric-string coercion and invalid UTF-8, all of which are evidence.
SELECT tenant_id, `value` FROM settings WHERE `key` = 'app_state' ORDER BY tenant_id;
