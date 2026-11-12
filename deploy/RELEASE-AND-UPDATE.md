# SmartPRS3 — releasing an update and installing it online

Everything below is done on screens, apart from making the package on the build PC (one double-click) and the
one-time server permission step.

---

## 1. Make the package (build PC: the office PC with C:\laragon\www\SmartPRS3)

1. Make sure the code is final and working locally.
2. Double-click **`C:\laragon\www\SmartPRS3\make-release.bat`**.
3. It asks two things:
   - **New version**: press Enter to take the suggested next number (e.g. 3.0.1), or type one.
   - **What changed**: one line, which clients see in their update notice.
4. It writes three files into **`C:\SmartPRS3-Releases`**:

| File | What it is |
|---|---|
| `smartprs3-<version>.zip` | the application package |
| `smartprs3-<version>.sig` | Ametecs' signature. Servers refuse a package without a matching signature |
| `smartprs3-<version>.txt` | version, SHA-256, size and notes, for your records |

The package never contains `.env`, `storage/`, `.git`, `mobile/`, `public/uploads/` or `Claude outputs`.
It is signed with `storage\keys\license_private.pem`, so only the PC that holds that key can make a release.

5. Commit and push as usual. `config/version.json` now carries the new number.

---

## 2. Publish it on central (smartprs.com/super)

1. **Super Admin → Releases & Updates → New release.**
2. **Package (.zip)**: choose `smartprs3-<version>.zip` from `C:\SmartPRS3-Releases`.
   **Signature (.sig)**: choose `smartprs3-<version>.sig`.
   The version and notes are read from the package. Edit the notes if you like.
3. **Save.** The server checks the signature; a wrong or damaged file is refused.
4. **Publish.**

If the upload is refused as too big, copy the `.zip` and `.sig` into `/var/www/client_apps/SmartPRS3/storage/releases/`
on the server (WinSCP). Then on New release, pick it under **"…or a package already copied into storage/releases"**.

---

## 3. Send it to clients

On the release card:

- **Cloud (every cloud company):** click **Install on cloud server**. The cloud server updates itself and every cloud
  company moves to the new version together. The install log is shown on the page.
- **On-prem clients:** under **Send this update to**, tick the clients (or **All on-prem clients**) and click
  **Send to ticked clients**. Only ticked clients, with an active licence and AMC, are offered the update.
- **Applied by clients** on the card shows each on-prem server that downloaded, installed or failed.

---

## 4. What the client sees

**On-prem:** Administration → **Updates & Licence** → **Check for update**. If a release was sent to their licence, it
shows the version and notes, and their administrator clicks **Download & install**.

**Cloud:** the same page shows "You are on the latest version" with what's new. They have nothing to do, because the
cloud is updated from step 3.

**Every install, cloud or on-prem, follows the same steps:**
1. Downloads the package.
2. Checks the SHA-256 and Ametecs' signature.
3. Backs up every file it will replace into `storage/updates/backup-<old>-to-<new>-<time>.zip`.
4. Writes the new files. If any write fails, everything is put back.
5. Runs pending database migrations (never `--fresh`) and the application-account grants.
6. Records the install in `storage/updates/history.json`.

Never overwritten: `.env`, `storage/`, `public/uploads/`, and whatever `config/update-keep.json` lists
(it ships with `config/gst.json`).

---

## 5. One-time setup

### Bring the new update code to smartprs.com (once)
The live server doesn't have the installer yet, so this one time push to GitHub and run, on the server:

```
cd /var/www/client_apps/SmartPRS3 && git pull
```

From then on, releases go through steps 1–3.

### Server permissions (smartprs.com, once)
The web server (www-data) must be allowed to write the application folder, or Install on cloud server stops with
"This server's PHP cannot write the application folder". Run on the server:

```
sudo chgrp -R www-data /var/www/client_apps/SmartPRS3
sudo chmod -R g+w /var/www/client_apps/SmartPRS3
sudo find /var/www/client_apps/SmartPRS3 -type d -exec chmod g+s {} +
sudo usermod -aG www-data ametecsssh
```

Also make the upload limits large enough for a package (check the .zip size in `C:\SmartPRS3-Releases`):
- nginx: `client_max_body_size 200M;` in the smartprs server block, then `sudo systemctl reload nginx`.
- PHP: `upload_max_filesize = 200M` and `post_max_size = 210M` in `/etc/php/8.3/fpm/php.ini`, then
  `sudo systemctl restart php8.3-fpm`.

Or skip the upload limits and copy the package with WinSCP (end of step 2).

**Git and packages:** once the cloud is updated by packages, the server's git copy shows local changes.
Choose one route and stick to it. To go back to git once: `git checkout -- . && git pull`.

### On-prem servers
- `.env` needs `SMARTPRS_LICENCE_SERVER=https://smartprs.com`. This is now the default when the line is missing.
- The licence must be activated **online** (License Code). An offline `.lic` install has no key to check with, so it gets
  the package by hand: copy the zip over the install and run `php tools/db/migrate.php`.
- On Windows/Laragon, nothing else is needed. On Linux, do the same permission step for the client's folder.

---

## 6. If something goes wrong
- **Signature or checksum refused:** the package wasn't built by make-release.bat on the build PC, or it was damaged
  in transit. Build or upload it again.
- **Migration failed:** the files are installed. Read the log on the page, fix, then run
  `php tools/db/migrate.php` on that server.
- **Roll back:** unzip `storage/updates/backup-<old>-to-<new>-<time>.zip` over the application folder.
