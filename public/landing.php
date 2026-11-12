<?php

declare(strict_types=1);

/**
 * PUBLIC MARKETING / LANDING PAGE — replicated from SmartPRS2's
 * resources/views/landing.blade.php + app/Http/Controllers/LandingController.php.
 *
 * Deliberately NOT at public/index.php ("/") — that file is SmartPRS3's dev
 * console (localhost/private-LAN only guard, widened 21 Sep 2026) and is left
 * untouched per instruction. This page lives at its own path instead.
 *
 * Content model matches SmartPRS2 exactly: a defaults array (here,
 * config/landing.json, since SmartPRS3 is "change configuration, not code")
 * that the Super Admin's Landing Page (CMS) overrides (platform_settings
 * 'landing', 16 Oct 2026).
 *
 * Links that pointed at SmartPRS2-only features (self-serve /signup with
 * billing, the /demo request flow, the dynamically generated
 * /sample-audit-report.pdf, and the legal/policy page set) are pointed at the
 * nearest SmartPRS3 equivalent or the on-page demo-request form instead —
 * flagged inline with `NOT PORTED` comments rather than left broken.
 */

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

spl_autoload_register(static function (string $class): void {
    foreach (['SmartPRS\\Platform\\' => '/../src/Platform/', 'SmartPRS\\Surface\\' => '/../surface/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $f = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($f)) {
                require_once $f;
            }

            return;
        }
    }
});

// The shipped wording (config/landing.json) under the Super Admin's edits (SaaS Platform → Landing Page
// (CMS), 16 Oct 2026). No database = the shipped wording, never a broken page.
$configPath = __DIR__ . '/../config/landing.json';
$lcSettings = null;

try {
    $lcPdo = \SmartPRS\Surface\Database::connect(__DIR__ . '/../.env');
    $lcSettings = $lcPdo === null ? null : new \SmartPRS\Surface\PlatformSettings(new \SmartPRS\Surface\SqlStore($lcPdo));
} catch (\Throwable $e) {
    $lcSettings = null; // no database: the shipped wording below
}

$c = (new \SmartPRS\Surface\LandingContent($configPath, $lcSettings))->content();

if ($c === []) {
    http_response_code(500);
    exit('Landing page content is missing or invalid (config/landing.json).');
}

$c['footer'] = str_replace('{{YEAR}}', date('Y'), $c['footer'] ?? '');

