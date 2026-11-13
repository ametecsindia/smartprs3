#!/usr/bin/env bash
# One-step update of SmartPRS 3 on smartprs.com. Run after `git pull`:
#   cd /var/www/client_apps/SmartPRS3 && git pull && sudo bash deploy/update.sh
# Safe to run again any time. Stops at the first error and says which step failed.
set -euo pipefail
APP=/var/www/client_apps/SmartPRS3
cd "$APP"
trap 'echo; echo "FAILED at: $BASH_COMMAND — send this screen to Claude."' ERR

echo "1/6 Libraries";        command -v composer >/dev/null && sudo -u "${SUDO_USER:-root}" composer install --no-dev --no-scripts --optimize-autoloader -q || echo "   composer not found — skipped"
echo "2/6 Remove dev files"; rm -f surface/screens/dev-*.php surface/actions/dev-*.php public/dev-fieldtest*.js public/diag.php
echo "3/6 Database";         sudo -u www-data php tools/db/migrate.php
echo "4/6 Permissions";      chgrp -R www-data "$APP"; chmod -R g+w "$APP"; find "$APP" -type d -exec chmod g+s {} +
                             [ -n "${SUDO_USER:-}" ] && usermod -aG www-data "$SUDO_USER" || true
echo "5/6 Upload size";      echo 'client_max_body_size 200M;' > /etc/nginx/conf.d/smartprs3-upload.conf
                             printf 'upload_max_filesize = 200M\npost_max_size = 210M\nmax_execution_time = 300\n' > /etc/php/8.3/fpm/conf.d/99-smartprs3.ini
echo "6/6 Reload";           cp deploy/nginx-smartprs3-routes.conf /etc/nginx/snippets/ && nginx -t -q && systemctl reload nginx && systemctl restart php8.3-fpm

echo; echo "DONE — SmartPRS 3 is updated to: $(git log -1 --format='%h %s')"
