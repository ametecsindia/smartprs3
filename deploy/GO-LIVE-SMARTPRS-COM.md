# Replacing SmartPRS 2 with SmartPRS 3 at smartprs.com (cPanel)

Owner decision, 12 Nov 2026: SmartPRS 3 replaces SmartPRS 2 at smartprs.com entirely, switched directly.
Nothing of SmartPRS 2 is deleted — its files are renamed and its database is left as it is, so the Rollback section
puts it back in two minutes if anything goes wrong.

Below, `CPUSER` is your cPanel username (top right of cPanel). Commands go in **cPanel → Terminal**.
PHP 8.3 on cPanel is `/opt/cpanel/ea-php83/root/usr/bin/php` — written `PHP` below.

---

## 1. Back up SmartPRS 2 (do not skip)

cPanel → **Backup** → *Download a Full Account Backup* → Generate. Wait for the email, download the file to your PC.
Also, in Terminal:

```bash
mkdir -p ~/backups
tar czf ~/backups/smartprs2-files-$(date +%F).tar.gz -C ~ public_html
```

and cPanel → **phpMyAdmin** → SmartPRS 2's database → *Export* → Go. Keep the .sql on your PC.

## 2. PHP 8.3 for smartprs.com

- cPanel → **MultiPHP Manager** → tick smartprs.com → PHP version **ea-php83** → Apply.
- cPanel → **MultiPHP INI Editor** → smartprs.com:
  `date.timezone = Asia/Kolkata`, `upload_max_filesize = 25M`, `post_max_size = 26M`,
  `memory_limit = 512M`, `max_execution_time = 300`.
- Extensions needed (most are on by default): pdo_mysql, mbstring, curl, intl, zip, gd, xml, openssl.

## 3. Database

cPanel → **MySQL Databases**:

1. New database: `smartprs3` (cPanel names it `CPUSER_smartprs3`).
2. New user `s3mig` with a strong password → add to the database with **ALL PRIVILEGES** (runs migrations only).
3. New user `s3app` with another strong password → add to the database with **SELECT, INSERT, UPDATE, DELETE** only.

## 4. Upload the code

On the office PC, make a zip of `C:\laragon\www\SmartPRS3` **with** `vendor\` and **without**:
`.git`, `.env`, `.env.bak`, `env-local.txt`, `storage`, `public\uploads`, `Claude outputs`, `mobile`.

cPanel → **File Manager** → home folder (`/home/CPUSER`, not public_html) → Upload the zip → Extract → rename
the extracted folder to `smartprs3`. Then in Terminal:

```bash
cd ~/smartprs3
mkdir -p storage/keys public/uploads
rm -f surface/screens/dev-*.php surface/actions/dev-*.php public/dev-fieldtest*.js public/diag.php
chmod -R 755 storage public/uploads
```

## 5. .env

```bash
cd ~/smartprs3
cp deploy/env.production.example .env
PHP -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Open `~/smartprs3/.env` in File Manager → Edit, and set:

```
APP_KEY=<the base64:… line printed above>
APP_URL=https://smartprs.com
DB_HOST=localhost
DB_DATABASE=CPUSER_smartprs3
DB_USERNAME=CPUSER_s3app
DB_PASSWORD=<s3app password>
DB_MIGRATION_USERNAME=CPUSER_s3mig
DB_MIGRATION_PASSWORD=<s3mig password>
MAIL_FROM_ADDRESS="no-reply@smartprs.com"
```

Save, then `chmod 640 ~/smartprs3/.env`. Copy the APP_KEY somewhere safe (password manager) — losing it makes stored
licence keys and gateway secrets unreadable.

## 6. Build the tables

```bash
cd ~/smartprs3
PHP tools/db/migrate.php
PHP tools/db/grant-application-account.php --apply
```

The second command may say it cannot GRANT on shared hosting — that is fine, step 3 already gave `s3app` its rights.

## 7. Licence signing key

File Manager → upload `C:\laragon\www\SmartPRS3\storage\keys\license_private.pem` into `~/smartprs3/storage/keys/`, then:

```bash
chmod 600 ~/smartprs3/storage/keys/license_private.pem
cd ~/smartprs3 && PHP tools/licence-keys.php status      # must say OK … matches
```

Never put this file in GitHub or email.

## 8. THE SWITCH

```bash
cd ~
mv public_html public_html_smartprs2
ln -s ~/smartprs3/public public_html
```

smartprs.com now serves SmartPRS 3. (No Terminal? Add a one-off cPanel Cron Job running exactly those two lines,
wait a minute, then delete the cron job.)

## 9. Cron jobs

cPanel → **Cron Jobs**, add each (replace PHP with the full path above):

```
*/5 * * * *  PHP /home/CPUSER/smartprs3/tools/cron.php >> /home/CPUSER/smartprs3/storage/cron.log 2>&1
*/5 * * * *  PHP /home/CPUSER/smartprs3/tools/db/schedule.php timers >> /home/CPUSER/smartprs3/storage/cron.log 2>&1
15 6 * * *   PHP /home/CPUSER/smartprs3/tools/db/schedule.php absence >> /home/CPUSER/smartprs3/storage/cron.log 2>&1
20 6 * * *   PHP /home/CPUSER/smartprs3/tools/db/schedule.php unauthorized-absence >> /home/CPUSER/smartprs3/storage/cron.log 2>&1
30 1 * * *   PHP /home/CPUSER/smartprs3/tools/db/schedule.php archive >> /home/CPUSER/smartprs3/storage/cron.log 2>&1
45 23 * * *  PHP /home/CPUSER/smartprs3/tools/db/schedule.php anchor >> /home/CPUSER/smartprs3/storage/cron.log 2>&1
```

Remove SmartPRS 2's old cron jobs from the same screen (note them down first, for Rollback).

## 10. First Super Admin and settings

```bash
cd ~/smartprs3 && PHP tools/make-super-admin.php you@ametecsindia.com "Your Name"
```

It prints a one-time password. Sign in at **https://smartprs.com/super**, change it under Platform Staff, then set:
Payment Gateways (Razorpay keys), Platform Email (SMTP), WhatsApp. Fill the GSTIN in `config/gst.json` → `seller`.

## 11. Check

| Open | Expect |
|---|---|
| https://smartprs.com/ | SmartPRS 3.0 landing page |
| https://smartprs.com/signup | Signup page with plans |
| https://smartprs.com/super | Super Admin sign-in |
| https://smartprs.com/privacy-policy | Privacy policy |
| https://smartprs.com/iclock/getrequest?SN=TEST | `OK` |

If the site shows a 500 error: `tail -50 ~/smartprs3/public/error_log` and cPanel → **Errors**.

---

## Rollback — back to SmartPRS 2 in two minutes

```bash
cd ~
rm public_html                     # removes only the link, not SmartPRS 3
mv public_html_smartprs2 public_html
```

MultiPHP Manager → set smartprs.com back to its old PHP version, and restore the old cron jobs.
SmartPRS 2's database was never touched.

## What stops working for SmartPRS 2 users at the switch

Its 4 live clients' logins, mobile app, biometric devices and on-prem licence checks at smartprs.com now reach
SmartPRS 3, which has none of their data. Each client needs a SmartPRS 3 workspace (Super Admin → Tenants) and
their data moved before or right after the switch.
