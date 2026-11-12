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
    <title><?= e($c['brand']) ?> — <?= e($c['hero']['badge']) ?></title>
    <meta name="description" content="<?= e($c['hero']['subtitle']) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/png" href="images/logo-icon.png">
    <style>
        :root{
            --navy:#0a1628;--navy2:#102744;--accent:#f97316;--accent2:#fb923c;
            --bg:#f6f8fb;--card:#fff;--border:#e8edf3;--text:#0f1d33;--text2:#516074;--text3:#9aa7b8;
            --green:#10b981;--blue:#3b82f6;--purple:#8b5cf6;--ink:#0a1628;
        }
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif;}
        html{scroll-behavior:smooth;}
        body{color:var(--text);background:var(--card);line-height:1.55;-webkit-font-smoothing:antialiased;overflow-x:hidden;-webkit-text-size-adjust:100%;}
        a{text-decoration:none;color:inherit;}
        img{max-width:100%;height:auto;}
        .wrap{max-width:1160px;width:100%;margin:0 auto;padding:0 clamp(16px,4vw,24px);}
        .mono2{font-family:'DM Sans',sans-serif;}
        .btn{display:inline-flex;align-items:center;gap:9px;padding:13px 24px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;border:none;transition:.18s;white-space:nowrap;}
        .btn-accent{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;box-shadow:0 10px 28px rgba(249,115,22,.32);}
        .btn-accent:hover{transform:translateY(-2px);box-shadow:0 14px 34px rgba(249,115,22,.42);}
        .btn-light{background:#fff;color:var(--navy);border:1px solid var(--border);}
        .btn-light:hover{border-color:var(--accent);color:var(--accent);}
        .btn-ghost{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.22);}
        .btn-ghost:hover{background:rgba(255,255,255,.16);}
        nav{position:sticky;top:0;z-index:50;background:rgba(10,22,40,.85);backdrop-filter:blur(14px);border-bottom:1px solid rgba(255,255,255,.06);}
        nav .wrap{display:flex;align-items:center;justify-content:space-between;height:70px;}
        .nav-burger{display:none;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.2);color:#fff;border-radius:10px;width:44px;height:44px;font-size:19px;cursor:pointer;align-items:center;justify-content:center;}
        .mobile-menu{display:none;flex-direction:column;background:rgba(10,22,40,.98);backdrop-filter:blur(14px);border-top:1px solid rgba(255,255,255,.08);padding:8px clamp(16px,4vw,24px) 18px;}
        .mobile-menu a{padding:14px 6px;color:rgba(255,255,255,.88);font-size:16px;font-weight:600;border-bottom:1px solid rgba(255,255,255,.07);}
        .mobile-menu a:last-child{border-bottom:none;}
        .mobile-menu .mm-cta{margin-top:10px;background:var(--accent);color:#fff;text-align:center;border-radius:11px;border:none;}
        body.mm-open .mobile-menu{display:flex;}
        .logo{display:flex;align-items:center;gap:11px;font-weight:800;font-size:19px;color:#fff;}
        .logo .mark{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--accent),#ea580c);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(249,115,22,.4);}
        .logo b{color:var(--accent2);font-weight:800;}
        .navlinks{display:flex;gap:30px;align-items:center;font-size:14.5px;font-weight:500;color:rgba(255,255,255,.72);}
        .navlinks a:hover{color:#fff;}
        .navlinks .btn{padding:9px 18px;font-size:14px;}
        .hero{background-color:var(--navy);background-size:cover;background-position:right center;background-repeat:no-repeat;color:#fff;padding:112px 0 72px;position:relative;overflow:hidden;min-height:92vh;display:flex;align-items:center;}
        .hero::before{content:'';position:absolute;inset:0;z-index:0;background:linear-gradient(90deg,var(--navy) 0%,rgba(15,29,51,.94) 26%,rgba(15,29,51,.62) 56%,rgba(15,29,51,.12) 100%);}
        .hero .wrap{position:relative;z-index:1;width:100%;}
        .hero-copy{max-width:640px;}
        .badge{display:inline-flex;align-items:center;gap:8px;background:rgba(249,115,22,.14);color:#fdba74;padding:7px 16px;border-radius:30px;font-size:12.5px;font-weight:600;margin-bottom:24px;border:1px solid rgba(249,115,22,.22);}
        .badge .dot{width:7px;height:7px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 4px rgba(249,115,22,.25);}
        .hero h1{font-size:clamp(34px,5.5vw,54px);font-weight:800;letter-spacing:-1.5px;max-width:880px;line-height:1.07;}
        .hero h1 .hl{background:linear-gradient(120deg,var(--accent2),#fcd34d);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;}
        .hero p{color:rgba(255,255,255,.72);font-size:19px;margin:24px 0 36px;max-width:660px;}
        .hero .cta{display:flex;gap:14px;flex-wrap:wrap;align-items:center;}
        .hero .micro{margin-top:18px;font-size:13.5px;color:rgba(255,255,255,.55);display:flex;gap:18px;flex-wrap:wrap;}
        .hero .micro span{display:inline-flex;align-items:center;gap:7px;}
        .hero .micro i{color:var(--green);}
        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:70px;}
        .stat{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:24px;text-align:left;}
        .stat .n{font-size:34px;font-weight:800;background:linear-gradient(120deg,var(--accent2),#fcd34d);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;}
        .stat .l{font-size:13.5px;color:rgba(255,255,255,.62);margin-top:6px;}
        .logobar{background:var(--navy);padding:30px 0;border-top:1px solid rgba(255,255,255,.06);}
        .logobar .wrap{display:flex;align-items:center;justify-content:center;gap:40px;flex-wrap:wrap;}
        .logobar .lbl{color:rgba(255,255,255,.4);font-size:12px;text-transform:uppercase;letter-spacing:1.5px;font-weight:600;}
        .logobar .cli{color:rgba(255,255,255,.55);font-weight:700;font-size:17px;letter-spacing:.3px;}
        section{padding:92px 0;}
        .sec-head{text-align:center;max-width:680px;margin:0 auto 56px;}
        .eyebrow{color:var(--accent);font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:1.4px;}
        h2{font-size:38px;font-weight:800;letter-spacing:-1px;margin:12px 0 14px;line-height:1.12;}
        .lead{color:var(--text2);font-size:17px;}
        .alt{background:var(--bg);}
        .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;margin-top:20px;counter-reset:step;}
        .step{position:relative;padding:28px 22px;background:var(--card);border:1px solid var(--border);border-radius:18px;}
        .step .num{counter-increment:step;width:42px;height:42px;border-radius:12px;background:rgba(249,115,22,.1);color:var(--accent);font-weight:800;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:16px;}
        .step .num::before{content:'0' counter(step);}
        .step h3{font-size:17px;margin-bottom:8px;}
        .step p{color:var(--text2);font-size:14px;}
        .feat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:22px;}
        .feat{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:26px;transition:.22s;}
        .feat:hover{transform:translateY(-5px);box-shadow:0 22px 50px rgba(15,29,51,.1);border-color:rgba(249,115,22,.3);}
        .feat .ic{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,rgba(249,115,22,.14),rgba(249,115,22,.06));color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:15px;}
        .feat h3{font-size:17px;margin-bottom:8px;}
        .feat p{color:var(--text2);font-size:14px;line-height:1.55;}
        .secure{background:linear-gradient(160deg,var(--navy),var(--navy2));color:#fff;border-radius:26px;padding:clamp(28px,5vw,54px);display:grid;grid-template-columns:1.1fr 1fr;gap:40px;align-items:center;overflow:hidden;}
        .secure h2{color:#fff;}
        .secure p{color:rgba(255,255,255,.72);font-size:16px;margin-top:8px;}
        .secure .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .secure .item{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:18px;}
        .secure .item i{color:var(--accent2);font-size:20px;}
        .secure .item h4{font-size:14.5px;margin:10px 0 4px;}
        .secure .item p{font-size:12.5px;color:rgba(255,255,255,.6);margin:0;}
        .price-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;align-items:start;}
        .plan{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:34px;transition:.2s;}
        .plan:hover{box-shadow:0 18px 44px rgba(15,29,51,.08);}
        .plan.hot{border:2px solid var(--accent);box-shadow:0 24px 60px rgba(249,115,22,.2);position:relative;transform:scale(1.03);}
        .plan.hot::before{content:'★ Most popular';position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;white-space:nowrap;}
        .plan h3{font-size:19px;}
        .plan .amt{font-size:42px;font-weight:800;margin:12px 0;letter-spacing:-1px;}
        .plan .amt small{font-size:15px;color:var(--text3);font-weight:500;}
        .plan ul{list-style:none;margin:20px 0 24px;}
        .plan li{padding:8px 0;font-size:14.5px;color:var(--text2);display:flex;gap:10px;align-items:flex-start;}
        .plan li i{color:var(--green);margin-top:3px;font-size:13px;}
        .onprem{margin-top:28px;background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;border-radius:14px;padding:clamp(24px,3.4vw,34px) clamp(22px,3.6vw,42px);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:26px;box-shadow:0 22px 50px rgba(10,22,40,.20);position:relative;overflow:hidden;border:1px solid rgba(255,255,255,.06);}
        .onprem::after{content:'';position:absolute;right:-70px;top:-70px;width:240px;height:240px;background:radial-gradient(circle,rgba(249,115,22,.20),transparent 70%);pointer-events:none;}
        .onprem .op-l{display:flex;align-items:center;gap:18px;flex:1;min-width:min(100%,440px);position:relative;z-index:1;}
        .onprem .op-ic{flex-shrink:0;width:58px;height:58px;border-radius:15px;background:linear-gradient(135deg,var(--accent),#ea580c);display:flex;align-items:center;justify-content:center;font-size:25px;color:#fff;box-shadow:0 10px 26px rgba(249,115,22,.40);}
        .onprem .op-l .eyebrow{color:var(--accent2);margin-bottom:3px;}
        .onprem h3{font-size:clamp(19px,2.2vw,23px);color:#fff;margin:2px 0 6px;line-height:1.22;}
        .onprem p{color:rgba(255,255,255,.74);font-size:14.5px;max-width:540px;margin:0;}
        .onprem .op-r{display:flex;flex-direction:column;align-items:flex-end;gap:13px;position:relative;z-index:1;}
        .onprem .op-chips{display:flex;flex-wrap:wrap;gap:11px;justify-content:flex-end;}
        .onprem .op-btn{display:inline-flex;align-items:center;gap:8px;background:#fff;color:var(--navy);font-weight:700;font-size:13.5px;padding:11px 20px;border-radius:10px;transition:.18s;white-space:nowrap;}
        .onprem .op-btn:hover{background:var(--accent);color:#fff;transform:translateY(-1px);box-shadow:0 8px 20px rgba(249,115,22,.35);}
        .onprem .op-btn i{font-size:11px;}
        .onprem .ver{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.17);border-radius:10px;padding:11px 17px;font-weight:700;font-size:14px;color:#fff;display:flex;align-items:center;gap:9px;white-space:nowrap;}
        .onprem .ver i{color:var(--accent2);font-size:13px;}
        @media(max-width:760px){.onprem{flex-direction:column;align-items:flex-start;}.onprem .op-r{width:100%;align-items:flex-start;}.onprem .op-chips{justify-content:flex-start;}}
        .quotes{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
        .quote{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:28px;}
        .quote .stars{color:#f59e0b;font-size:14px;margin-bottom:12px;}
        .quote p{font-size:15px;color:var(--text);font-style:italic;}
        .quote .who{display:flex;align-items:center;gap:12px;margin-top:18px;}
        .quote .av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;}
        .quote .who b{font-size:14px;display:block;}
        .quote .who span{font-size:12.5px;color:var(--text3);}
        .faq{max-width:780px;margin:0 auto;}
        .faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 30px;max-width:1120px;margin:0 auto;align-items:start;}
        .faq-cat{margin-bottom:10px;}
        .faq-cat h3{font-size:13px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--text1);margin:6px 0 12px;display:flex;align-items:center;gap:9px;}
        .faq-cat h3 .dot{width:9px;height:9px;border-radius:50%;background:var(--accent);flex:0 0 auto;}
        details{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:0 20px;margin-bottom:12px;transition:.2s;}
        details[open]{border-color:rgba(249,115,22,.35);box-shadow:0 10px 30px rgba(15,29,51,.06);}
        summary{list-style:none;cursor:pointer;padding:20px 0;font-weight:700;font-size:16px;display:flex;justify-content:space-between;align-items:center;gap:16px;}
        summary::-webkit-details-marker{display:none;}
        summary i{color:var(--accent);transition:.2s;flex-shrink:0;}
        details[open] summary i{transform:rotate(45deg);}
        details p{color:var(--text2);font-size:14.5px;padding:0 0 20px;margin:0;}
        .band{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff;border-radius:28px;padding:60px 48px;text-align:center;position:relative;overflow:hidden;}
        .band::after{content:'';position:absolute;right:-80px;bottom:-80px;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.08);}
        .band h2{color:#fff;font-size:clamp(26px,4vw,36px);position:relative;}
        .band p{color:rgba(255,255,255,.92);margin:14px 0 30px;font-size:17px;position:relative;}
        .band .row{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;position:relative;}
        .band .meta{margin-top:22px;font-size:14px;opacity:.92;position:relative;}
        footer{background:var(--navy);color:rgba(255,255,255,.6);padding:64px 0 32px;font-size:14px;}
        footer .cols{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:32px;margin-bottom:40px;}
        footer h5{color:#fff;font-size:13px;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;}
        footer .lk{display:block;padding:6px 0;color:rgba(255,255,255,.6);}
        footer .lk:hover{color:#fff;}
        footer .blurb{color:rgba(255,255,255,.5);font-size:14px;margin-top:14px;max-width:280px;}
        footer .bottom{border-top:1px solid rgba(255,255,255,.1);padding-top:24px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;font-size:13px;}
        @media(max-width:920px){
            .stats,.feat-grid,.price-grid,.steps,.quotes{grid-template-columns:1fr 1fr;}
            .secure{grid-template-columns:1fr;}
            footer .cols{grid-template-columns:1fr 1fr;}
            .hero{min-height:auto;padding:96px 0 60px;background-position:72% center;}
            .hero-copy{max-width:none;}
            .hero::before{background:linear-gradient(180deg,rgba(15,29,51,.9),rgba(15,29,51,.82));}
        }
        @media(max-width:620px){
            .navlinks{display:none;}
            .nav-burger{display:inline-flex;}
            .hero h1{font-size:36px;letter-spacing:-1px;}
            .hero p{font-size:17px;}
            h2{font-size:29px;}
            .stats,.feat-grid,.price-grid,.steps,.quotes,.secure .grid,footer .cols,.faq-grid{grid-template-columns:1fr;}
            .plan.hot{transform:none;}
            section{padding:64px 0;}
            .band,.secure{padding:36px 24px;}
        }
    </style>
</head>
<body>
    <nav>
        <div class="wrap">
            <a href="/landing.php" class="logo"><img src="images/logo.png" alt="SmartPRS — Reputation | Relationships | Results" style="height:40px;width:auto;display:block;"></a>
            <div class="navlinks">
                <a href="#about">Why SmartPRS</a>
                <a href="#features">Features</a>
                <a href="#how">How it works</a>
                <a href="#pricing">Pricing</a>
                <a href="#contact" style="color:var(--accent);font-weight:700;">Live Demo</a>
                <a href="#faq">FAQ</a>
                <a href="app.php?r=signin" class="btn btn-ghost">Sign in</a>
                <a href="#contact" class="btn btn-accent"><?= e($c['hero']['cta']) ?></a>
            </div>
            <button class="nav-burger" aria-label="Menu" onclick="document.body.classList.toggle('mm-open')"><i class="fas fa-bars"></i></button>
        </div>
        <div class="mobile-menu" onclick="document.body.classList.remove('mm-open')">
            <a href="#about">Why SmartPRS</a>
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            <a href="#pricing">Pricing</a>
            <a href="#contact" style="color:var(--accent);font-weight:700;">Live Demo</a>
            <a href="#faq">FAQ</a>
            <a href="app.php?r=signin">Sign in</a>
            <a href="#contact" class="mm-cta"><?= e($c['hero']['cta']) ?> →</a>
        </div>
    </nav>

    <header class="hero" style="background-image:url('<?= e($c['hero']['image'] ?? 'images/hero.png') ?>')">
        <div class="wrap">
            <div class="hero-copy">
                <span class="badge"><span class="dot"></span> <?= e($c['hero']['badge']) ?></span>
                <h1><?= e($c['hero']['title']) ?></h1>
                <p><?= e($c['hero']['subtitle']) ?></p>
                <div class="cta">
                    <a href="#contact" class="btn btn-accent"><?= e($c['hero']['cta']) ?> <i class="fas fa-arrow-right"></i></a>
                    <a href="app.php?r=signin" class="btn btn-ghost"><i class="fas fa-right-to-bracket"></i> <?= e($c['hero']['cta2']) ?></a>
                </div>
                <div class="micro">
                    <span><i class="fas fa-check-circle"></i> No card required for a demo</span>
                    <span><i class="fas fa-check-circle"></i> SaaS or on-premise</span>
                    <span><i class="fas fa-check-circle"></i> India statutory-ready</span>
                </div>
                <div class="stats">
                    <?php foreach ($c['stats'] as $s): ?>
                        <div class="stat"><div class="n"><?= e($s['n']) ?></div><div class="l"><?= e($s['l']) ?></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="logobar">
        <div class="wrap">
            <span class="lbl">Trusted by collections &amp; recovery teams</span>
            <?php foreach ($c['clients'] as $cl): ?>
                <span class="cli"><?= e($cl['name']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <section class="alt" id="about">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow"><?= e($c['about']['eyebrow']) ?></div>
                <h2><?= e($c['about']['title']) ?></h2>
            </div>
            <div style="max-width:840px;margin:0 auto;">
                <?php foreach (preg_split('/\n{2,}/', $c['about']['body']) as $p): ?>
                    <p class="lead" style="margin-bottom:16px;"><?= nl2br(e($p)) ?></p>
                <?php endforeach; ?>
                <?php if (!empty($c['about']['proof'])): ?>
                    <div style="margin-top:22px;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                        <i class="fas fa-circle-check" style="color:var(--green);font-size:1.3rem;"></i>
                        <span style="font-weight:600;color:var(--navy);flex:1 1 280px;"><?= e($c['about']['proof']) ?></span>
                        <?php if (!empty($c['about']['proof_url'])): ?>
                            <a href="<?= e($c['about']['proof_url']) ?>" target="_blank" rel="noopener" style="color:var(--accent);font-weight:700;white-space:nowrap;"><?= e($c['about']['proof_label'] ?? 'Know more') ?> <i class="fas fa-arrow-up-right-from-square" style="font-size:.8rem;"></i></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div style="margin-top:26px;padding-left:16px;border-left:3px solid var(--accent);">
                    <div style="font-weight:800;color:var(--navy);font-size:1.05rem;"><?= e($c['about']['founder']) ?></div>
                    <div style="color:var(--text2);font-size:.93rem;"><?= e($c['about']['founder_role']) ?></div>
                </div>
            </div>
        </div>
    </section>

    <section style="background:linear-gradient(135deg,var(--navy) 0%,#0e1c30 100%);color:#fff;padding:40px 0;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-60px;right:-40px;width:280px;height:280px;border-radius:50%;background:var(--accent);opacity:.08;"></div>
        <div class="wrap" style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:26px;flex-wrap:wrap;">
            <div style="flex:1 1 520px;">
                <span style="display:inline-block;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-weight:700;font-size:12.5px;letter-spacing:.6px;padding:6px 15px;border-radius:30px;margin-bottom:13px;"><i class="fas fa-bullhorn" style="margin-right:7px;"></i>Effective 1 October 2026</span>
                <h2 style="font-size:clamp(22px,3vw,31px);font-weight:800;letter-spacing:-.6px;line-height:1.12;margin:4px 0 10px;color:#fff;">Recovery Compliance 2026 — is your agency audit-ready?</h2>
                <p style="color:rgba(255,255,255,.74);font-size:16px;line-height:1.5;margin:0 0 14px;max-width:680px;">New RBI recovery-conduct rules for collection &amp; recovery agencies. See all 45 payroll &amp; HRMS topics every agency must follow — and how SmartPRS already delivers them.</p>
                <div style="display:flex;flex-wrap:wrap;gap:9px;max-width:720px;">
                    <span style="background:rgba(255,255,255,.12);color:#fff;font-size:12.5px;font-weight:600;padding:6px 13px;border-radius:20px;"><i class="fas fa-id-badge" style="margin-right:6px;opacity:.8;"></i>DRA · PCC · references tracked per agent</span>
                    <span style="background:rgba(255,255,255,.12);color:#fff;font-size:12.5px;font-weight:600;padding:6px 13px;border-radius:20px;"><i class="fas fa-shield-halved" style="margin-right:6px;opacity:.8;"></i>Verdict per agent — Compliant / Attention / Non-compliant</span>
                    <span style="background:rgba(255,255,255,.12);color:#fff;font-size:12.5px;font-weight:600;padding:6px 13px;border-radius:20px;"><i class="fas fa-layer-group" style="margin-right:6px;opacity:.8;"></i>One bulk audit file for the whole agency</span>
                    <span style="background:rgba(255,255,255,.12);color:#fff;font-size:12.5px;font-weight:600;padding:6px 13px;border-radius:20px;"><i class="fas fa-fingerprint" style="margin-right:6px;opacity:.8;"></i>Tamper-evident report references</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="recovery-compliance-2026.html" class="btn btn-accent"><i class="fas fa-book-open"></i> Read the Manual</a>
                <?php /* NOT PORTED: /sample-audit-report.pdf was dynamically generated by SmartPRS2's ReportController — no generator exists yet in SmartPRS3, so that button is omitted rather than linking to a 404. */ ?>
                <a href="SmartPRS-Recovery-Compliance-2026-Manual.pdf" download class="btn btn-ghost"><i class="fas fa-download"></i> Download PDF</a>
            </div>
        </div>
    </section>

    <section id="features">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Platform · 16 modules</div>
                <h2>Sixteen modules. One workforce platform.</h2>
                <p class="lead">Every HR function your collections &amp; recovery business runs on — from hiring and biometric attendance to statutory payroll, field-force compliance and analytics — built in.</p>
            </div>
            <div class="feat-grid">
                <?php foreach ($c['features'] as $f): ?>
                    <div class="feat">
                        <div class="ic"><i class="fas fa-<?= e($f['icon']) ?>"></i></div>
                        <h3><?= e($f['title']) ?></h3>
                        <p><?= e($f['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="alt" id="how">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">How it works</div>
                <h2>Live in days, not months</h2>
                <p class="lead">A guided onboarding gets your companies, people and payroll running fast.</p>
            </div>
            <div class="steps">
                <div class="step"><div class="num"></div><h3>Onboard your company</h3><p>We set up your tenant, branding and the first company in minutes.</p></div>
                <div class="step"><div class="num"></div><h3>Add people &amp; devices</h3><p>Import employees, link biometric devices and configure pay structures.</p></div>
                <div class="step"><div class="num"></div><h3>Run compliant payroll</h3><p>Attendance flows into PF/ESI/PT/TDS-ready payroll with approvals.</p></div>
                <div class="step"><div class="num"></div><h3>Pay &amp; stay compliant</h3><p>Generate bank files, payslips and track DRA/PCC compliance automatically.</p></div>
            </div>
        </div>
    </section>

    <section id="security">
        <div class="wrap">
            <div class="secure">
                <div>
                    <div class="eyebrow">Security &amp; compliance</div>
                    <h2>Built for India, secure by design</h2>
                    <p>Role-based access, full audit trails and statutory formats — so payroll and field-force data stay accurate and protected.</p>
                </div>
                <div class="grid">
                    <div class="item"><i class="fas fa-user-shield"></i><h4>Role-based access</h4><p>Granular permissions per role and module.</p></div>
                    <div class="item"><i class="fas fa-file-invoice-dollar"></i><h4>Statutory formats</h4><p>PF ECR, ESI, PT and TDS-ready outputs.</p></div>
                    <div class="item"><i class="fas fa-clipboard-check"></i><h4>Audit trails</h4><p>Every approval and change is logged.</p></div>
                    <div class="item"><i class="fas fa-database"></i><h4>Your data, your choice</h4><p>Cloud SaaS or on-premise deployment.</p></div>
                </div>
            </div>
        </div>
    </section>

    <section class="alt" id="pricing">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Pricing</div>
                <h2>Simple, scalable plans</h2>
                <p class="lead">All 16 modules in every plan — pricing changes only with your team size. Pay 12 months in advance and save 25%.</p>
            </div>
            <div class="price-grid">
                <?php foreach ($c['plans'] as $p): ?>
                    <div class="plan <?= (($p['highlight'] ?? '0') == '1') ? 'hot' : '' ?>">
                        <h3><?= e($p['name']) ?></h3>
                        <div class="amt"><?= e($p['price']) ?><small><?= e($p['period']) ?></small></div>
                        <ul>
                            <?php foreach (array_filter(array_map('trim', explode(',', $p['features'] ?? ''))) as $li): ?>
                                <li><i class="fas fa-check"></i> <?= e($li) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php /* NOT PORTED: SmartPRS2's /signup self-serve purchase+billing flow does not exist in SmartPRS3 yet (part of the Super Admin/licensing/billing work not yet built) — routed to the demo form instead of a dead link. */ ?>
                        <a href="#contact" class="btn <?= (($p['highlight'] ?? '0') == '1') ? 'btn-accent' : 'btn-light' ?>" style="width:100%;justify-content:center;">Get started</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="onprem">
                <div class="op-l">
                    <div class="op-ic"><i class="fas fa-server"></i></div>
                    <div>
                        <div class="eyebrow">On-premise &middot; Perpetual licence</div>
                        <h3>Prefer to own it outright? Run SmartPRS on your own server.</h3>
                        <p>A lifetime perpetual licence installed on your own infrastructure &mdash; in your office, your data, no monthly fees. Available in three editions:</p>
                    </div>
                </div>
                <div class="op-r">
                    <div class="op-chips">
                        <span class="ver"><i class="fas fa-cube"></i> Basic</span>
                        <span class="ver"><i class="fas fa-layer-group"></i> Advance</span>
                        <span class="ver"><i class="fas fa-dna"></i> Collection DNA</span>
                    </div>
                    <a href="#contact" class="op-btn">Talk to sales <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>

    <section id="testimonials">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Customer stories</div>
                <h2>Teams already running on SmartPRS</h2>
            </div>
            <div class="quotes">
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>"Payroll that used to take us three days now closes in an afternoon — and the statutory files are ready to file."</p>
                    <div class="who"><div class="av">RS</div><div><b>Operations Head</b><span>Exon Recovery Services</span></div></div>
                </div>
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>"DRA and PCC expiries used to slip through. Now we get alerts before they lapse — a huge compliance relief."</p>
                    <div class="who"><div class="av">NU</div><div><b>Compliance Manager</b><span>Numero Uno Financial</span></div></div>
                </div>
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>"Running all our group companies from one platform, with one login, changed how we operate."</p>
                    <div class="who"><div class="av">SC</div><div><b>Managing Director</b><span>Storm Collections</span></div></div>
                </div>
            </div>
        </div>
    </section>

    <section class="alt" id="faq">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">FAQ</div>
                <h2>Questions, answered</h2>
            </div>
            <div class="faq-grid">
                <div class="faq-cat">
                    <h3><span class="dot"></span> Platform &amp; modules</h3>
                    <details open><summary>What does SmartPRS cover? <i class="fas fa-plus"></i></summary><p>Sixteen modules across hiring, people, attendance, leave, payroll, compensation, statutory compliance, performance, learning, HR letters, field force, communication, reports, administration and the SaaS platform — in one system.</p></details>
                    <details><summary>Can it run multiple companies? <i class="fas fa-plus"></i></summary><p>Yes. A single tenant manages many companies, with a group view plus per-company data, branding and payroll.</p></details>
                    <details><summary>Is it built specifically for collections &amp; recovery? <i class="fas fa-plus"></i></summary><p>Yes — on-roll and off-roll agents, field operations and RBI / DRA / PCC obligations are first-class, not bolted on.</p></details>
                    <details><summary>Can modules be limited per plan or company? <i class="fas fa-plus"></i></summary><p>Yes. Module access can be gated by subscription plan, and within that every screen follows each user's role.</p></details>
                </div>
                <div class="faq-cat">
                    <h3><span class="dot"></span> Payroll &amp; compliance</h3>
                    <details><summary>Is it compliant with Indian payroll statutes? <i class="fas fa-plus"></i></summary><p>Yes: PF, ESI, Professional Tax and TDS, with statutory-ready outputs including PF ECR and ESI challans, configurable to your rates.</p></details>
                    <details><summary>How is field-force compliance handled? <i class="fas fa-plus"></i></summary><p>We track DRA certification, police verification (PCC) and agent authorisations, with alerts before anything expires.</p></details>
                    <details><summary>How are incentives &amp; commissions managed? <i class="fas fa-plus"></i></summary><p>A built-in engine computes payouts on a collected, target or manual basis (flat, slab or portfolio formulas) and folds them into payroll.</p></details>
                    <details><summary>Is sensitive data protected? <i class="fas fa-plus"></i></summary><p>Role-based access, full audit trails and DPDP-2023-aligned handling of employee and borrower data.</p></details>
                </div>
                <div class="faq-cat">
                    <h3><span class="dot"></span> Attendance &amp; field force</h3>
                    <details><summary>Does it support biometric devices? <i class="fas fa-plus"></i></summary><p>Yes — ZKTeco LAN sync, cloud services like eTimeOffice, or a universal CSV punch upload, plus geo-fenced in-app punch for field agents. Everything lands in one register that payroll reads.</p></details>
                    <details><summary>Can teams work different shifts — including night? <i class="fas fa-plus"></i></summary><p>Yes. Define named working shifts (General / Morning / Night), set a default per employee or roster day-wise. Night shifts cross midnight correctly and can pay an automatic per-night allowance in payroll.</p></details>
                    <details><summary>How does it stop attendance fraud? <i class="fas fa-plus"></i></summary><p>Geo-fenced selfie / GPS punch ties every attendance mark to a real location and time.</p></details>
                    <details><summary>Can it manage off-roll / commission agents? <i class="fas fa-plus"></i></summary><p>Yes — engage vendor / off-roll agents with full KYC (photo, ID, PAN, DRA, PCC, bank), kept separate from payroll employees.</p></details>
                </div>
                <div class="faq-cat">
                    <h3><span class="dot"></span> RBI 2026 &amp; audit reports</h3>
                    <details><summary>What changes on 1 October 2026? <i class="fas fa-plus"></i></summary><p>RBI's recovery-conduct directions require agencies to prove agent-level compliance — DRA certification, police verification, references, code-of-conduct undertakings and complaint records. Banks will ask for evidence, not assurances.</p></details>
                    <details><summary>What is the one-click audit report? <i class="fas fa-plus"></i></summary><p>For any agent, SmartPRS generates a compliance audit PDF on YOUR letterhead: every parameter checked against live records, each marked Compliant / Attention / Non-compliant, with an overall verdict, compliance score and a tamper-evident reference.</p></details>
                    <details><summary>Can I audit the whole agency at once? <i class="fas fa-plus"></i></summary><p>Yes — filter by company, department or team and generate one bulk PDF: a summary page with a verdict per agent, followed by a full report for each. Ready to hand to a bank or auditor.</p></details>
                    <details><summary>Can I see what the report looks like? <i class="fas fa-plus"></i></summary><p>A sample audit report is not available online yet in SmartPRS3 — ask in the demo request below and our team will walk you through one.</p></details>
                </div>
                <div class="faq-cat">
                    <h3><span class="dot"></span> Deployment, security &amp; pricing</h3>
                    <details><summary>SaaS or on-premise? <i class="fas fa-plus"></i></summary><p>Both — multi-company SaaS, or an on-premise install with a perpetual licence so your data stays on your infrastructure.</p></details>
                    <details><summary>How long does onboarding take? <i class="fas fa-plus"></i></summary><p>Most teams are live within days. We set up your tenant, import people and configure pay structures with you.</p></details>
                    <details><summary>How does pricing work? <i class="fas fa-plus"></i></summary><p>Every plan includes all 16 modules — the price depends only on your team size. Starter covers 25 employees, Growth 75, Professional 150, with a small per-employee rate beyond that. Billing is minimum 3 months in advance; pay 6 months for 10% off or 12 months for 25% off. GST tax invoices are emailed automatically.</p></details>
                    <details><summary>Do you support GST invoicing &amp; auto-renewal? <i class="fas fa-plus"></i></summary><p>Yes — GST tax invoices are generated and emailed, with automated subscription renewals.</p></details>
                </div>
            </div>
            <?php /* NOT PORTED: SmartPRS2's /faqs deep page (policies/faqs.blade.php) is not replicated — this button is omitted rather than linking to a 404. */ ?>
        </div>
    </section>

    <section id="contact" style="background:#f1f5f9;">
        <div class="wrap">
            <div class="sec-head">
                <div class="eyebrow">Book a live demo</div>
                <h2 style="color:var(--navy);">See SmartPRS in action for your companies</h2>
                <p class="lead" style="color:#475569;">Share a few details and our team will schedule a personalised walkthrough for your collections &amp; recovery business — SaaS or on-premise.</p>
            </div>
            <div style="display:grid;grid-template-columns:1.25fr 1fr;gap:26px;align-items:start;" class="lead-grid">
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:26px 28px;box-shadow:0 10px 28px rgba(15,29,51,.07);">
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
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:26px 28px;">
                    <h3 style="margin:0 0 14px;color:var(--navy);font-size:19px;">What happens after you submit?</h3>
                    <ol style="margin:0;padding-left:20px;color:#334155;font-size:14.5px;line-height:1.7;">
                        <li>Our team validates your requirement and team size.</li>
                        <li>We schedule a live SmartPRS demo over Zoom or Google Meet.</li>
                        <li>We map your companies, attendance and payroll flow into SmartPRS and show sample payslips and reports.</li>
                        <li>Commercials and deployment options (SaaS or on-premise) are shared after the demo.</li>
                    </ol>
                    <p style="font-size:13px;color:#64748b;margin:14px 0 0;">No obligations. No lock-in. The goal of the demo is to show how SmartPRS fits your collections &amp; recovery operation.</p>
                    <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0;">
                    <div style="font-size:14px;color:#334155;line-height:1.9;">
                        <div><i class="fas fa-envelope" style="color:var(--accent);width:20px;"></i> <a href="mailto:<?= e($c['contact']['email']) ?>" style="color:#334155;"><?= e($c['contact']['email']) ?></a></div>
                        <div><i class="fas fa-phone" style="color:var(--accent);width:20px;"></i> <?= e($c['contact']['phone']) ?></div>
                        <div style="display:flex;gap:8px;"><i class="fas fa-location-dot" style="color:var(--accent);width:20px;margin-top:3px;"></i> <span><?= nl2br(e(str_replace(' · ', "\n", $c['contact']['address']))) ?></span></div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .lf label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
            .lf input,.lf select,.lf textarea{width:100%;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:9px;font-size:14px;font-family:inherit;background:#f8fafc;}
            .lf input:focus,.lf select:focus,.lf textarea:focus{outline:none;border-color:var(--accent);background:#fff;}
            @media (max-width:920px){.lead-grid{grid-template-columns:1fr !important;}.lead-fields{grid-template-columns:1fr !important;}}
        </style>
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

    <footer>
        <div class="wrap">
            <div class="cols">
                <div>
                    <div class="logo"><img src="images/logo.png" alt="SmartPRS — Reputation | Relationships | Results" style="height:42px;width:auto;display:block;"></div>
                    <p class="blurb"><?= e($c['brand']) ?> <?= e($c['tagline']) ?> — <?= e($c['about']['short']) ?></p>
                    <?php if (file_exists(__DIR__ . '/images/ametecs-logo.png')): ?>
                        <a href="https://www.ametecsindia.com" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:10px;margin-top:14px;">
                            <span style="color:var(--text3);font-size:.85rem;">A product of</span>
                            <img src="images/ametecs-logo.png" alt="Ametecs India" style="height:30px;width:auto;display:block;">
                        </a>
                    <?php endif; ?>
                </div>
                <div>
                    <h5>Product</h5>
                    <a class="lk" href="#features">Features</a>
                    <a class="lk" href="#how">How it works</a>
                    <a class="lk" href="#pricing">Pricing</a>
                    <a class="lk" href="#security">Security</a>
                </div>
                <div>
                    <h5>Company</h5>
                    <a class="lk" href="#testimonials">Customers</a>
                    <a class="lk" href="#faq">FAQ</a>
                    <a class="lk" href="#contact">Contact</a>
                    <a class="lk" href="app.php?r=signin">Sign in</a>
                </div>
                <div>
                    <h5>Get in touch</h5>
                    <a class="lk" href="mailto:<?= e($c['contact']['email']) ?>"><?= e($c['contact']['email']) ?></a>
                    <span class="lk"><?php foreach (explode(',', $c['contact']['phone']) as $i => $ph): ?><?= $i ? ', ' : '' ?><span style="white-space:nowrap"><?= e(trim($ph)) ?></span><?php endforeach; ?></span>
                    <span class="lk"><?= nl2br(e(str_replace(' · ', "\n", $c['contact']['address']))) ?></span>
                </div>
            </div>
            <?php /* NOT PORTED: SmartPRS2's legal/policy page set (privacy, terms, refund, etc. — PolicyController::PAGES) is not replicated in SmartPRS3 yet — the footer link row is omitted rather than linking to nine 404s. */ ?>
            <div class="bottom">
                <span><?= e($c['footer']) ?></span>
                <span><?= e($c['brand']) ?> <?= e($c['tagline']) ?></span>
            </div>
        </div>
    </footer>
</body>
</html>