/**
 * Demo-request lead form (POST). Platform-level data — a visitor is not a
 * tenant yet, so this writes through an UNSCOPED store, the same pattern
 * tools/seed-local-admin.php uses for `tenants`/`users` before a tenant
 * exists (see database/migrations/2026_09_21_000100_create_leads_table.php).
 *
 * No CSRF token: this is a public, pre-session, anonymous form — there is no
 * signed-in Session to issue one against. The hidden `website` honeypot field
 * (copied from SmartPRS2) is the only bot defence, matching the exposure of
 * an ordinary public contact form.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'lead-store') {
    header('Content-Type: application/json');

    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        // Honeypot tripped — pretend success, save nothing.
        echo json_encode(['ok' => true, 'message' => 'Thank you!']);
        exit;
    }

    $required = ['name' => 'Full Name', 'company' => 'Company Name', 'city' => 'City / Location',
        'mobile' => 'Mobile Number', 'email' => 'Official Email ID'];
    $missing = [];

    foreach ($required as $field => $label) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'a valid email';
    }

    if (!empty($missing)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Please add: ' . implode(', ', $missing) . '.']);
        exit;
    }

    $row = [
        'name' => substr(trim((string) $_POST['name']), 0, 120),
        'company' => substr(trim((string) $_POST['company']), 0, 160),
        'designation' => substr(trim((string) ($_POST['designation'] ?? '')), 0, 120) ?: null,
        'city' => substr(trim((string) $_POST['city']), 0, 120),
        'mobile' => substr(trim((string) $_POST['mobile']), 0, 20),
        'email' => substr($email, 0, 160),
        'employees_range' => substr(trim((string) ($_POST['employees'] ?? '')), 0, 40) ?: null,
        'challenges' => substr(trim((string) ($_POST['challenges'] ?? '')), 0, 2000) ?: null,
        'source' => 'landing',
        'created_at' => date('c'),
    ];

    require_once __DIR__ . '/../surface/RowStore.php';
    require_once __DIR__ . '/../surface/Store.php';
    require_once __DIR__ . '/../surface/SqlStore.php';
    require_once __DIR__ . '/../surface/Database.php';

    $saved = false;

    try {
        $pdo = \SmartPRS\Surface\Database::connect(__DIR__ . '/../.env');

        if ($pdo !== null) {
            (new \SmartPRS\Surface\SqlStore($pdo))->put('leads', $row);
            $saved = true;
        }
    } catch (\Throwable $e) {
        $saved = false; // fall through to the file store below
    }

    if (!$saved) {
        try {
            (new \SmartPRS\Surface\Store(__DIR__ . '/../storage'))->put('leads', $row);
            $saved = true;
        } catch (\Throwable $e) {
            $saved = false;
        }
    }

    // LEAD ALERT (SmartPRS 2 rev 91c): the sales mailbox and WhatsApp from the landing content's contact
    // block hear about it at once, through the platform mailbox / Interakt — when those are set up.
    if ($saved && $lcSettings !== null) {
        try {
            $env = __DIR__ . '/../.env';
            $to = (string) ($c['contact']['lead_email'] ?? '');

            if (filter_var($to, FILTER_VALIDATE_EMAIL) !== false) {
                (new \SmartPRS\Surface\PlatformMail($lcSettings, $env))->send([$to], 'New SmartPRS demo request — ' . $row['name'] . ', ' . $row['company'],
                    'A new demo request came in from the website.', ['Name' => $row['name'], 'Company' => $row['company'], 'Designation' => (string) $row['designation'],
                    'City' => $row['city'], 'Mobile' => $row['mobile'], 'Email' => $row['email'], 'Employees' => (string) $row['employees_range'], 'Challenges' => (string) $row['challenges']]);
            }

            if ((string) ($c['contact']['lead_wa'] ?? '') !== '') {
                $wa = new \SmartPRS\Surface\WhatsApp(new \SmartPRS\Surface\SqlStore($lcPdo), $lcSettings, $env);

                if ($wa->ready()) {
                    $wa->send((string) $c['contact']['lead_wa'], $wa->templateFor('lead'),
                        [$row['name'], $row['company'], $row['mobile'], $row['city'], (string) ($row['employees_range'] ?? '-')], 'lead');
                }
            }
        } catch (\Throwable $e) {
            error_log('Lead alert not sent: ' . $e->getMessage()); // the lead itself is saved; the alert is a courtesy
        }
    }

    echo json_encode($saved
        ? ['ok' => true, 'message' => 'Thank you! Your details are saved — our team will reach out shortly.']
        : ['ok' => false, 'message' => 'Could not save your details right now — please try WhatsApp instead.']);
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $seo = (array) ($c['seo'] ?? []); $seoT = (string) ($seo['title'] ?? ($c['brand'] . ' — ' . $c['hero']['badge'])); $seoD = (string) ($seo['description'] ?? $c['hero']['subtitle']); ?>
    <title><?= e($seoT) ?></title>
    <meta name="description" content="<?= e($seoD) ?>">
    <?php if (($seo['url'] ?? '') !== ''): ?><link rel="canonical" href="<?= e((string) $seo['url']) ?>"><meta property="og:url" content="<?= e((string) $seo['url']) ?>"><?php endif; ?>
    <meta property="og:type" content="website"><meta property="og:title" content="<?= e($seoT) ?>"><meta property="og:description" content="<?= e($seoD) ?>">
    <meta property="og:image" content="<?= e(rtrim((string) ($seo['url'] ?? ''), '/') . '/images/screens/hero-dashboard.jpg') ?>"><meta name="twitter:card" content="summary_large_image">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/png" href="images/logo-icon.png">
    <?php $waNo = preg_replace('/\D+/', '', $c['contact']['whatsapp'] ?? ''); ?>
    <style>
        /* LANDING PAGE — Rovexy layout in the SmartPRS 3 palette (owner, 12 Nov 2026). Words come from Super Admin → Landing Page (CMS). */
        :root{--navy:#0a1628;--navy2:#102744;--accent:#f97316;--accent2:#fb923c;--blue:#3b5bdb;--blue2:#60a5fa;
            --bg:#f6f8fb;--card:#fff;--border:#e6ebf2;--text:#0f1d33;--text2:#516074;--text3:#94a3b8;--green:#10b981;--red:#ef4444}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif}
        html{scroll-behavior:smooth}body{color:var(--text);background:#fff;line-height:1.55;-webkit-font-smoothing:antialiased;overflow-x:hidden}
        a{text-decoration:none;color:inherit}img{max-width:100%}
        .wrap{max-width:1200px;margin:0 auto;padding:0 clamp(16px,4vw,28px)}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:13px 24px;border-radius:10px;font-weight:700;font-size:15px;cursor:pointer;border:0;transition:.18s;white-space:nowrap}
        .btn-accent{background:var(--accent);color:#fff;box-shadow:0 10px 26px rgba(249,115,22,.3)}.btn-accent:hover{background:#ea580c;transform:translateY(-1px)}
        .btn-out{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.35)}.btn-out:hover{border-color:#fff;background:rgba(255,255,255,.06)}
        .btn-out-dark{background:#fff;color:var(--navy);border:1.5px solid #cbd5e1}.btn-out-dark:hover{border-color:var(--navy)}
        .eyebrow{color:var(--accent);font-weight:700;font-size:13px;letter-spacing:.3px}
        section{padding:96px 0;scroll-margin-top:60px}.alt{background:var(--bg)}
        .sec-head{max-width:720px;margin:0 0 48px}.sec-head.c{text-align:center;margin:0 auto 48px}
        h2{font-size:clamp(28px,3.6vw,42px);font-weight:800;letter-spacing:-1px;line-height:1.12;margin:10px 0 14px}
        .lead{color:var(--text2);font-size:17px}
        /* nav */
        nav{position:sticky;top:0;z-index:60;background:var(--navy);border-bottom:1px solid rgba(255,255,255,.08)}
        nav .wrap{display:flex;align-items:center;justify-content:space-between;height:68px;gap:20px}
        .navlinks{display:flex;gap:26px;align-items:center;font-size:14.5px;font-weight:500;color:rgba(255,255,255,.78)}.navlinks a:hover{color:#fff}
        .navlinks .btn{padding:9px 18px;font-size:14px}
        .burger{display:none;background:none;color:#fff;border:1.5px solid rgba(255,255,255,.25);border-radius:10px;width:44px;height:44px;font-size:18px;cursor:pointer}
        .mmenu{display:none;flex-direction:column;padding:6px clamp(16px,4vw,28px) 16px;border-top:1px solid rgba(255,255,255,.08)}
        .mmenu a{padding:13px 4px;border-bottom:1px solid rgba(255,255,255,.08);font-weight:600;color:#fff}body.mm .mmenu{display:flex}
        /* hero */
        .hero{background:radial-gradient(ellipse at 15% 0%,#17345c 0,var(--navy2) 35%,var(--navy) 75%);color:#fff;padding:64px 0 80px;overflow:hidden}
        .hero-row{display:grid;grid-template-columns:1fr 1.2fr;gap:44px;align-items:stretch}
        .hero .eyebrow{color:var(--accent2);font-size:14px}
        .hero h1{font-size:clamp(34px,3.9vw,48px);font-weight:800;letter-spacing:-1.6px;line-height:1.06;margin:14px 0 20px}
        .hero p.sub{color:rgba(255,255,255,.75);font-size:18px;margin-bottom:30px;max-width:560px}
        .hero .cta{display:flex;gap:12px;flex-wrap:wrap}
        .ticks{list-style:none;margin-top:26px;display:grid;gap:9px;font-size:14.5px;color:rgba(255,255,255,.75)}
        .ticks li::before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:11px;vertical-align:middle}
        /* console mock */
        .con{position:relative;background:#fff;color:var(--text);border-radius:14px;box-shadow:0 40px 90px -25px rgba(0,0,0,.7);display:grid;grid-template-columns:168px 1fr;overflow:hidden;font-size:12px;min-height:470px}
        .con-side{background:#0c1b30;color:#cbd5e1;padding:12px 8px;display:flex;flex-direction:column;gap:2px}
        .con-brand{display:flex;align-items:center;gap:8px;padding:4px 8px 12px;color:#fff;font-weight:800;font-size:13px}
        .con-brand i{width:24px;height:24px;border-radius:7px;background:var(--accent);display:grid;place-items:center;font-size:11px}
        .con-side button{all:unset;cursor:pointer;display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:7px;font-size:11.5px;color:#cbd5e1}
        .con-side button i{width:14px;text-align:center;color:#64748b}
        .con-side button:hover{background:rgba(255,255,255,.06)}.con-side button.on{background:rgba(249,115,22,.15);color:#fff}.con-side button.on i{color:var(--accent)}
        .con-main{padding:14px 16px;background:#f6f8fb;display:flex;flex-direction:column;gap:10px}
        .con-top{display:flex;justify-content:space-between;align-items:center;color:var(--text3);font-size:11px}
        .con-top b{color:var(--text);font-size:15px;display:block}
        .con-pane{display:none;flex-direction:column;gap:10px}.con-pane.on{display:flex}
        .kp{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
        .kp div{background:#fff;border:1px solid var(--border);border-radius:9px;padding:9px}
        .kp small{display:block;color:var(--text3);font-size:9.5px;text-transform:uppercase;letter-spacing:.4px;font-weight:700}
        .kp b{font-size:18px}.kp em{font-style:normal;font-size:10px;color:var(--green);display:block}
        .panel{background:#fff;border:1px solid var(--border);border-radius:9px;padding:10px 12px}
        .panel h6{font-size:11.5px;margin-bottom:8px;display:flex;justify-content:space-between}.panel h6 span{color:var(--accent);font-weight:600}
        .bars{display:flex;align-items:flex-end;gap:7px;height:150px}
        .bars i{flex:1;border-radius:4px 4px 0 0;background:linear-gradient(180deg,var(--blue2),var(--blue));position:relative}
        .bars i b{position:absolute;bottom:0;left:0;right:0;background:var(--accent);border-radius:0;opacity:.85}
        .rows div{display:grid;grid-template-columns:1.4fr 1fr 1fr .8fr;gap:8px;padding:6px 0;border-top:1px solid var(--border);font-size:11px;align-items:center}
        .rows div:first-child{border-top:0;color:var(--text3);font-weight:700;font-size:9.5px;text-transform:uppercase}
        .pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:9.5px;font-weight:700}
        .pg{background:#dcfce7;color:#15803d}.po{background:#ffedd5;color:#c2410c}.pr{background:#fee2e2;color:#b91c1c}.pb{background:#dbeafe;color:#1d4ed8}
        .con-hint{position:absolute;left:12px;bottom:12px;background:rgba(10,22,40,.9);color:#fff;font-size:11px;font-weight:600;padding:6px 12px;border-radius:20px}
        .toast{position:absolute;right:14px;bottom:14px;background:#fff;border:1px solid var(--border);border-left:3px solid var(--green);border-radius:9px;padding:8px 12px;font-size:11px;box-shadow:0 12px 26px -10px rgba(15,23,42,.35);max-width:230px}
        /* strip */
        .strip{background:var(--navy);color:#fff;padding:26px 0;border-top:1px solid rgba(255,255,255,.06)}
        .strip .wrap{display:flex;justify-content:space-between;align-items:center;gap:24px;flex-wrap:wrap}
        .strip .st{display:flex;gap:40px;flex-wrap:wrap}.strip .st b{font-size:26px;color:var(--accent2);display:block}.strip .st span{font-size:12.5px;color:rgba(255,255,255,.6)}
        .strip .cl{color:rgba(255,255,255,.55);font-size:13px}.strip .cl b{color:#fff;margin-left:14px;font-weight:700}
        /* problem / solution */
        .ps{display:grid;grid-template-columns:1fr 1fr;gap:22px}
        .ps>div{border-radius:16px;padding:28px;border:1px solid var(--border);background:#fff}
        .ps h4{font-size:15px;margin-bottom:14px}.ps ul{list-style:none;display:grid;gap:12px}
        .ps li{display:flex;gap:12px;font-size:15px;color:var(--text2)}.ps li i{margin-top:4px}
        .ps .bad i{color:var(--red)}.ps .good{border-color:rgba(16,185,129,.35);background:linear-gradient(180deg,#f0fdf9,#fff)}.ps .good i{color:var(--green)}
        /* compliance band */
        .band26{background:linear-gradient(135deg,var(--navy),#0e1c30);color:#fff;padding:44px 0}
        .band26 .wrap{display:flex;align-items:center;justify-content:space-between;gap:26px;flex-wrap:wrap}
        .band26 .tag{display:inline-block;background:var(--accent);font-size:12px;font-weight:700;padding:5px 13px;border-radius:30px;margin-bottom:12px}
        .band26 h3{font-size:clamp(22px,2.6vw,30px);font-weight:800;margin-bottom:8px}.band26 p{color:rgba(255,255,255,.72);max-width:660px}
        /* how it works stepper */
        .how{display:grid;grid-template-columns:.9fr 1.1fr;gap:30px;align-items:start}
        .steps{display:grid;gap:8px}
        .steps button{all:unset;cursor:pointer;display:flex;gap:14px;align-items:center;padding:14px 16px;border:1px solid var(--border);border-radius:12px;background:#fff;font-weight:600;font-size:15px}
        .steps button span.n{width:30px;height:30px;border-radius:8px;display:grid;place-items:center;background:var(--bg);color:var(--text2);font-weight:800;font-size:13px;flex:none}
        .steps button small{margin-left:auto;font-size:11px;color:var(--text3);font-weight:600}
        .steps button.on{border-color:var(--accent);box-shadow:0 10px 24px -14px rgba(249,115,22,.6)}.steps button.on span.n{background:var(--accent);color:#fff}
        .step-card{position:sticky;top:90px;background:linear-gradient(160deg,var(--navy),var(--navy2));color:#fff;border-radius:18px;padding:32px;min-height:340px;display:flex;flex-direction:column}
        .step-card .k{font-size:12px;letter-spacing:1px;color:var(--accent2);font-weight:700}
        .step-card h3{font-size:26px;margin:10px 0 12px}.step-card p{color:rgba(255,255,255,.75);font-size:16px}
        .step-card .ic{width:64px;height:64px;border-radius:16px;background:rgba(249,115,22,.15);color:var(--accent2);display:grid;place-items:center;font-size:26px;margin-bottom:18px}
        .step-card .nav2{margin-top:auto;display:flex;justify-content:space-between;align-items:center;padding-top:22px}
        .step-card .nav2 button{all:unset;cursor:pointer;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.25);display:grid;place-items:center}
        .step-card .nav2 a{color:var(--accent2);font-weight:700}
        /* modules */
        .mods{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
        .mod{border:1px solid var(--border);border-radius:14px;padding:20px;background:#fff;transition:.18s}
        .mod:hover{border-color:rgba(249,115,22,.4);transform:translateY(-3px);box-shadow:0 16px 34px -18px rgba(15,29,51,.3)}
        .mod i{width:40px;height:40px;border-radius:11px;background:rgba(249,115,22,.1);color:var(--accent);display:grid;place-items:center;font-size:17px;margin-bottom:12px}
        .mod h3{font-size:15.5px;margin-bottom:6px}.mod p{font-size:13.5px;color:var(--text2)}
        /* about */
        .about{display:grid;grid-template-columns:1.2fr .8fr;gap:40px;align-items:start}
        .about p{color:var(--text2);font-size:16px;margin-bottom:14px}
        .proof{border:1px solid var(--border);border-radius:16px;padding:26px;background:#fff}
        .proof .big{font-size:15px;font-weight:700;margin:10px 0 14px}.proof a{color:var(--accent);font-weight:700}
        .founder{margin-top:20px;padding-left:14px;border-left:3px solid var(--accent)}.founder b{display:block}.founder span{color:var(--text2);font-size:14px}
        /* security */
        .sec{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
        .sec div{border:1px solid var(--border);border-radius:14px;padding:22px;background:#fff}
        .sec i{color:var(--accent);font-size:20px}.sec h4{margin:12px 0 6px;font-size:15.5px}.sec p{font-size:14px;color:var(--text2)}
        /* pricing */
        .prices{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;align-items:stretch}
        .plan{border:1px solid var(--border);border-radius:18px;padding:30px;background:#fff;display:flex;flex-direction:column}
        .plan.hot{border:2px solid var(--accent);position:relative;box-shadow:0 24px 50px -24px rgba(249,115,22,.5)}
        .plan.hot::before{content:'Most teams choose this';position:absolute;top:-13px;left:24px;background:var(--accent);color:#fff;font-size:11.5px;font-weight:700;padding:4px 12px;border-radius:20px}
        .plan h3{font-size:19px}.plan .amt{font-size:38px;font-weight:800;margin:10px 0 2px;letter-spacing:-1px}.plan .amt small{font-size:14px;color:var(--text3);font-weight:500}
        .plan ul{list-style:none;margin:18px 0 22px;display:grid;gap:9px;flex:1}.plan li{display:flex;gap:10px;font-size:14px;color:var(--text2)}.plan li i{color:var(--green);margin-top:4px;font-size:12px}
        .dep-tog{display:inline-flex;background:#fff;border:1px solid var(--border);border-radius:14px;padding:5px;gap:4px;margin:0 auto 30px;box-shadow:0 6px 18px -10px rgba(15,23,42,.25)}
        .dep-wrap{text-align:center}.dep-tog button{all:unset;cursor:pointer;padding:11px 22px;border-radius:10px;font-weight:700;font-size:14.5px;color:var(--text2)}
        .dep-tog button.on{background:var(--navy);color:#fff}.dep-tog small{font-weight:600;opacity:.7;margin-left:4px}
        .prices.p4{grid-template-columns:repeat(4,1fr)}.plan .per{font-size:13px;color:var(--text2);margin-bottom:4px}.plan .per b{color:var(--green)}
        .plan.ask .amt{font-size:28px}.plan ul{align-content:start}.qr-inc{display:inline-flex;gap:6px;align-items:center;font-size:12px;font-weight:700;color:#0f766e;background:#ccfbf1;border-radius:20px;padding:4px 10px;margin-top:8px;width:max-content}
        .pfoot{margin-top:18px;text-align:center;font-size:14px;color:var(--text2)}.pfoot b{color:var(--text)}
        .lic{display:grid;grid-template-columns:1.1fr 1fr 1fr;gap:0;border:2px solid var(--accent);border-radius:20px;background:#fff;overflow:hidden;box-shadow:0 24px 50px -24px rgba(249,115,22,.45)}
        .lic>div{padding:30px}.lic .l1{background:var(--navy);color:#fff}.lic .l1 h3{font-size:20px}.lic .l1 .amt{font-size:40px;font-weight:800;letter-spacing:-1px;margin:12px 0 2px}
        .lic .l1 p{color:rgba(255,255,255,.72);font-size:14px}.lic .l1 .qr-inc{margin-top:14px}
        .lic h4{font-size:12.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--text3);margin-bottom:14px}
        .lic .li{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px dashed var(--border);font-size:14px;color:var(--text2)}.lic .li b{color:var(--text);white-space:nowrap}
        .lic .inc{list-style:none;display:grid;gap:8px;margin-top:14px}.lic .inc li{font-size:13.5px;color:var(--text2);display:flex;gap:8px}.lic .inc i{color:var(--green);margin-top:4px;font-size:12px}
        .lic .cap{margin-top:14px;font-size:12.5px;color:var(--text3)}
        .calc{margin-top:34px;display:grid;grid-template-columns:.8fr 1.2fr;gap:0;border:1px solid var(--border);border-radius:20px;overflow:hidden;background:#fff}
        .calc .ci{padding:28px;background:#f8fafc;border-right:1px solid var(--border)}.calc h3{font-size:19px;margin-bottom:6px}.calc .ci p{font-size:13.5px;color:var(--text2);margin-bottom:18px}
        .calc label{display:block;font-size:13px;font-weight:700;margin:14px 0 6px}.calc input{width:100%;height:46px;border:1px solid var(--border);border-radius:11px;padding:0 14px;font:inherit;font-size:16px;font-weight:700}
        .calc .seg{display:flex;gap:6px}.calc .seg button{all:unset;cursor:pointer;flex:1;text-align:center;padding:11px 8px;border-radius:10px;border:1px solid var(--border);font-weight:700;font-size:13.5px;background:#fff}
        .calc .seg button.on{border-color:var(--accent);color:var(--accent);background:#fff7ed}
        .calc .co{padding:22px 28px}.cq-row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:14px;color:var(--text2)}.cq-row b{color:var(--text);text-align:right}
        .cq-head b{color:var(--accent)}.cq-sum{border-top:1px dashed var(--border)}.calc .seg button small{display:block}.cq-sub{border-top:1px solid var(--border)}.cq-total{font-size:17px;border-bottom:0}.cq-total span,.cq-total b{color:var(--text);font-weight:800}
        .cq-rec{margin-top:10px;background:#f1f5f9;border-radius:10px;padding:12px 14px;display:flex;justify-content:space-between;gap:12px;font-size:13.5px;color:var(--text2);flex-wrap:wrap}.cq-rec b{color:var(--text)}
        .cq-contact{margin-top:14px;font-size:14.5px;color:var(--text2);display:flex;gap:14px;align-items:center;flex-wrap:wrap}
        .calc .fine{font-size:12px;color:var(--text3);margin-top:16px}
        .apps{display:grid;grid-template-columns:repeat(6,1fr);gap:16px}.apps figure{margin:0;text-align:center}
        .apps .ph{background:#0b1220;border-radius:30px;padding:10px;box-shadow:0 24px 40px -22px rgba(2,6,23,.6)}.apps .ph img{display:block;width:100%;aspect-ratio:9/19.5;object-fit:cover;object-position:top;border-radius:22px;background:#fff}
        .apps figcaption{margin-top:10px;font-size:13px;font-weight:700;color:var(--text)}
        .appf{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.appf div{border:1px solid var(--border);border-radius:14px;padding:16px;background:#fff;font-size:14px;color:var(--text2)}.appf i{color:var(--accent);margin-right:8px}
        .app-cta{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:26px}
        @media(max-width:1020px){.prices.p4{grid-template-columns:1fr 1fr}.lic,.calc{grid-template-columns:1fr}.calc .ci{border-right:0;border-bottom:1px solid var(--border)}.apps{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:600px){.prices.p4,.appf{grid-template-columns:1fr !important}.apps{grid-template-columns:1fr 1fr}.dep-tog button{padding:10px 12px;font-size:13.5px}.lic>div,.calc .ci,.calc .co{padding:20px}}
        .onprem{margin-top:22px;border-radius:16px;padding:26px 30px;background:var(--navy);color:#fff;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}
        .onprem p{color:rgba(255,255,255,.72);font-size:14.5px;max-width:620px}.onprem h3{font-size:20px;margin-bottom:6px}
        .onprem .chips span{display:inline-block;border:1px solid rgba(255,255,255,.2);border-radius:9px;padding:8px 14px;margin:0 6px 6px 0;font-size:13.5px;font-weight:700}
        .pnote{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:22px}.pnote div{font-size:13px;color:var(--text2)}.pnote b{display:block;color:var(--text);font-size:14px}
        /* try */
        .try{display:grid;grid-template-columns:.9fr 1.1fr;gap:30px;align-items:start}
        .try ol{list-style:none;display:grid;gap:16px;margin:22px 0 26px;counter-reset:t}
        .try ol li{display:flex;gap:14px;counter-increment:t}.try ol li::before{content:counter(t);width:32px;height:32px;border-radius:50%;background:var(--accent);color:#fff;font-weight:800;display:grid;place-items:center;flex:none}
        .try ol b{display:block}.try ol span{color:var(--text2);font-size:14px}
        .formcard{background:#fff;border:1px solid var(--border);border-radius:18px;padding:26px 28px;box-shadow:0 20px 40px -24px rgba(15,29,51,.25)}
        .formcard h3{font-size:18px;margin-bottom:4px}.formcard .note{color:var(--text3);font-size:13px;margin-bottom:16px}
        .lf label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px}
        .lf input,.lf select,.lf textarea{width:100%;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:9px;font-size:14px;font-family:inherit;background:#f8fafc}
        .lf input:focus,.lf select:focus,.lf textarea:focus{outline:none;border-color:var(--accent);background:#fff}
        /* quotes */
        .quotes{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
        .quote{border:1px solid var(--border);border-radius:16px;padding:26px;background:#fff}.quote .stars{color:#f59e0b;margin-bottom:10px}
        .quote p{font-size:15px}.quote .who{margin-top:16px;font-size:13px;color:var(--text2)}.quote .who b{display:block;color:var(--text)}
        /* faq */
        .fq{display:grid;grid-template-columns:1fr 300px;gap:30px;align-items:start}
        .fq-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
        .fq-chips button{all:unset;cursor:pointer;padding:8px 14px;border-radius:30px;border:1px solid var(--border);font-size:13px;font-weight:600;background:#fff}.fq-chips button.on{background:var(--navy);color:#fff;border-color:var(--navy)}
        .fq-list details{border-bottom:1px solid var(--border)}
        .fq-list summary{list-style:none;cursor:pointer;display:flex;gap:16px;align-items:center;padding:18px 4px;font-weight:700;font-size:16px}
        .fq-list summary::-webkit-details-marker{display:none}.fq-n{color:var(--accent);font-size:13px;width:24px}.fq-q{flex:1}
        .fq-list summary i{color:var(--text3);transition:.2s}.fq-list details[open] summary i{transform:rotate(45deg);color:var(--accent)}
        .fq-list p{color:var(--text2);padding:0 4px 18px 44px;font-size:15px}
        .fq-side{position:sticky;top:90px;background:var(--navy);color:#fff;border-radius:16px;padding:24px}
        .fq-side p{color:rgba(255,255,255,.7);font-size:14px;margin:8px 0 18px}.fq-side .btn{width:100%;margin-bottom:10px}
        /* final */
        .final{background:radial-gradient(ellipse at 80% 0%,#17345c 0,var(--navy2) 40%,var(--navy) 80%);color:#fff;text-align:center;padding:90px 0}
        .final h2{color:#fff}.final p{color:rgba(255,255,255,.75);font-size:17px;margin-bottom:28px}
        .final .cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
        .final .st{display:flex;gap:50px;justify-content:center;margin-top:50px;flex-wrap:wrap}.final .st b{font-size:30px;color:var(--accent2);display:block}.final .st span{color:rgba(255,255,255,.6);font-size:13px}
        .wa-float{position:fixed;right:22px;bottom:22px;z-index:70;background:#25d366;color:#fff;font-weight:700;padding:12px 18px;border-radius:30px;box-shadow:0 12px 26px rgba(37,211,102,.4);display:flex;gap:8px;align-items:center}
        .shots{position:relative;display:flex;flex-direction:column;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 40px 90px -25px rgba(0,0,0,.7)}
        .shots-bar{display:flex;align-items:center;gap:6px;padding:10px 12px;background:#eef2f7;border-bottom:1px solid var(--border);flex-wrap:wrap}
        .shots-bar span{width:10px;height:10px;border-radius:50%;background:#ef4444}.shots-bar span:nth-child(2){background:#f59e0b}.shots-bar span:nth-child(3){background:#10b981;margin-right:8px}
        .shots-bar button{all:unset;cursor:pointer;font-size:12px;font-weight:600;color:var(--text2);padding:5px 11px;border-radius:7px}
        .shots-bar button.on{background:#fff;color:var(--text);box-shadow:0 1px 3px rgba(15,23,42,.15)}
        .shots{align-self:center}.shots-img img{display:none;width:100%;height:auto}.shots-img img.on{display:block}
        @media(max-width:1020px){.shots-img{min-height:0}.shots-img img{position:static;height:auto}}
        .shot-frame{border-radius:14px;overflow:hidden;box-shadow:0 30px 70px -25px rgba(0,0,0,.6);background:#fff}.shot-frame img{display:block;width:100%}
        /* SmartPRS 3 highlights: QR attendance spotlight + configuration grid (owner, 12 Nov 2026) */
        .qr{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center}
        .qr ul{list-style:none;display:grid;gap:14px;margin:22px 0 26px}.qr li{display:flex;gap:12px;font-size:15px;color:var(--text2)}.qr li b{color:var(--text)}
        .qr li i{width:32px;height:32px;border-radius:9px;background:rgba(249,115,22,.1);color:var(--accent);display:grid;place-items:center;flex:none;font-size:14px}
        .qr-vis{position:relative;display:flex;gap:22px;justify-content:center;align-items:center;padding:30px;background:linear-gradient(160deg,var(--navy),var(--navy2));border-radius:20px;min-height:380px}
        .gate{background:#fff;border-radius:14px;padding:18px;text-align:center;width:220px;box-shadow:0 20px 50px -20px rgba(0,0,0,.6)}
        .gate small{display:block;color:var(--text3);font-size:11px;font-weight:700;letter-spacing:.5px}.gate b{display:block;margin:4px 0 12px}
        .qrcode{width:150px;height:150px;margin:0 auto;display:grid;grid-template-columns:repeat(9,1fr);gap:2px}
        .qrcode i{background:var(--navy);border-radius:2px}.qrcode i.w{background:transparent}
        .gate .tick{margin-top:10px;font-size:11.5px;color:var(--text2)}.gate .tick span{color:var(--accent);font-weight:700}
        .phone{width:170px;height:320px;border-radius:26px;background:#0b1220;border:6px solid #1f2a3c;padding:14px 10px;color:#fff;display:flex;flex-direction:column;gap:10px;box-shadow:0 30px 60px -20px rgba(0,0,0,.7)}
        .phone .scan{flex:1;border-radius:14px;border:2px dashed rgba(96,165,250,.6);display:grid;place-items:center;color:var(--blue2);font-size:34px}
        .phone .ok{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.4);color:#6ee7b7;border-radius:10px;padding:8px;font-size:11px;text-align:center}
        .phone .ok b{display:block;color:#fff;font-size:13px}
        .cfg{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
        .cfg div{background:#fff;border:1px solid var(--border);border-radius:14px;padding:20px}
        .cfg i{color:var(--blue);font-size:19px}.cfg h4{margin:10px 0 6px;font-size:15px}.cfg p{font-size:13.5px;color:var(--text2)}
        .cfg-note{margin-top:22px;display:flex;gap:14px;align-items:center;background:var(--navy);color:#fff;border-radius:14px;padding:18px 22px;font-size:15px}
        .cfg-note i{color:var(--accent2);font-size:22px}
        .apps{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:22px}
        .apps div{border:1px solid var(--border);border-radius:14px;padding:20px;background:#fff}.apps i{color:var(--accent);font-size:20px}.apps h4{margin:10px 0 6px}.apps p{font-size:14px;color:var(--text2)}
        @media(max-width:1020px){.qr{grid-template-columns:1fr}.cfg{grid-template-columns:repeat(2,1fr)}.apps{grid-template-columns:1fr}}
        @media(max-width:600px){.cfg{grid-template-columns:1fr}.phone{display:none}.qr-vis{padding:10px;min-height:0;border-radius:14px}}
        @media(max-width:1020px){.hero-row,.how,.about,.try,.fq{grid-template-columns:1fr}.mods{grid-template-columns:repeat(2,1fr)}.sec,.prices,.quotes{grid-template-columns:1fr 1fr}.ps{grid-template-columns:1fr}.step-card,.fq-side{position:static}}
        @media(max-width:820px){.navlinks{display:none}.burger{display:inline-block}.con{grid-template-columns:1fr}.con-side{display:none}.kp{grid-template-columns:1fr 1fr}.pnote{grid-template-columns:1fr 1fr}}
        @media(max-width:600px){section{padding:64px 0}.mods,.sec,.prices,.quotes,.pnote,.lead-fields{grid-template-columns:1fr !important}.con-hint,.toast{display:none}}
    </style>
</head>
<body>
    <nav>
        <div class="wrap">
            <a href="./" class="logo"><img src="images/logo.png" alt="SmartPRS" style="height:40px;width:auto;display:block"></a>
            <div class="navlinks">
                <a href="#how">How it works</a>
                <a href="#product">Product</a>
                <a href="#qr">QR Attendance</a>
                <a href="#customise">Customise</a>
                <a href="#pricing">Pricing</a>
                <a href="#faq">FAQ</a>
                <a href="app.php?r=signin">Sign in</a>
                <a href="demo.php" class="btn btn-out">Explore Demo</a>
                <a href="#try" class="btn btn-accent"><?= e($c['hero']['cta']) ?></a>
            </div>
            <button class="burger" aria-label="Menu" onclick="document.body.classList.toggle('mm')"><i class="fas fa-bars"></i></button>
        </div>
        <div class="mmenu" onclick="document.body.classList.remove('mm')">
            <a href="#how">How it works</a><a href="#product">Product</a><a href="#qr">QR Attendance</a><a href="#app">Mobile App</a><a href="#customise">Customise</a><a href="#security">Security</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a>
            <a href="app.php?r=signin">Sign in</a><a href="demo.php" style="color:var(--accent)">Explore Demo</a><a href="#try"><?= e($c['hero']['cta']) ?> →</a>
        </div>
    </nav>

    <header class="hero">
        <div class="wrap hero-row">
            <div>
                <div class="eyebrow"><?= e($c['hero']['badge']) ?></div>
                <h1><?= e($c['hero']['title']) ?></h1>
                <p class="sub"><?= e($c['hero']['subtitle']) ?></p>
                <div class="cta">
                    <a href="#try" class="btn btn-accent"><?= e($c['hero']['cta']) ?> <i class="fas fa-arrow-right"></i></a>
                    <a href="demo.php" class="btn btn-out"><i class="fas fa-play"></i> Explore Demo</a>
                </div>
                <ul class="ticks">
                    <li>QR check-in / check-out at the gate — no biometric device required</li>
                    <li>Attendance flows straight into payroll — every payslip shows its working</li>
                    <li>Your rules, formulas and approvals — set on screen, not in code</li>
                    <li>PF, ESI, PT, TDS and RBI audit reports, ready to file</li>
                </ul>
            </div>
            <div class="shots" id="shots">
                <div class="shots-bar"><span></span><span></span><span></span>
                    <button class="on" data-s="0">Dashboard</button><button data-s="1">QR Attendance</button><button data-s="2">Offices &amp; gates</button><button data-s="3">Payslips</button></div>
                <div class="shots-img">
                    <img class="on" src="images/screens/hero-dashboard.jpg" alt="SmartPRS dashboard — headcount, present, on leave, absent, workforce by department">
                    <img src="images/screens/hero-qr-dashboard.jpg" alt="SmartPRS QR Attendance dashboard — employees, punches, phones waiting, offices" loading="lazy">
                    <img src="images/screens/hero-qr-gates.jpg" alt="SmartPRS QR Attendance offices and gates — gate per branch with location and scanner link" loading="lazy">
                    <img src="images/screens/hero-payslips.jpg" alt="SmartPRS payslips — gross, deductions and net pay for the month" loading="lazy">
                </div>
            </div>
        </div>
    </header>

    <div class="strip"><div class="wrap">
        <div class="st"><?php foreach ($c['stats'] as $s): ?><div><b><?= e($s['n']) ?></b><span><?= e($s['l']) ?></span></div><?php endforeach; ?></div>
        <div class="cl">Trusted by<?php foreach ($c['clients'] as $cl): ?><b><?= e($cl['name']) ?></b><?php endforeach; ?></div>
    </div></div>

    <section>
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow">Why SmartPRS</div><h2>Payroll gets done. Proof usually doesn't.</h2>
                <p class="lead">When a bank, an auditor or an employee asks how a number was arrived at, the answer has to be on record — not in a spreadsheet on someone's laptop.</p></div>
            <div class="ps">
                <div class="bad"><h4>What most collection floors have today</h4><ul>
                    <li><i class="fas fa-xmark"></i>Attendance on registers and Excel, fixed by hand every month</li>
                    <li><i class="fas fa-xmark"></i>Payroll that takes days and nobody can explain line by line</li>
                    <li><i class="fas fa-xmark"></i>DRA and police verification expiries found during the audit</li>
                    <li><i class="fas fa-xmark"></i>Leave, advances and approvals lost in WhatsApp</li></ul></div>
                <div class="good"><h4>With SmartPRS</h4><ul>
                    <li><i class="fas fa-check"></i>QR, biometric and app punches in one register payroll reads</li>
                    <li><i class="fas fa-check"></i>Payroll in an afternoon, every payslip with its working</li>
                    <li><i class="fas fa-check"></i>Alerts before DRA / PCC lapse; RBI audit report per agent</li>
                    <li><i class="fas fa-check"></i>Every request in one approvals inbox, with an audit trail</li></ul></div>
            </div>
        </div>
    </section>

    <section class="alt" id="qr">
        <div class="wrap qr">
            <div><div class="eyebrow">New in SmartPRS 3.0 · Smart QR Attendance</div><h2>From attendance to payroll — no biometric device required</h2>
                <p class="lead">Put a screen at the gate. It shows a QR code that changes every few seconds. Employees scan it in the SmartPRS3 app — and the punch is checked before it is saved.</p>
                <ul>
                    <li><i class="fas fa-qrcode"></i><div><b>A new code every few seconds.</b> A photo of yesterday's code — or one shared on WhatsApp — is refused.</div></li>
                    <li><i class="fas fa-location-crosshairs"></i><div><b>At the gate, really.</b> GPS distance and accuracy are checked against the branch location you set.</div></li>
                    <li><i class="fas fa-mobile-screen"></i><div><b>Only approved phones.</b> Each phone is activated once with a code; a borrowed phone can't punch for someone else.</div></li>
                    <li><i class="fas fa-door-open"></i><div><b>Every branch, every gate.</b> Main entrance, back gate, warehouse — open the gate screen on any PC or TV, and see each branch's attendance on one dashboard.</div></li>
                    <li><i class="fas fa-calculator"></i><div><b>Straight into payroll.</b> QR punches land in the same attendance register as leave and regularizations, so late cuts, LOP and pay follow automatically.</div></li>
                    <li><i class="fas fa-clock-rotate-left"></i><div><b>History for everyone.</b> Each employee sees their own days, in and out times and late minutes in the app; HR sees the full ledger.</div></li>
                    <li><i class="fas fa-plug"></i><div><b>Already have biometric or SmartEPT?</b> One source per company, so nothing is counted twice; unmatched punches wait for you to match.</div></li>
                </ul>
                <div style="display:flex;gap:10px;flex-wrap:wrap"><a href="demo.php" class="btn btn-accent"><i class="fas fa-play"></i> See it in the demo</a><span class="qr-inc" style="display:inline-flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;color:#0f766e;background:#ccfbf1;border-radius:20px;padding:8px 14px">Included in Cloud and On-Premises — no extra licence</span></div>
            </div>
            <div class="qr-vis">
                <div class="shot-frame" style="flex:1"><img src="images/screens/qr-gates.jpg" alt="SmartPRS QR Attendance — offices and gates" loading="lazy"></div>
                <div class="phone" style="position:absolute;right:18px;bottom:-24px;transform:scale(.8);transform-origin:bottom right" aria-hidden="true"><div style="font-size:12px;font-weight:700">SmartPRS3</div><div class="scan"><i class="fas fa-qrcode"></i></div>
                    <div class="ok"><b>Punched in · 09:02</b>Main entrance · 18 m away</div></div>
            </div>
        </div>
    </section>

    <?php
    // MOBILE APP SHOWCASE (owner, 12 Nov 2026): only genuine screenshots — a screen is shown only when its file is in public/images/app/.
    $ap = (array) ($c['app'] ?? []);
    $apShots = array_values(array_filter((array) ($ap['screens'] ?? []), static fn (array $x): bool => is_file(__DIR__ . '/' . (string) ($x['file'] ?? ''))));
    ?>
    <section id="app">
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow"><?= e((string) ($ap['eyebrow'] ?? '')) ?></div><h2><?= e((string) ($ap['title'] ?? '')) ?></h2>
                <p class="lead"><?= e((string) ($ap['lead'] ?? '')) ?></p></div>
            <?php if ($apShots !== []): ?>
            <div class="apps"><?php foreach ($apShots as $x): ?><figure><div class="ph"><img src="<?= e((string) $x['file']) ?>" alt="SmartPRS mobile app — <?= e((string) $x['caption']) ?>" loading="lazy"></div><figcaption><?= e((string) $x['caption']) ?></figcaption></figure><?php endforeach; ?></div>
            <?php else: ?>
            <div class="appf"><div><i class="fas fa-house"></i>Employee dashboard</div><div><i class="fas fa-qrcode"></i>QR attendance scanner</div><div><i class="fas fa-calendar-check"></i>Attendance history</div>
                <div><i class="fas fa-umbrella-beach"></i>Leave — apply and track</div><div><i class="fas fa-file-invoice"></i>Payslips</div><div><i class="fas fa-user"></i>Employee self-service &amp; approvals</div></div>
            <?php endif; ?>
            <div class="app-cta"><?php if (($ap['play_url'] ?? '') !== ''): ?><a href="<?= e((string) $ap['play_url']) ?>" target="_blank" rel="noopener" class="btn btn-accent"><i class="fab fa-google-play"></i> Get it on Google Play</a><?php else: ?><a href="#contact" class="btn btn-accent"><i class="fab fa-android"></i> Android app — ask us for access</a><?php endif; ?>
                <a href="demo.php" class="btn btn-out-dark"><i class="fas fa-play"></i> Try it in the demo</a></div>
        </div>
    </section>

    <section id="customise">
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow">Change configuration, not code</div><h2>Shaped to your company — from the screen</h2>
                <p class="lead">Your HR policy, salary formulas, approvals and documents are settings you change yourself. No developer, no new version, no waiting.</p></div>
            <div class="cfg">
                <div><i class="fas fa-table-list"></i><h4>Custom fields</h4><p>Add the fields your company needs to employees and registers; they show on screens, imports and exports.</p></div>
                <div><i class="fas fa-calculator"></i><h4>Salary structures &amp; formulas</h4><p>Components, formulas and rules by role, grade or company — Government PF/ESI/PT rules applied for you.</p></div>
                <div><i class="fas fa-user-lock"></i><h4>Roles &amp; permissions</h4><p>Tick what each role can view, add, edit, approve or export — and how far: own, team, branch or company.</p></div>
                <div><i class="fas fa-diagram-project"></i><h4>Approval chains</h4><p>Who approves leave, advances, regularizations and payroll — in your order, with your limits.</p></div>
                <div><i class="fas fa-clock"></i><h4>Shifts, late &amp; leave rules</h4><p>Named shifts, night shifts, grace and late-cut policy, leave types, accruals and holidays per branch.</p></div>
                <div><i class="fas fa-file-invoice"></i><h4>Payslip designer</h4><p>Your layout, your letterhead, your columns — per company.</p></div>
                <div><i class="fas fa-file-signature"></i><h4>HR letter templates</h4><p>Offer, appointment, increment, warning and relieving letters — filled from the employee record and sent by email.</p></div>
                <div><i class="fas fa-bell"></i><h4>Alerts by email &amp; WhatsApp</h4><p>Choose which events alert whom — birthdays, expiries, approvals, absences.</p></div>
            </div>
            <div class="cfg-note"><i class="fas fa-clock-rotate-left"></i><div>Every change is saved as a new version with who changed it and when — nothing is ever overwritten, and every number on screen can show how it was worked out.</div></div>
            <div class="apps">
                <div><i class="fas fa-mobile-screen-button"></i><h4>One mobile app for everyone</h4><p>Punch, see your own attendance and payslips, apply for leave — and managers approve on the go. Menus follow each person's role.</p></div>
                <div><i class="fas fa-building"></i><h4>Many companies, one login</h4><p>Group companies, branches and departments with their own branding, statutory codes and payroll — linked everywhere.</p></div>
                <div><i class="fas fa-inbox"></i><h4>One approvals inbox</h4><p>Every request waiting on you in one list, with the bell and email telling you the moment it arrives.</p></div>
            </div>
        </div>
    </section>

    <div class="band26"><div class="wrap">
        <div><span class="tag"><i class="fas fa-bullhorn"></i> Effective 1 October 2026</span>
            <h3>Recovery Compliance 2026 — is your agency audit-ready?</h3>
            <p>New RBI recovery-conduct rules for collection &amp; recovery agencies. See all 45 payroll &amp; HRMS topics every agency must follow — and how SmartPRS already delivers them.</p></div>
        <div class="cta" style="display:flex;gap:12px;flex-wrap:wrap">
            <a href="recovery-compliance-2026.html" class="btn btn-accent"><i class="fas fa-book-open"></i> Read the Manual</a>
            <a href="SmartPRS-Recovery-Compliance-2026-Manual.pdf" download class="btn btn-out"><i class="fas fa-download"></i> Download PDF</a>
        </div>
    </div></div>

    <section class="alt" id="how">
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow">How it works</div><h2>One month, start to finish</h2>
                <p class="lead">Follow one real pay cycle in SmartPRS, from setting up the company to the money in the bank. Pick a step.</p></div>
            <div class="how">
                <div class="steps" id="steps">
                    <button class="on" data-i="0"><span class="n">1</span>Set up the company<small>Admin</small></button>
                    <button data-i="1"><span class="n">2</span>Add people and phones<small>Admin</small></button>
                    <button data-i="2"><span class="n">3</span>Punch in — QR, biometric or app<small>Employee</small></button>
                    <button data-i="3"><span class="n">4</span>Leave and approvals<small>Manager</small></button>
                    <button data-i="4"><span class="n">5</span>Generate payroll<small>HR</small></button>
                    <button data-i="5"><span class="n">6</span>Pay and file<small>Finance</small></button>
                </div>
                <div class="step-card" id="stepCard"><div class="ic"><i class="fas fa-building"></i></div><div class="k">STEP 01 / 06</div><h3></h3><p></p>
                    <div class="nav2"><div style="display:flex;gap:8px"><button type="button" data-d="-1">‹</button><button type="button" data-d="1">›</button></div><a href="demo.php">Try it yourself →</a></div></div>
            </div>
        </div>
    </section>

    <section id="product">
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow">Product · <?= count($c['features']) ?> modules</div><h2>Every HR function, one platform</h2>
                <p class="lead">From hiring and attendance to statutory payroll, field-force compliance and analytics — built in, and set up by configuration, not code.</p></div>
            <div class="mods">
                <?php foreach ($c['features'] as $f): ?>
                    <div class="mod"><i class="fas fa-<?= e($f['icon']) ?>"></i><h3><?= e($f['title']) ?></h3><p><?= e($f['desc']) ?></p></div>
                <?php endforeach; ?>
            </div>
            <p class="lead" style="margin-top:22px;font-size:14.5px">Also included: company branding on every screen and document, roles and permissions down to each button, and a tamper-evident audit log. Works with SmartEPT, ROVEXY and SmartDCM.</p>
        </div>
    </section>

    <section class="alt" id="about">
        <div class="wrap about">
            <div><div class="eyebrow"><?= e($c['about']['eyebrow']) ?></div><h2><?= e($c['about']['title']) ?></h2>
                <?php foreach (preg_split('/\n{2,}/', $c['about']['body']) as $p): ?><p><?= nl2br(e($p)) ?></p><?php endforeach; ?>
                <div class="founder"><b><?= e($c['about']['founder']) ?></b><span><?= e($c['about']['founder_role']) ?></span></div>
            </div>
            <?php if (!empty($c['about']['proof'])): ?>
            <div class="proof"><i class="fas fa-circle-check" style="color:var(--green);font-size:22px"></i>
                <div class="big"><?= e($c['about']['proof']) ?></div>
                <?php if (!empty($c['about']['proof_url'])): ?><a href="<?= e($c['about']['proof_url']) ?>" target="_blank" rel="noopener"><?= e($c['about']['proof_label'] ?? 'Know more') ?> <i class="fas fa-arrow-up-right-from-square" style="font-size:.8rem"></i></a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section id="security">
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow">Security</div><h2>Records you can stand behind</h2>
                <p class="lead">Salaries, IDs and bank details are sensitive. SmartPRS is built around that, so you can show an auditor exactly who did what.</p></div>
            <div class="sec">
                <div><i class="fas fa-building-lock"></i><h4>Your company, your data</h4><p>One company never sees another's people or payroll.</p></div>
                <div><i class="fas fa-user-shield"></i><h4>Access by role</h4><p>Managers see only their team; permissions decide who views, edits or approves.</p></div>
                <div><i class="fas fa-mobile-screen"></i><h4>Approved phones only</h4><p>Each phone is activated before anyone punches or signs in on it.</p></div>
                <div><i class="fas fa-clipboard-check"></i><h4>Audit log</h4><p>Every approval, change and export — with who and when.</p></div>
                <div><i class="fas fa-clock-rotate-left"></i><h4>Nothing overwritten</h4><p>Corrections are new versions; the old figure is always on record.</p></div>
                <div><i class="fas fa-server"></i><h4>Cloud or on-premise</h4><p>Run it on our cloud, or on your own server with a perpetual licence.</p></div>
            </div>
        </div>
    </section>

    <?php
    // SMARTPRS 3.0 PRICING (owner, 12 Nov 2026): figures from config/landing.json "pricing", GST rate from config/gst.json.
    $pr = (array) ($c['pricing'] ?? []);
    $prGst = (int) ((json_decode((string) @file_get_contents(__DIR__ . '/../config/gst.json'), true) ?: [])['rate_percent'] ?? 18);
    $inr = static fn (int|float $v): string => '₹' . preg_replace('/(\d+?)(?=(\d\d)+(\d)(?!\d))/', '$1,', (string) (int) round($v));
    $prOn = (array) ($pr['onprem'] ?? []);
    $prOb = (array) ($pr['onboarding'] ?? []);
    $prObText = $inr((int) ($prOb['base'] ?? 0)) . (($prOb['mode'] ?? '') === 'max' ? ' minimum, or ' : ' + ') . $inr((int) ($prOb['per_employee'] ?? 0)) . ' per employee';
    $prAmc = (int) round(((int) ($prOn['licence'] ?? 0)) * ((int) ($prOn['amc_percent'] ?? 0)) / 100);
    $prPacks = (array) ($pr['cloud']['packages'] ?? []);
    ?>
    <section class="alt" id="pricing" data-gst="<?= $prGst ?>">
        <script type="application/json" id="pricing-data"><?= json_encode($pr, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow">Pricing · SmartPRS 3.0</div><h2>Simple, scalable plans</h2>
                <p class="lead">All modules and QR Code Attendance in every plan — pricing changes only with your team size. Pay 12 months in advance and save 25%. Or own it outright with a lifetime On-Premises licence.</p></div>
            <div class="dep-wrap"><div class="dep-tog" role="tablist">
                <button type="button" data-dep="onprem" role="tab" class="on">On-Premises <small>(Default)</small></button>
                <button type="button" data-dep="cloud" role="tab">Cloud Service</button></div></div>

            <div data-panel="onprem">
                <div class="lic">
                    <div class="l1"><h3>Lifetime On-Premises Licence</h3><div class="amt"><?= $inr((int) ($prOn['licence'] ?? 0)) ?></div><p>one-time licence fee + GST · runs on your own infrastructure</p>
                        <div class="qr-inc"><i class="fas fa-qrcode"></i> QR Code Attendance included</div></div>
                    <div><h4>One-time charges</h4>
                        <div class="li"><span>Lifetime licence</span><b><?= $inr((int) ($prOn['licence'] ?? 0)) ?></b></div>
                        <div class="li"><span>Installation</span><b><?= $inr((int) ($prOn['installation'] ?? 0)) ?></b></div>
                        <div class="li"><span>Setup &amp; onboarding</span><b><?= e($prObText) ?></b></div>
                        <ul class="inc"><li><i class="fas fa-check"></i>All modules, QR attendance and the mobile app</li><li><i class="fas fa-check"></i>Deployed on the customer's own infrastructure</li></ul></div>
                    <div><h4>Recurring</h4>
                        <div class="li"><span>Annual Maintenance (AMC) — <?= (int) ($prOn['amc_percent'] ?? 0) ?>% of licence</span><b><?= $inr($prAmc) ?> / year</b></div>
                        <?php if (!empty($prOn['amc_first_year_included'])): ?><p class="cap">The first 12 months of maintenance come with the licence; AMC is billed yearly after that (<a href="licence-agreement" style="color:var(--accent)">Licence Agreement</a>).</p><?php endif; ?>
                        <ul class="inc"><li><i class="fas fa-check"></i>Product and statutory updates</li><li><i class="fas fa-check"></i>Remote support</li></ul>
                        <p class="cap"><?= e((string) ($prOn['capacity_note'] ?? '')) ?> GST extra on every line.</p>
                        <a href="#contact" class="btn btn-accent" style="margin-top:14px;width:100%;justify-content:center">Get a quotation</a></div>
                </div>
            </div>

            <div data-panel="cloud" hidden>
                <?php $prCyc = (array) ($pr['cloud']['cycles'] ?? []); $prCo = (int) ($pr['cloud']['company_fee'] ?? 0); ?>
                <div class="prices">
                    <?php foreach ($prPacks as $p): $hot = !empty($p['highlight']); $pe = (int) $p['employees']; ?>
                    <div class="plan<?= $hot ? ' hot' : '' ?>"><h3><?= e((string) $p['name']) ?></h3>
                        <div class="amt"><?= $inr((int) $p['monthly']) ?><small>/mo + GST</small></div>
                        <ul><li><i class="fas fa-check"></i>Up to <?= $pe ?> employees</li><li><i class="fas fa-check"></i>All modules included</li>
                            <li><i class="fas fa-check"></i>QR Code Attendance included</li>
                            <li><i class="fas fa-check"></i>Extra employee <?= $inr((int) $p['extra']) ?>/mo</li><li><i class="fas fa-check"></i>Extra company <?= $inr($prCo) ?>/mo</li>
                            <?php foreach ($prCyc as $cy): ?><li><i class="fas fa-check"></i><?= (int) $cy['months'] === (int) ($prCyc[0]['months'] ?? 0) ? 'Minimum ' . (int) $cy['months'] . ' months advance' : (int) $cy['months'] . ' months payment' ?> — <?= (int) $cy['discount'] ?>% discount</li><?php endforeach; ?>
                            <li><i class="fas fa-check"></i>Setup &amp; onboarding <?= e($prObText) ?></li></ul>
                        <a href="signup.php?plan=<?= rawurlencode((string) $p['name']) ?>" class="btn <?= $hot ? 'btn-accent' : 'btn-out-dark' ?>" style="width:100%;justify-content:center">Get started</a></div>
                    <?php endforeach; ?>
                </div>
                <p class="pfoot"><b><?= e((string) ($pr['cloud']['above_label'] ?? '')) ?>?</b> <a href="#contact" style="color:var(--accent);font-weight:700"><?= e((string) ($pr['cloud']['above_text'] ?? '')) ?></a> for a custom rate. Prices exclude GST.</p>
            </div>

            <div class="calc" id="calc">
                <div class="ci"><h3>Pricing calculator</h3><p>Pick a deployment and your number of employees — every line is shown.</p>
                    <label>Deployment</label><div class="seg"><button type="button" data-dep="onprem" class="on">On-Premises</button><button type="button" data-dep="cloud">Cloud Service</button></div>
                    <label for="calc-emp">Total employees</label><input id="calc-emp" type="number" min="1" step="1" value="25" inputmode="numeric">
                    <div data-cloud-only hidden>
                        <label>Plan</label><div class="seg"><?php foreach ($prPacks as $p): ?><button type="button" data-plan="<?= e((string) $p['name']) ?>"><?= e((string) $p['name']) ?></button><?php endforeach; ?></div>
                        <label for="calc-co">Companies in your group</label><input id="calc-co" type="number" min="1" step="1" value="1" inputmode="numeric">
                        <label>Advance period</label><div class="seg"><?php foreach ((array) ($pr['cloud']['cycles'] ?? []) as $cy): ?><button type="button" data-months="<?= (int) $cy['months'] ?>"><?= e((string) $cy['label']) ?><br><small style="font-weight:600;opacity:.75"><?= (int) $cy['months'] ?> mo<?= (int) $cy['discount'] ? ' · ' . (int) $cy['discount'] . '% off' : '' ?></small></button><?php endforeach; ?></div>
                    </div>
                    <p class="fine">Same calculation as checkout: plan + extra employees + extra companies, × the advance period, less its discount, + setup &amp; onboarding (not discounted), + GST. Figures in Indian Rupees. GST at <?= $prGst ?>% (CGST + SGST in Telangana, IGST elsewhere — same total). Your quotation is final.</p></div>
                <div class="co" id="calc-out" aria-live="polite"></div>
            </div>
        </div>
    </section>

    <section id="try">
        <div class="wrap try">
            <div><div class="eyebrow">Explore Demo · free for 7 days</div><h2>Try it with your own company</h2>
                <p class="lead">Your own demo company with two branches, teams, two months of attendance, leave and payroll — every feature switched on. Add your own people and test anything.</p>
                <ol><li><div><b>Tell us who you are</b><span>Name, company, mobile and email.</span></div></li>
                    <li><div><b>Enter the passkey</b><span>Sent to your email and WhatsApp.</span></div></li>
                    <li><div><b>Explore</b><span>Sign in as Admin, Manager, Team Lead or Agent and see it the way they would.</span></div></li></ol>
                <div style="display:flex;gap:12px;flex-wrap:wrap"><a href="demo.php" class="btn btn-accent"><i class="fas fa-play"></i> Explore Demo</a>
                    <a href="https://wa.me/<?= e($waNo) ?>" target="_blank" rel="noopener" class="btn btn-out-dark"><i class="fab fa-whatsapp" style="color:#25d366"></i> Talk to sales</a></div>
                <p style="margin-top:18px;color:var(--text2);font-size:14px">Prefer a person? <?= e($c['contact']['phone']) ?> · <a href="mailto:<?= e($c['contact']['email']) ?>" style="color:var(--accent)"><?= e($c['contact']['email']) ?></a></p>
            </div>
            <div class="formcard" id="contact"><h3>Book a live demo</h3><div class="note">We'll walk your team through SmartPRS on a call — SaaS or on-premise.</div>
    <form id="leadForm" onsubmit="return spLeadSubmit(event)">
                        <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="lead-fields">
                            <div class="lf"><label>Full Name*</label><input name="name" required maxlength="120"></div>
                            <div class="lf"><label>Company Name*</label><input name="company" required maxlength="160"></div>
                            <div class="lf"><label>Designation</label><input name="designation" maxlength="120"></div>
                            <div class="lf"><label>City / Location*</label><input name="city" required maxlength="120"></div>
                            <div class="lf"><label>Mobile Number*</label><input name="mobile" required maxlength="20" inputmode="tel"></div>
                            <div class="lf"><label>Official Email ID*</label><input name="email" type="email" required maxlength="160"></div>
                            <div class="lf" style="grid-column:1/-1;"><label>Number of Employees</label>
                                <select name="employees">
                                    <option value="">Select</option>
                                    <option>1 - 25</option>
                                    <option>26 - 75</option>
                                    <option>76 - 150</option>
                                    <option>More than 150</option>
                                </select>
                            </div>
                            <div class="lf" style="grid-column:1/-1;"><label>What challenges are you facing today?</label>
                                <textarea name="challenges" rows="3" maxlength="2000" placeholder="Example: payroll taking days, attendance fraud, DRA/PCC expiries slipping, incentive disputes, multiple companies on spreadsheets…"></textarea>
                            </div>
                        </div>
                        <p style="font-size:12px;color:#64748b;margin:12px 0 16px;">By submitting this form, you agree to be contacted by Ametecs India for a SmartPRS demo and related communication.</p>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            <button type="submit" class="btn btn-accent" id="leadBtn"><i class="fas fa-calendar-check"></i> Schedule My Demo</button>
                            <a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $c['contact']['whatsapp'] ?? '')) ?>?text=<?= urlencode('Hi! I would like a SmartPRS demo for my company.') ?>" onclick="return spLeadWa(this)" target="_blank" rel="noopener" class="btn" style="background:#25d366;color:#fff;justify-content:center;"><i class="fab fa-whatsapp"></i> Chat on WhatsApp</a>
                        </div>
                        <div id="leadMsg" style="display:none;margin-top:14px;padding:12px 14px;border-radius:10px;font-size:14px;"></div>
                    </form>
            </div>
        </div>
        <script>
            var SP_WA = '<?= e(preg_replace('/\D+/', '', $c['contact']['whatsapp'] ?? '')) ?>';
            function spLeadCollect(){
                var f=document.getElementById('leadForm'), d={};
                new FormData(f).forEach(function(v,k){ d[k]=String(v||'').trim(); });
                return d;
            }
            function spLeadValidate(d){
                var required={name:'Full Name',company:'Company Name',city:'City / Location',mobile:'Mobile Number',email:'Official Email ID'};
                var miss=[];
                Object.keys(required).forEach(function(k){ if(!d[k]) miss.push(required[k]); });
                if(d.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(d.email)) miss.push('a valid email');
                if(d.mobile && d.mobile.replace(/\D/g,'').length<10) miss.push('a 10-digit mobile');
                return miss;
            }
            function spLeadMsg(html,bad){
                var msg=document.getElementById('leadMsg');
                var base='display:block;margin-top:14px;padding:12px 14px;border-radius:10px;font-size:14px;';
                msg.style.cssText=base+(bad?'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;':'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;');
                msg.innerHTML=html;
            }
            function spLeadWaUrl(d){
                var lines=['Hi! I would like a SmartPRS demo for my company.'];
                if(d.name) lines.push('Name: '+d.name+(d.designation?' ('+d.designation+')':''));
                if(d.company) lines.push('Company: '+d.company);
                if(d.city) lines.push('City: '+d.city);
                if(d.mobile) lines.push('Mobile: '+d.mobile);
                if(d.email) lines.push('Email: '+d.email);
                if(d.employees) lines.push('Employees: '+d.employees);
                if(d.challenges) lines.push('Challenges: '+d.challenges);
                return 'https://wa.me/'+SP_WA+'?text='+encodeURIComponent(lines.join('\n'));
            }
            function spLeadSave(d){
                if(window.__leadSaved) return;
                if(!(d.name&&d.company&&d.city&&d.mobile&&d.email)) return;
                window.__leadSaved=true;
                fetch('landing.php?action=lead-store',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d).toString()}).catch(function(){});
            }
            function spLeadWa(a){
                var d=spLeadCollect(), miss=spLeadValidate(d);
                if(miss.length){
                    spLeadMsg('<i class="fas fa-circle-exclamation"></i> Please fill your details first so we receive them on WhatsApp — add: '+miss.join(', ')+'.',true);
                    var fld=document.querySelector('#leadForm [name="name"]'); if(fld) fld.focus();
                    return false;
                }
                window.open(spLeadWaUrl(d),'_blank','noopener');
                spLeadSave(d);
                spLeadMsg('<i class="fab fa-whatsapp"></i> Opening WhatsApp with your details — tap Send and our team will reply shortly.',false);
                return false;
            }
            function spLeadSubmit(ev){
                ev.preventDefault();
                var d=spLeadCollect(), miss=spLeadValidate(d);
                if(miss.length){ spLeadMsg('<i class="fas fa-circle-exclamation"></i> Please add: '+miss.join(', ')+'.',true); return false; }
                window.open(spLeadWaUrl(d),'_blank','noopener');
                var btn=document.getElementById('leadBtn'); btn.disabled=true; btn.style.opacity='.6';
                fetch('landing.php?action=lead-store',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d).toString()})
                .then(function(r){return r.json().then(function(j){return {s:r.status,j:j};},function(){return {s:r.status,j:null};});})
                .then(function(res){
                    btn.disabled=false; btn.style.opacity='1'; window.__leadSaved=true;
                    var okmsg=(res.j&&res.j.message)||'Thank you! Your details are saved — tap Send on WhatsApp to confirm and our team will reply.';
                    spLeadMsg('<i class="fas fa-circle-check"></i> '+okmsg,false);
                    if(res.s===200&&res.j&&res.j.ok){ document.getElementById('leadForm').reset(); }
                }).catch(function(){
                    btn.disabled=false; btn.style.opacity='1';
                    spLeadMsg('<i class="fab fa-whatsapp"></i> Opening WhatsApp with your details — tap Send and our team will help you right away.',false);
                });
                return false;
            }
        </script>
    </section>

    <section class="alt" id="testimonials">
        <div class="wrap">
            <div class="sec-head c"><div class="eyebrow">Customer stories</div><h2>Teams already running on SmartPRS</h2></div>
            <div class="quotes">
                <div class="quote"><div class="stars">★★★★★</div><p>"Payroll that used to take us three days now closes in an afternoon — and the statutory files are ready to file."</p><div class="who"><b>Operations Head</b>Exon Recovery Services</div></div>
                <div class="quote"><div class="stars">★★★★★</div><p>"DRA and PCC expiries used to slip through. Now we get alerts before they lapse — a huge compliance relief."</p><div class="who"><b>Compliance Manager</b>Numero Uno Financial</div></div>
                <div class="quote"><div class="stars">★★★★★</div><p>"Running all our group companies from one platform, with one login, changed how we operate."</p><div class="who"><b>Managing Director</b>Storm Collections</div></div>
            </div>
        </div>
    </section>

    <section id="faq">
        <div class="wrap">
            <div class="sec-head"><div class="eyebrow">FAQ</div><h2>Questions HR and collection heads ask us</h2><p class="lead">Straight answers on payroll, attendance, compliance and pricing.</p></div>
            <div class="fq">
                <div>
<div class="fq-chips"><button type="button" class="on" data-fq="all">All</button>
<button type="button" data-fq="c0">Platform &amp; modules</button>
<button type="button" data-fq="c1">Payroll &amp; compliance</button>
<button type="button" data-fq="c2">Attendance &amp; field force</button>
<button type="button" data-fq="c3">RBI 2026 &amp; audit reports</button>
<button type="button" data-fq="c4">Deployment, security &amp; pricing</button>
</div><div class="fq-list">
<details data-cat="c0" open><summary><span class="fq-n">01</span><span class="fq-q">What does SmartPRS cover?</span><i class="fas fa-plus"></i></summary><p>Sixteen modules across hiring, people, attendance, leave, payroll, compensation, statutory compliance, performance, learning, HR letters, field force, communication, reports, administration and the SaaS platform — in one system.</p></details>
<details data-cat="c0"><summary><span class="fq-n">02</span><span class="fq-q">Can it run multiple companies?</span><i class="fas fa-plus"></i></summary><p>Yes. A single tenant manages many companies, with a group view plus per-company data, branding and payroll.</p></details>
<details data-cat="c0"><summary><span class="fq-n">03</span><span class="fq-q">Is it built specifically for collections &amp; recovery?</span><i class="fas fa-plus"></i></summary><p>Yes — on-roll and off-roll agents, field operations and RBI / DRA / PCC obligations are first-class, not bolted on.</p></details>
<details data-cat="c0"><summary><span class="fq-n">04</span><span class="fq-q">Can modules be limited per plan or company?</span><i class="fas fa-plus"></i></summary><p>Yes. Module access can be gated by subscription plan, and within that every screen follows each user's role.</p></details>
<details data-cat="c1"><summary><span class="fq-n">05</span><span class="fq-q">Is it compliant with Indian payroll statutes?</span><i class="fas fa-plus"></i></summary><p>Yes: PF, ESI, Professional Tax and TDS, with statutory-ready outputs including PF ECR and ESI challans, configurable to your rates.</p></details>
<details data-cat="c1"><summary><span class="fq-n">06</span><span class="fq-q">How is field-force compliance handled?</span><i class="fas fa-plus"></i></summary><p>We track DRA certification, police verification (PCC) and agent authorisations, with alerts before anything expires.</p></details>
<details data-cat="c1"><summary><span class="fq-n">07</span><span class="fq-q">How are incentives &amp; commissions managed?</span><i class="fas fa-plus"></i></summary><p>A built-in engine computes payouts on a collected, target or manual basis (flat, slab or portfolio formulas) and folds them into payroll.</p></details>
<details data-cat="c1"><summary><span class="fq-n">08</span><span class="fq-q">Is sensitive data protected?</span><i class="fas fa-plus"></i></summary><p>Role-based access, full audit trails and DPDP-2023-aligned handling of employee and borrower data.</p></details>
<details data-cat="c2"><summary><span class="fq-n">09</span><span class="fq-q">Does it support biometric devices?</span><i class="fas fa-plus"></i></summary><p>Yes — ZKTeco LAN sync, cloud services like eTimeOffice, or a universal CSV punch upload, plus geo-fenced in-app punch for field agents. Everything lands in one register that payroll reads.</p></details>
<details data-cat="c2"><summary><span class="fq-n">10</span><span class="fq-q">Can teams work different shifts — including night?</span><i class="fas fa-plus"></i></summary><p>Yes. Define named working shifts (General / Morning / Night), set a default per employee or roster day-wise. Night shifts cross midnight correctly and can pay an automatic per-night allowance in payroll.</p></details>
<details data-cat="c2"><summary><span class="fq-n">11</span><span class="fq-q">How does it stop attendance fraud?</span><i class="fas fa-plus"></i></summary><p>Geo-fenced selfie / GPS punch ties every attendance mark to a real location and time.</p></details>
<details data-cat="c2"><summary><span class="fq-n">12</span><span class="fq-q">Can it manage off-roll / commission agents?</span><i class="fas fa-plus"></i></summary><p>Yes — engage vendor / off-roll agents with full KYC (photo, ID, PAN, DRA, PCC, bank), kept separate from payroll employees.</p></details>
<details data-cat="c3"><summary><span class="fq-n">13</span><span class="fq-q">What changes on 1 October 2026?</span><i class="fas fa-plus"></i></summary><p>RBI's recovery-conduct directions require agencies to prove agent-level compliance — DRA certification, police verification, references, code-of-conduct undertakings and complaint records. Banks will ask for evidence, not assurances.</p></details>
<details data-cat="c3"><summary><span class="fq-n">14</span><span class="fq-q">What is the one-click audit report?</span><i class="fas fa-plus"></i></summary><p>For any agent, SmartPRS generates a compliance audit PDF on YOUR letterhead: every parameter checked against live records, each marked Compliant / Attention / Non-compliant, with an overall verdict, compliance score and a tamper-evident reference.</p></details>
<details data-cat="c3"><summary><span class="fq-n">15</span><span class="fq-q">Can I audit the whole agency at once?</span><i class="fas fa-plus"></i></summary><p>Yes — filter by company, department or team and generate one bulk PDF: a summary page with a verdict per agent, followed by a full report for each. Ready to hand to a bank or auditor.</p></details>
<details data-cat="c3"><summary><span class="fq-n">16</span><span class="fq-q">Can I see what the report looks like?</span><i class="fas fa-plus"></i></summary><p>A sample audit report is not available online yet in SmartPRS3 — ask in the demo request below and our team will walk you through one.</p></details>
<details data-cat="c4"><summary><span class="fq-n">17</span><span class="fq-q">SaaS or on-premise?</span><i class="fas fa-plus"></i></summary><p>Both. <b>On-Premises</b>: a lifetime licence installed on your own infrastructure, so your data never leaves your office. <b>Cloud Service</b>: a fixed-capacity monthly package that we host. QR Code Attendance and every module are included in both.</p></details>
<details data-cat="c4"><summary><span class="fq-n">18</span><span class="fq-q">How long does onboarding take?</span><i class="fas fa-plus"></i></summary><p>Most teams are live within days. We set up your tenant, import people and configure pay structures with you.</p></details>
<details data-cat="c4"><summary><span class="fq-n">19</span><span class="fq-q">How does pricing work?</span><i class="fas fa-plus"></i></summary><p><?php $fqP = (array) ($c['pricing'] ?? []); $fqC = (array) ($fqP['cloud']['packages'] ?? []); $fqO = (array) ($fqP['onprem'] ?? []); $fqB = (array) ($fqP['onboarding'] ?? []);
    $fqI = static fn ($v): string => '₹' . preg_replace('/(\d+?)(?=(\d\d)+(\d)(?!\d))/', '$1,', (string) (int) round((float) $v)); ?>
    <b>Cloud</b> — every plan includes every module and QR attendance; the price depends only on your team size: <?= e(implode(', ', array_map(static fn (array $p): string => $p['name'] . ' ' . $fqI($p['monthly']) . '/mo (' . $p['employees'] . ' employees, extra employee ' . $fqI($p['extra']) . '/mo)', $fqC))) ?>; each additional company <?= e($fqI($fqP['cloud']['company_fee'] ?? 0)) ?>/mo. Billing is minimum 3 months in advance; pay 6 months for 10% off or 12 months for 25% off. Above 100 employees, contact sales.
    <b>On-Premises</b> — a lifetime licence of <?= e($fqI($fqO['licence'] ?? 0)) ?> one time, installation <?= e($fqI($fqO['installation'] ?? 0)) ?>, and AMC of <?= (int) ($fqO['amc_percent'] ?? 0) ?>% (<?= e($fqI(((float) ($fqO['licence'] ?? 0)) * ((float) ($fqO['amc_percent'] ?? 0)) / 100)) ?>) a year.
    Setup &amp; onboarding: <?= e($fqI($fqB['base'] ?? 0)) ?> + <?= e($fqI($fqB['per_employee'] ?? 0)) ?> per employee, one time. GST extra. The <a href="#calc" style="color:var(--accent)">pricing calculator</a> shows your total.</p></details>
<details data-cat="c4"><summary><span class="fq-n">20</span><span class="fq-q">Is QR Attendance charged extra?</span><i class="fas fa-plus"></i></summary><p>No. QR Code Attendance — and the mobile app that scans it — is included in every Cloud package and in the On-Premises licence, with no additional licence cost.</p></details>
<details data-cat="c4"><summary><span class="fq-n">21</span><span class="fq-q">Do you support GST invoicing &amp; auto-renewal?</span><i class="fas fa-plus"></i></summary><p>Yes — every payment gets a GST tax invoice (CGST + SGST in Telangana, IGST elsewhere).</p></details>
</div>
                </div>
                <aside class="fq-side"><h3>Still unsure?</h3><p>Talk to a person who has set SmartPRS up for collection floors before.</p>
                    <a href="tel:<?= e(preg_replace('/[^\d+]/', '', explode(',', $c['contact']['phone'])[0])) ?>" class="btn btn-accent"><i class="fas fa-phone"></i> Call us</a>
                    <a href="#contact" class="btn btn-out"><i class="fas fa-calendar-check"></i> Book a live demo</a></aside>
            </div>
        </div>
    </section>

    <section class="final">
        <div class="wrap"><div class="eyebrow">Ready when you are</div><h2>Put your next payroll on record.</h2>
            <p>Open your own demo company today, or let us walk your team through SmartPRS on a live call.</p>
            <div class="cta"><a href="demo.php" class="btn btn-accent"><i class="fas fa-play"></i> Explore Demo</a><a href="#contact" class="btn btn-out"><?= e($c['hero']['cta']) ?></a></div>
            <div class="st"><?php foreach ($c['stats'] as $s): ?><div><b><?= e($s['n']) ?></b><span><?= e($s['l']) ?></span></div><?php endforeach; ?></div>
        </div>
    </section>

    <?php $sfContact = (array) ($c['contact'] ?? []); $sfAbout = $c['brand'] . ' ' . $c['tagline'] . ' — ' . $c['about']['short']; $sfHome = '';
    require __DIR__ . '/../surface/partials/site-footer.php'; ?>

    <?php if ($waNo !== ''): ?><a class="wa-float" href="https://wa.me/<?= e($waNo) ?>?text=<?= urlencode('Hi! I would like to know more about SmartPRS.') ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp us</a><?php endif; ?>

    <script src="js/pricing.js"></script>
    <script>
    (function(){
        // Real screenshots: the tabs switch the picture.
        var sh=document.getElementById('shots'),imgs=sh.querySelectorAll('.shots-img img');
        sh.querySelectorAll('.shots-bar button').forEach(function(b){b.addEventListener('click',function(){
            sh.querySelectorAll('.shots-bar button').forEach(function(x){x.classList.toggle('on',x===b);});
            imgs.forEach(function(im,k){im.classList.toggle('on',k===+b.getAttribute('data-s'));});
        });});
        // How it works stepper.
        var S=[['fa-building','Set up the company','Your company, branches, departments, shifts, holidays and salary structure — set up in an afternoon. Statutory rules (PF, ESI, PT) follow the Government rules by themselves.'],
            ['fa-users','Add people and phones','Import employees from Excel. Each gets a login with their role; their phone is activated once with a code, then only approved phones can punch.'],
            ['fa-qrcode','Punch in — QR, biometric or app','Employees scan the gate QR in the SmartPRS3 app, punch on the biometric device, or via SmartEPT. Everything lands in one attendance register.'],
            ['fa-circle-check','Leave and approvals','Leave, regularizations, advances and letters reach the right manager in one approvals inbox — approve with one click, with an audit trail.'],
            ['fa-calculator','Generate payroll','Attendance, leave, late cuts, overtime, incentives and statutory deductions are computed. Every payslip explains its own figures.'],
            ['fa-building-columns','Pay and file','Approve, download the bank file and payslips, and file PF ECR, ESI, PT and TDS. RBI audit reports are one click away.']];
        var cur=0,card=document.getElementById('stepCard'),btns=document.querySelectorAll('#steps button');
        function show(i){cur=(i+S.length)%S.length;btns.forEach(function(b,k){b.classList.toggle('on',k===cur);});
            card.querySelector('.ic i').className='fas '+S[cur][0];card.querySelector('.k').textContent='STEP 0'+(cur+1)+' / 0'+S.length;
            card.querySelector('h3').textContent=S[cur][1];card.querySelector('p').textContent=S[cur][2];}
        btns.forEach(function(b){b.addEventListener('click',function(){show(+b.getAttribute('data-i'));});});
        card.querySelectorAll('[data-d]').forEach(function(b){b.addEventListener('click',function(){show(cur+ +b.getAttribute('data-d'));});});
        show(0);
        // FAQ filter.
        document.querySelectorAll('.fq-chips button').forEach(function(b){b.addEventListener('click',function(){
            document.querySelectorAll('.fq-chips button').forEach(function(x){x.classList.toggle('on',x===b);});
            var k=b.getAttribute('data-fq');document.querySelectorAll('.fq-list details').forEach(function(d){d.style.display=(k==='all'||d.getAttribute('data-cat')===k)?'':'none';});
        });});
    })();
    </script>
</body>
</html>
