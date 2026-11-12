# Putting SmartPRS 3 online at app.smartprs.com

Plain steps for a Linux VM (Ubuntu 22.04/24.04, GCP or AWS). SmartPRS 2 at smartprs.com is not
touched — SmartPRS 3 gets its own folder, database, PHP pool and subdomain.

Time: about an hour. You need SSH access to the VM and the Cloudflare/GoDaddy DNS panel.

---

## 1. DNS

Add an **A record**: `app` → the VM's public IP (same IP as smartprs.com is fine).
If smartprs.com is on Cloudflare, set it to "DNS only" (grey cloud) until the certificate in step 7 is issued.

## 2. Server software (once)

```bash
# Ubuntu 22.04 only: PHP 8.3 comes from the ondrej PPA →  sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y nginx mariadb-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl \
  php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd php8.3-intl unzip git certbot python3-certbot-nginx
```

PHP must run in Indian time — punches, payroll dates and "today" all depend on it:

```bash
echo "date.timezone = Asia/Kolkata" | sudo tee /etc/php/8.3/fpm/conf.d/99-smartprs.ini /etc/php/8.3/cli/conf.d/99-smartprs.ini
echo -e "upload_max_filesize = 25M\npost_max_size = 26M\nmemory_limit = 512M\nmax_execution_time = 300" | sudo tee -a /etc/php/8.3/fpm/conf.d/99-smartprs.ini
sudo systemctl restart php8.3-fpm
```

(If smartprs.com already runs PHP 8.1 on this VM, 8.3 installs alongside it; SmartPRS 2 keeps its own version.)

## 3. Code (GitHub)

On your PC (once): create a **private** GitHub repository `smartprs3`, then in `C:\laragon\www\SmartPRS3`:

```bat
git init
git add .
git commit -m "SmartPRS 3"
git branch -M main
git remote add origin https://github.com/<your-account>/smartprs3.git
git push -u origin main
```

`.gitignore` already keeps `.env`, `storage/` (licence private key, logs), `vendor/` and `public/uploads/` out of GitHub.

On the VM:

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/<your-account>/smartprs3.git
cd smartprs3
sudo mkdir -p storage/keys public/uploads
sudo rm -f surface/screens/dev-*.php surface/actions/dev-*.php     # development-only helper screens
sudo chown -R www-data:www-data storage public/uploads
```

## 4. Database

```bash
sudo mysql <<'SQL'
CREATE DATABASE smartprs3 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'smartprs3_migrator'@'localhost' IDENTIFIED BY 'CHOOSE-A-STRONG-ONE';
GRANT ALL PRIVILEGES ON smartprs3.* TO 'smartprs3_migrator'@'localhost' WITH GRANT OPTION;
CREATE USER 'smartprs3_app'@'localhost' IDENTIFIED BY 'CHOOSE-ANOTHER-STRONG-ONE';
CREATE USER 'smartprs3_app'@'127.0.0.1' IDENTIFIED BY 'CHOOSE-ANOTHER-STRONG-ONE';
FLUSH PRIVILEGES;
SQL
```

## 5. .env

```bash
sudo cp deploy/env.production.example .env
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"    # paste as APP_KEY
sudo nano .env      # APP_KEY, DB passwords from step 4
sudo chown www-data:www-data .env && sudo chmod 640 .env
```

Then build the tables and give the app account its rights:

```bash
sudo -u www-data php tools/db/migrate.php
sudo -u www-data php tools/db/grant-application-account.php --apply
```

## 6. Licence signing key

The file `storage/keys/license_private.pem` on the office PC (`C:\laragon\www\SmartPRS3\storage\keys\`) signs on-premise
`.lic` files. Copy it to the VM — never into GitHub, never by email:

```bash
# from the PC:  scp storage/keys/license_private.pem user@VM:/tmp/
sudo mv /tmp/license_private.pem /var/www/smartprs3/storage/keys/
sudo chown www-data:www-data storage/keys/license_private.pem && sudo chmod 600 storage/keys/license_private.pem
sudo -u www-data php tools/licence-keys.php status     # must say "OK — … matches"
```

Keep a backup of this file somewhere safe (password manager / encrypted drive). Lose it and no new `.lic` files can be
issued for installs already in the field (online License Codes keep working).

## 7. nginx + HTTPS

```bash
sudo cp deploy/nginx-app.smartprs.com.conf /etc/nginx/sites-available/app.smartprs.com
sudo ln -s /etc/nginx/sites-available/app.smartprs.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.smartprs.com
```

(If smartprs.com runs Apache instead of nginx on this VM, use `public/.htaccess` — it carries the same routes — with a
VirtualHost whose `DocumentRoot` is `/var/www/smartprs3/public` and `AllowOverride All`.)

## 8. Scheduler (cron)

```bash
sudo crontab -u www-data -e
```

```
*/5 * * * *  php /var/www/smartprs3/tools/cron.php >> /var/www/smartprs3/storage/cron.log 2>&1
*/5 * * * *  php /var/www/smartprs3/tools/db/schedule.php timers  >> /var/www/smartprs3/storage/cron.log 2>&1
15 6 * * *   php /var/www/smartprs3/tools/db/schedule.php absence >> /var/www/smartprs3/storage/cron.log 2>&1
20 6 * * *   php /var/www/smartprs3/tools/db/schedule.php unauthorized-absence >> /var/www/smartprs3/storage/cron.log 2>&1
30 1 * * *   php /var/www/smartprs3/tools/db/schedule.php archive >> /var/www/smartprs3/storage/cron.log 2>&1
45 23 * * *  php /var/www/smartprs3/tools/db/schedule.php anchor >> /var/www/smartprs3/storage/cron.log 2>&1
```

`tools/cron.php` pulls eTimeOffice / eTimeTrackLite / Generic biometric devices on their own sync frequency.

## 9. First Super Admin

Open **https://app.smartprs.com/super**. It asks for a one-time setup token:

```bash
sudo cat /var/www/smartprs3/storage/super-setup-token.txt
```

Paste it, set your email and a 12+ character password. (Shell alternative:
`sudo -u www-data php tools/make-super-admin.php you@ametecsindia.com "Your Name"` prints a password.)

From the Super Admin console: **Tenants → Add tenant** with an admin email provisions a client workspace and shows its
one-time password; the client signs in at `https://app.smartprs.com/c/<workspace-slug>`.

