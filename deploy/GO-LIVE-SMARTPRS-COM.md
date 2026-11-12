# Replacing SmartPRS 2 with SmartPRS 3 at smartprs.com

Owner decision, 12 Nov 2026: SmartPRS 3 replaces SmartPRS 2 at smartprs.com entirely, switched directly.

The server (checked 12 Nov 2026): Ubuntu 22.04, nginx, PHP 8.3-FPM, `ametecsssh@linux-ametecs` (35.200.195.98).
smartprs.com is `/etc/nginx/sites-available/smartprs`, serving `/var/www/client_apps/SmartPRS2/public`.
SmartPRS 2's database `smartprs` is on the MySQL server at 34.93.92.26. Its scheduler is the www-data cron line
`artisan smartprs:tick`.

Nothing of SmartPRS 2 is deleted. The switch changes one nginx file (a copy is kept) and one cron line, so
**Rollback** at the end puts SmartPRS 2 back in a minute. Other sites on this server are not touched.

Run each block, check it worked, then go to the next. Anything in `<angle brackets>` you replace.

---

## 0. Check the tools

```bash
which composer git; systemctl is-active mysql
```

`composer` and `git` must print a path. `mysql` says `active` only if there is a database server on this machine too.

## 1. Back up SmartPRS 2

```bash
mkdir -p ~/backups
sudo cp /etc/nginx/sites-available/smartprs ~/backups/smartprs.nginx.bak
sudo crontab -l -u www-data > ~/backups/www-data.crontab.bak
grep -E "^DB_USERNAME" /var/www/client_apps/SmartPRS2/.env
mysqldump -h 34.93.92.26 -u <that DB_USERNAME> -p --single-transaction --no-tablespaces smartprs > ~/backups/smartprs2-db-$(date +%F).sql
ls -lh ~/backups
```

The password it asks for is `DB_PASSWORD` in `/var/www/client_apps/SmartPRS2/.env`. The .sql file must not be 0 bytes.

## 2. Get the code

```bash
cd /var/www/client_apps
git clone https://github.com/ametecsindia/smartprs3.git SmartPRS3
```

GitHub asks for a username and password: give your GitHub username and a **personal access token** (GitHub →
Settings → Developer settings → Personal access tokens → Fine-grained, read access to `smartprs3`), not your password.

```bash
cd /var/www/client_apps/SmartPRS3
composer install --no-dev --no-scripts --optimize-autoloader
mkdir -p storage/keys storage/logs public/uploads
rm -f surface/screens/dev-*.php surface/actions/dev-*.php public/dev-fieldtest*.js public/diag.php
sudo chown -R www-data:www-data storage public/uploads
```

## 3. Database

Sign in to MySQL as an admin — on 34.93.92.26 (`mysql -h 34.93.92.26 -u <admin> -p`), or `sudo mysql` if step 0 said
`active` and you want the database on this machine (then use `localhost` instead of 34.93.92.26 below). Then, with two
new strong passwords:

```sql
CREATE DATABASE smartprs3 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'smartprs3_migrator'@'%' IDENTIFIED BY '<password 1>';
GRANT ALL PRIVILEGES ON smartprs3.* TO 'smartprs3_migrator'@'%' WITH GRANT OPTION;
CREATE USER 'smartprs3_app'@'%' IDENTIFIED BY '<password 2>';
GRANT SELECT, INSERT, UPDATE, DELETE ON smartprs3.* TO 'smartprs3_app'@'%';
EXIT;
```

## 4. .env

```bash
cd /var/www/client_apps/SmartPRS3
cp deploy/env.production.example .env
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
nano .env
```

Set these lines (Ctrl+O, Enter to save, Ctrl+X to close):

```
APP_KEY=<the base64:… line printed above>
APP_URL=https://smartprs.com
DB_HOST=34.93.92.26
DB_DATABASE=smartprs3
DB_USERNAME=smartprs3_app
DB_PASSWORD=<password 2>
DB_MIGRATION_USERNAME=smartprs3_migrator
DB_MIGRATION_PASSWORD=<password 1>
```

```bash
sudo chown www-data:www-data .env && sudo chmod 640 .env
```

Save the APP_KEY in your password manager — losing it makes stored licence keys and gateway secrets unreadable.

## 5. Build the tables

```bash
cd /var/www/client_apps/SmartPRS3
sudo -u www-data php tools/db/migrate.php
sudo -u www-data php tools/db/grant-application-account.php --apply
```

## 6. Licence signing key

From the office PC (Command Prompt):

```bat
scp C:\laragon\www\SmartPRS3\storage\keys\license_private.pem ametecsssh@35.200.195.98:/tmp/
```

Then on the server:

