<?php
/**
 * D11 — environment fingerprint.
 *
 * Three of these fields settle questions the analysis could not:
 *   - mysql.sql_mode decides whether the enum violations THREW (data missing) or
 *     SILENTLY BLANKED (data corrupt). Different remediation.
 *   - queue_worker_running decides whether the customer has ever received a
 *     notification, and therefore whether an alert marked 'sent' was delivered.
 *   - app.timezone (config default is UTC; only the .env templates set Asia/Kolkata)
 *     decides whether the absence sweep has been evaluating the wrong day between
 *     18:30 and 24:00 IST and marking working employees absent.
 *
 * Also answers: is demo:reset scheduled on this install, and does the default
 * on-prem credential account still exist with its published password?
 *
 * Secrets from .env are NEVER written to the pack — only key presence and length.
 *
 * Read-only. Standalone: php checks/d11_env.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

function d11_env(DscCtx $c): void
{
    $app = isset($c->opt['app']) ? rtrim((string) $c->opt['app'], "/\\") : null;
    $sep = DIRECTORY_SEPARATOR;
    $fp = [
        'install_id' => $c->install,
        'collected_at_utc' => gmdate('c'),
        'collected_by' => (string) ($c->opt['collected-by'] ?? (getenv('USERNAME') ?: getenv('USER') ?: 'unknown')),
        'collector_host' => php_uname('n'),
        'collector_os' => PHP_OS_FAMILY,
        'redacted' => $c->redact,
    ];

    /* ---- application revision --------------------------------------- */
    $appFacts = ['root' => $app];
    if ($app !== null) {
        foreach (['version.json', 'VERSION', 'public/version.json'] as $rel) {
            $f = $app . $sep . str_replace('/', $sep, $rel);
            if (is_file($f)) {
                $raw = trim((string) file_get_contents($f));
                $appFacts['version_file'] = $rel;
                $appFacts['version_raw'] = mb_substr($raw, 0, 2000);
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    $appFacts['version'] = $j;
                }
                break;
            }
        }
        if (!isset($appFacts['version_file'])) {
            $appFacts['version_file'] = null;
            $c->finding('HIGH', 'env', 'No version.json on this install',
                'R-XXIX-019 needs the exact revision to diff app_state collections against the prototype seed '
                . 'shipped with that revision. Without it, the NORMALISE-vs-PROTOTYPE-SEED verdict cannot be '
                . 'evidenced and must be decided by interview instead.');
        }
        $head = $app . $sep . '.git' . $sep . 'HEAD';
        if (is_file($head)) {
            $appFacts['git_head'] = trim((string) file_get_contents($head));
        }
        // composer.lock is parsed directly — Composer itself is not invoked.
        $lock = $app . $sep . 'composer.lock';
        if (is_file($lock)) {
            $j = json_decode((string) file_get_contents($lock), true);
            $pkgs = [];
            foreach ((array) ($j['packages'] ?? []) as $p) {
                $pkgs[] = ($p['name'] ?? '?') . ' ' . ($p['version'] ?? '?');
            }
            sort($pkgs);
            $appFacts['composer_packages'] = $pkgs;
        }
    }
    $fp['app'] = $appFacts;

    /* ---- PHP --------------------------------------------------------- */
    $ext = get_loaded_extensions();
    sort($ext);
    $fp['php'] = [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'date.timezone' => ini_get('date.timezone'),
        'default_timezone' => date_default_timezone_get(),
        'extensions' => $ext,
        'sourceguardian_loader' => extension_loaded('SourceGuardian') || extension_loaded('sourceguardian'),
        'ioncube_loader' => extension_loaded('ionCube Loader'),
    ];
    if (version_compare(PHP_VERSION, '8.1', '<') || version_compare(PHP_VERSION, '8.4', '>=')) {
        $c->finding('MEDIUM', 'env', 'CLI PHP is ' . PHP_VERSION,
            'The target installs run 8.1–8.3. This is the CLI binary running the collector, which may differ '
            . 'from the web SAPI the application runs under — check both.');
    }

    /* ---- MySQL ------------------------------------------------------- */
    $fp['mysql'] = [
        'version' => (string) dsc_val($c, 'SELECT VERSION()', [], 'd11.my', ''),
        'sql_mode_session' => (string) dsc_val($c, 'SELECT @@SESSION.sql_mode', [], 'd11.my', ''),
        'sql_mode_global' => (string) dsc_val($c, 'SELECT @@GLOBAL.sql_mode', [], 'd11.my', ''),
        'innodb_version' => (string) dsc_val($c, 'SELECT @@innodb_version', [], 'd11.my', ''),
        'time_zone_session' => (string) dsc_val($c, 'SELECT @@SESSION.time_zone', [], 'd11.my', ''),
        'time_zone_global' => (string) dsc_val($c, 'SELECT @@GLOBAL.time_zone', [], 'd11.my', ''),
        'system_time_zone' => (string) dsc_val($c, 'SELECT @@system_time_zone', [], 'd11.my', ''),
        'character_set_server' => (string) dsc_val($c, 'SELECT @@character_set_server', [], 'd11.my', ''),
        'collation_server' => (string) dsc_val($c, 'SELECT @@collation_server', [], 'd11.my', ''),
        'now' => (string) dsc_val($c, 'SELECT NOW()', [], 'd11.my', ''),
        'utc_now' => (string) dsc_val($c, 'SELECT UTC_TIMESTAMP()', [], 'd11.my', ''),
    ];

    /* ---- Laravel configuration (from .env; secrets never recorded) ---- */
    $env = $app !== null ? dsc_env($app) : [];
    $safeKeys = ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_TIMEZONE', 'APP_LOCALE', 'DB_CONNECTION',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'CACHE_STORE',
        'CACHE_DRIVER', 'FILESYSTEM_DISK', 'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'LOG_CHANNEL',
        'BROADCAST_CONNECTION', 'SCHEDULE_TIMEZONE'];
    $laravel = [];
    foreach ($safeKeys as $k) {
        $laravel[$k] = $env[$k] ?? null;
    }
    $secretPresence = [];
    foreach ($env as $k => $v) {
        if (preg_match('/KEY|SECRET|PASSWORD|TOKEN|DSN|CREDENTIAL/i', $k)) {
            $secretPresence[$k] = $v === '' ? 'empty' : 'present(' . strlen($v) . ' bytes)';
        }
    }
    $laravel['_secret_keys'] = $secretPresence;
    $fp['laravel'] = $laravel;

    $tz = $env['APP_TIMEZONE'] ?? null;
    $c->finding($tz === null || strtoupper((string) $tz) === 'UTC' ? 'HIGH' : 'INFO', 'env',
        'APP_TIMEZONE = ' . ($tz === null ? '(unset — config default is UTC)' : $tz),
        $tz === null || strtoupper((string) $tz) === 'UTC'
            ? 'The absence sweep has been evaluating the WRONG DAY between 18:30 and 24:00 IST and marking '
              . 'working employees absent (B-… Appendix Q5 #10). Every attendance and LOP figure derived on '
              . 'this install carries that error, and the reinterpretation is conditional on this field '
              . '(R-XXIX-031). Size the affected population before quoting any attendance number.'
            : 'Timestamps on this install are interpreted in this zone. Record it permanently against the '
              . 'migration record: the date-parsing and timezone-reinterpretation choices depend on it.');

    /* ---- Operational: scheduler, queue worker, storage link ---------- */
    $op = [];
    $op['storage_link_present'] = $app !== null
        && (is_link($app . $sep . 'public' . $sep . 'storage') || is_dir($app . $sep . 'public' . $sep . 'storage'));

    [$cronOut, $cronOk] = d11_run(PHP_OS_FAMILY === 'Windows' ? 'schtasks /query /fo LIST' : 'crontab -l');
    $op['cron_query_ran'] = $cronOk;
    $op['cron_mentions_schedule_run'] = $cronOk && stripos($cronOut, 'schedule:run') !== false;
    $op['cron_entry'] = $cronOk
        ? implode("\n", array_values(array_filter(explode("\n", $cronOut),
            static fn($l) => stripos($l, 'artisan') !== false || stripos($l, 'schedule') !== false)))
        : '';

    [$psOut, $psOk] = d11_run(PHP_OS_FAMILY === 'Windows'
        ? 'wmic process get commandline' : 'ps -eo args');
    $op['process_query_ran'] = $psOk;
    $op['queue_worker_running'] = $psOk && (bool) preg_match('/artisan\s+queue:(work|listen)/i', $psOut);
    $op['scheduler_process_running'] = $psOk && (bool) preg_match('/artisan\s+schedule:(run|work)/i', $psOut);
    $op['horizon_running'] = $psOk && (bool) preg_match('/artisan\s+horizon/i', $psOut);

    // demo:reset — scheduled here?
    $demo = ['scheduled' => 'unknown', 'sites' => []];
    if ($app !== null) {
        foreach (['app/Console/Kernel.php', 'routes/console.php', 'bootstrap/app.php'] as $rel) {
            $f = $app . $sep . str_replace('/', $sep, $rel);
            if (!is_file($f)) {
                continue;
            }
            $src = (string) file_get_contents($f);
            if (preg_match('/demo:reset|DemoReset/i', $src)) {
                $demo['sites'][] = $rel;
            }
        }
        $demo['scheduled'] = $demo['sites'] ? 'yes' : 'no';
    }
    $op['demo_reset'] = $demo;
    if ($demo['scheduled'] === 'yes') {
        $c->finding('BLOCKER', 'env', 'demo:reset is wired into the scheduler on this install',
            'DemoReset deletes settings rows including app_state and creates or TAKES OVER an admin account '
            . 'with a published password. If the scheduler runs here (cron_mentions_schedule_run: '
            . ($op['cron_mentions_schedule_run'] ? 'YES' : 'no') . '), a customer tenant can be reset nightly. '
            . 'Escalate during the visit — this is a live risk, not a migration finding.',
            ['sites' => $demo['sites']]);
    }

    if ($op['process_query_ran'] && !$op['queue_worker_running']) {
        $c->finding('HIGH', 'env', 'No queue worker process is running',
            'Nothing queued has been delivered. An alert row marked status = \'sent\' does NOT mean the '
            . 'customer received anything (L-… OQ-L-02). Any migration claim about notification history must '
            . 'be qualified, and the customer should be told which notifications never went out.');
    }

    // Queue backlog and failure evidence.
    foreach (['jobs', 'failed_jobs'] as $t) {
        $op[$t . '_count'] = dsc_has_table($c, $t)
            ? (int) dsc_val($c, 'SELECT COUNT(*) FROM ' . dsc_id($t), [], 'd11.q', 0) : null;
    }
    if (($op['failed_jobs_count'] ?? 0) > 0) {
        $c->finding('MEDIUM', 'env', $op['failed_jobs_count'] . ' rows in failed_jobs',
            'Work the application believes it dispatched and never completed. Sample the payload types '
            . 'before assuming any background-produced data is complete.');
    }

    // Scheduler evidence from the cache table, where Laravel stores its schedule mutexes.
    if (dsc_has_cols($c, 'cache', ['key'])) {
        $op['schedule_cache_keys'] = array_column(dsc_q($c,
            "SELECT `key` FROM cache WHERE `key` LIKE '%schedule%' OR `key` LIKE '%framework%'
              ORDER BY `key` LIMIT 50", [], 'd11.cache'), 'key');
    }

    // Backup posture, from .env keys only.
    $op['backup_env_keys'] = array_values(array_filter(array_keys($env),
        static fn($k) => stripos($k, 'BACKUP') !== false));
    $fp['operational'] = $op;

    /* ---- Default / vendor credential accounts ------------------------ */
    $accounts = [];
    if (dsc_has_cols($c, 'users', ['email'])) {
        $uc = array_keys(dsc_cols($c, 'users'));
        $sel = implode(', ', array_map('dsc_id', array_values(array_intersect(
            ['id', 'name', 'email', 'tenant_id', 'status', 'created_at', 'updated_at', 'last_login_at'], $uc))));
        $pwCol = in_array('password', $uc, true) ? 'password' : null;
        $rows = dsc_q($c, "SELECT $sel" . ($pwCol ? ', password' : '') . " FROM users
                            WHERE email IN ('admin@smartprs.local', 'support@ametecsindia.com', 'admin@admin.com')
                               OR email LIKE 'demo-%@smartprs.in'
                               OR email LIKE '%@smartprs.local'
                            ORDER BY id", [], 'd11.users');
        foreach ($rows as $r) {
            $weak = null;
            if ($pwCol && isset($r['password']) && is_string($r['password']) && $r['password'] !== '') {
                // Read-only check against the seeder's published password. The hash is
                // never written to the pack; only the boolean verdict is.
                foreach (['password', 'secret', 'admin123', '12345678'] as $guess) {
                    if (@password_verify($guess, $r['password'])) {
                        $weak = $guess;
                        break;
                    }
                }
            }
            // The account IDENTITY matters to the engineer even in a redacted pack: these are
            // vendor/system accounts, not people. The pattern is recorded; the address is hashed.
            $email = (string) ($r['email'] ?? '');
            $r['matched_pattern'] = $email === 'admin@smartprs.local' ? 'OnPremWorkspaceSeeder default admin'
                : ($email === 'support@ametecsindia.com' ? 'Ametecs support account'
                : (str_starts_with($email, 'demo-') ? 'DemoReset nightly admin'
                : (str_ends_with($email, '@smartprs.local') ? 'vendor .local account' : 'other')));
            unset($r['password']);
            $r['default_password_still_set'] = $weak === null ? 'no' : 'YES';
            $accounts[] = $r;
        }
        dsc_write_csv($c, 'env/default_accounts.csv', $accounts);

        $live = array_values(array_filter($accounts, static fn($r) => $r['default_password_still_set'] === 'YES'));
        if ($live) {
            $c->finding('BLOCKER', 'env', count($live) . ' default credential account(s) still use a published password',
                'OnPremWorkspaceSeeder creates admin@smartprs.local with a published password, and DemoReset '
                . 'creates or takes over an admin account nightly. These accounts are reachable on this install '
                . 'right now. This is the highest-urgency operational item in the dossier — tell the customer '
                . 'during the visit and get it changed before you leave. The password itself is not recorded '
                . 'in this pack.',
                ['accounts' => count($live)]);
        } elseif ($accounts) {
            $c->finding('HIGH', 'env', count($accounts) . ' vendor/default account(s) still exist',
                'Their passwords are not the published defaults, but the accounts themselves should be '
                . 'disabled or owned by a named person before migration.');
        }
    }

    dsc_write_json($c, 'env/fingerprint.json', $fp);
    $c->finding('INFO', 'env',
        'Environment: PHP ' . PHP_VERSION . ', MySQL ' . ($fp['mysql']['version'] ?: '?')
        . ', app ' . (is_array($appFacts['version'] ?? null)
            ? (string) ($appFacts['version']['version'] ?? $appFacts['version']['rev'] ?? 'unknown') : 'unknown'),
        'R-XXIX-031: attach env/fingerprint.json to this install\'s migration record permanently. Date parsing, '
        . 'enum remediation and timezone reinterpretation are all conditional on it and must stay auditable.');

    dsc_finish($c, 'd11_env');
}

/** Run a shell command if the SAPI allows it. @return array{0:string,1:bool} */
function d11_run(string $cmd): array
{
    if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim',
        explode(',', (string) ini_get('disable_functions'))), true)) {
        return ['', false];
    }
    $out = @shell_exec($cmd . ' 2>&1');
    return [(string) $out, $out !== null && $out !== false];
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d11_env.php', 'd11_env');
}