## 10. Check it works

| Check | Expected |
|---|---|
| `https://app.smartprs.com/` | SmartPRS sign-in page |
| `https://app.smartprs.com/super` | Super Admin sign-in |
| `curl https://app.smartprs.com/api/v1/ping` | `{"error":{"code":"API_KEY_401",…}}` (alive, needs a key) |
| `curl -X POST https://app.smartprs.com/update/heartbeat -d key=x` | `{"ok":false,…"Licence not recognised…"}` |
| `curl "https://app.smartprs.com/iclock/getrequest?SN=TEST"` | `OK` |

---

## Updating later

```bash
cd /var/www/smartprs3
sudo -u www-data git pull
sudo rm -f surface/screens/dev-*.php surface/actions/dev-*.php
sudo -u www-data php tools/db/migrate.php
sudo -u www-data php tools/db/grant-application-account.php --apply
```

## Before real clients go on it (from CLAUDE.md "open items")

- `Session::PASSWORD_FLOOR` back to 12 (surface/Session.php).
- No `admin@smartprslocal` account in the production database (it is never created there unless the local seed is run).
- Fill the GSTIN in `config/gst.json` → `seller` (printed on on-prem licence invoices).

---

## On-premise client install (Windows / IIS or Laragon)

1. Copy the release (same code) to the client server; serve **public\\** as the site root (a host or a port of its own —
   the mobile app and biometric devices need the site at `/`, not in a sub-folder).
   IIS: copy `deploy\web.config` into `public\` and install the IIS URL Rewrite module. Laragon/Apache: `public\.htaccess` already does it.
2. `.env`: database as usual, plus
   ```
   SMARTPRS_DEPLOYMENT=onprem
   SMARTPRS_LICENCE_SERVER=https://app.smartprs.com
   ```
3. Run `php tools/db/migrate.php` and `php tools/db/grant-application-account.php --apply`.
4. Open the site — it goes to **/activate** and shows the server fingerprint. Either:
   - **Online**: in Super Admin → On-Prem Clients, record the sale and payment, press **Generate License Code**, give the
     client the `SPRS-XXXX-…` code; they paste it on /activate. It binds to that server automatically and checks in daily
     (revocation, renewal, seat cap follow automatically).
   - **Offline .lic**: paste the client's fingerprint into **Generate .lic file**, send them the downloaded file; they upload
     it on /activate. Needs `SMARTPRS_OFFLINE_LIC=true` in their `.env`. Renewal = a fresh .lic.
5. Windows Task Scheduler, every 5 minutes: `php C:\path\to\SmartPRS3\tools\cron.php`.
6. Moving to a new server: Super Admin → the client → **Release machine**, then activate on the new server.