```bash
cd /var/www/client_apps/SmartPRS3
sudo mv /tmp/license_private.pem storage/keys/
sudo chown www-data:www-data storage/keys/license_private.pem && sudo chmod 600 storage/keys/license_private.pem
sudo -u www-data php tools/licence-keys.php status      # must say OK … matches
```

## 7. First Super Admin

```bash
cd /var/www/client_apps/SmartPRS3
sudo -u www-data php tools/make-super-admin.php <your email> "<Your Name>"
```

Note the one-time password it prints.

## 8. THE SWITCH (nginx)

```bash
F=/etc/nginx/sites-available/smartprs
sudo cp /var/www/client_apps/SmartPRS3/deploy/nginx-smartprs3-routes.conf /etc/nginx/snippets/
sudo sed -i 's#root /var/www/client_apps/SmartPRS2/public;#root /var/www/client_apps/SmartPRS3/public;\n    include snippets/nginx-smartprs3-routes.conf;#' $F
sudo sed -i 's#index index.php index.html;#index app.php;#' $F
sudo sed -i 's#try_files $uri $uri/ /index.php?$query_string;#try_files $uri $uri/ /app.php$is_args$args;#' $F
sudo grep -n "root\|include snippets/nginx\|index app\|app.php" $F
sudo nginx -t && sudo systemctl reload nginx
```

The grep must show the SmartPRS3 root, the include, `index app.php` and the `app.php` try_files line.
`nginx -t` must say *test is successful* — if it does not, nothing was reloaded; run Rollback step 1 and send me the error.

## 9. Scheduler

```bash
sudo crontab -u www-data -e
```

Put `#` at the start of the SmartPRS 2 line (`… SmartPRS2' && … artisan smartprs:tick …`) and add at the bottom:

```
*/5 * * * *  /usr/bin/php /var/www/client_apps/SmartPRS3/tools/cron.php >> /var/www/client_apps/SmartPRS3/storage/cron.log 2>&1
*/5 * * * *  /usr/bin/php /var/www/client_apps/SmartPRS3/tools/db/schedule.php timers >> /var/www/client_apps/SmartPRS3/storage/cron.log 2>&1
15 6 * * *   /usr/bin/php /var/www/client_apps/SmartPRS3/tools/db/schedule.php absence >> /var/www/client_apps/SmartPRS3/storage/cron.log 2>&1
20 6 * * *   /usr/bin/php /var/www/client_apps/SmartPRS3/tools/db/schedule.php unauthorized-absence >> /var/www/client_apps/SmartPRS3/storage/cron.log 2>&1
30 1 * * *   /usr/bin/php /var/www/client_apps/SmartPRS3/tools/db/schedule.php archive >> /var/www/client_apps/SmartPRS3/storage/cron.log 2>&1
45 23 * * *  /usr/bin/php /var/www/client_apps/SmartPRS3/tools/db/schedule.php anchor >> /var/www/client_apps/SmartPRS3/storage/cron.log 2>&1
```

Leave the SmartEPT, Leadger and vi_consumer lines exactly as they are.

## 10. Check

| Open | Expect |
|---|---|
| https://smartprs.com/ | SmartPRS 3.0 landing page |
| https://smartprs.com/signup | Signup page with plans |
| https://smartprs.com/privacy-policy | Privacy policy |
| https://smartprs.com/super | Super Admin sign-in — use the step 7 password, then change it |
| https://smartprs.com/iclock/getrequest?SN=TEST | `OK` |

Then in Super Admin: Payment Gateways (Razorpay keys), Platform Email (SMTP), WhatsApp. Fill the GSTIN in
`config/gst.json` → `seller`.

A 500 error: `sudo tail -30 /var/log/nginx/error.log` and send me the output.

---

## Rollback — back to SmartPRS 2

```bash
sudo cp ~/backups/smartprs.nginx.bak /etc/nginx/sites-available/smartprs
sudo nginx -t && sudo systemctl reload nginx
sudo crontab -u www-data ~/backups/www-data.crontab.bak
```

SmartPRS 2's code and database were never changed.

## Updating SmartPRS 3 later

```bash
cd /var/www/client_apps/SmartPRS3
git pull
composer install --no-dev --no-scripts --optimize-autoloader
rm -f surface/screens/dev-*.php surface/actions/dev-*.php public/dev-fieldtest*.js public/diag.php
sudo -u www-data php tools/db/migrate.php
sudo cp deploy/nginx-smartprs3-routes.conf /etc/nginx/snippets/ && sudo nginx -t && sudo systemctl reload nginx
```

## What stops working for SmartPRS 2 users at the switch

Its 4 live clients' logins, mobile app, biometric devices and on-prem licence checks at smartprs.com reach SmartPRS 3,
which has none of their data. Each needs a SmartPRS 3 workspace (Super Admin → Tenants) and their data moved.
