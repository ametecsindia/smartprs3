<?php

declare(strict_types=1);

/**
 * PUBLIC LEGAL / POLICY PAGES — carried over from SmartPRS 2 (PolicyController + resources/views/policies),
 * same addresses: /privacy-policy, /terms-and-conditions, … (public/.htaccess sends them here as ?p=<slug>).
 * The list and titles are config/policies.json; the wording is surface/policies/<slug>.html.
 */

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

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$cfg = json_decode((string) @file_get_contents(__DIR__ . '/../config/policies.json'), true) ?: [];
$pages = (array) ($cfg['pages'] ?? []);
$slug = (string) ($_GET['p'] ?? '');
$file = __DIR__ . '/../surface/policies/' . $slug . '.html';

// Only a slug the list names, and only a file that is there (the slug never reaches the path otherwise).
if (!isset($pages[$slug]) || !preg_match('/^[a-z-]+$/', $slug) || !is_file($file)) {
    http_response_code(404);
    header('Location: ./');
    exit;
}

$pg = $pages[$slug];
$c = [];

try {
    $pdo = \SmartPRS\Surface\Database::connect(__DIR__ . '/../.env');
    $settings = $pdo === null ? null : new \SmartPRS\Surface\PlatformSettings(new \SmartPRS\Surface\SqlStore($pdo));
    $c = (new \SmartPRS\Surface\LandingContent(__DIR__ . '/../config/landing.json', $settings))->content();
} catch (\Throwable $e) {
    $c = []; // no database: the footer shows without the contact lines
}

$sfContact = (array) ($c['contact'] ?? []);
$sfAbout = trim(($c['brand'] ?? 'SmartPRS') . ' ' . ($c['tagline'] ?? '') . ' — ' . ($c['about']['short'] ?? ''), ' —');
$sfHome = './';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $pg['title']) ?> — SmartPRS by Ametecs</title>
<link rel="icon" type="image/png" href="images/logo-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--navy:#0a1628;--navy2:#102744;--accent:#f97316;--border:#e8edf3;--text:#0f1d33;--text2:#516074;--text3:#9aa7b8;--bg:#f6f8fb}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif}
body{color:var(--text);background:var(--bg);line-height:1.65;-webkit-font-smoothing:antialiased}
a{color:var(--accent);text-decoration:none}
.wrap{max-width:1200px;margin:0 auto;padding:0 clamp(16px,4vw,28px)}
.nav{background:var(--navy);position:sticky;top:0;z-index:50;border-bottom:1px solid rgba(255,255,255,.08)}
.nav .wrap{display:flex;align-items:center;justify-content:space-between;height:68px}
.nav img{height:36px;width:auto;display:block}
.nav .r{display:flex;gap:10px;align-items:center}
.nav .r a{color:#fff;font-weight:600;font-size:14px;padding:9px 16px;border-radius:9px}
.nav .r a.cta{background:var(--accent)}
.ph{background:radial-gradient(ellipse at 15% 0%,#17345c 0,var(--navy2) 40%,var(--navy) 80%);padding:46px 0 50px;color:#fff}
.ph h1{font-size:clamp(26px,3.4vw,38px);font-weight:800;letter-spacing:-.8px}
.ph .sub{color:#b8c4d4;margin-top:6px}.ph .meta{color:var(--text3);margin-top:12px;font-size:13.5px}.ph .meta a{color:#b8c4d4}
.doc{max-width:880px}
main{background:#fff;border-bottom:1px solid var(--border)}
article{padding:40px 0 60px}
article h2{font-size:1.18rem;font-weight:800;color:var(--navy);margin:30px 0 10px}
article h3{font-size:1.02rem;font-weight:700;color:var(--navy);margin:20px 0 8px}
article p{margin:0 0 12px;color:var(--text2)}
article ul,article ol{margin:0 0 14px 22px;color:var(--text2)}article li{margin-bottom:7px}article strong{color:var(--text)}
article table{width:100%;border-collapse:collapse;margin:0 0 16px;font-size:.93rem}
article th{background:var(--navy);color:#fff;text-align:left;padding:9px 12px;font-weight:700}
article td{border:1px solid var(--border);padding:9px 12px;color:var(--text2);vertical-align:top}
.note{background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid var(--accent);border-radius:10px;padding:14px 18px;margin:16px 0;color:#7c2d12;font-size:.95rem}
@media(max-width:600px){.nav .r a:not(.cta){display:none}}
</style></head>
<body>
<nav class="nav"><div class="wrap">
    <a href="./"><img src="images/logo.png" alt="SmartPRS"></a>
    <div class="r"><a href="app.php?r=signin">Sign in</a><a class="cta" href="demo.php">Explore Demo</a></div>
</div></nav>
<header class="ph"><div class="wrap doc">
    <h1><?= $h((string) $pg['title']) ?></h1>
    <div class="sub"><?= $h((string) ($pg['sub'] ?? '')) ?></div>
    <div class="meta">Effective date: <?= $h((string) ($cfg['effective'] ?? '')) ?> · <a href="./">&larr; Back to home</a></div>
</div></header>
<main><div class="wrap doc"><article>
<?php readfile($file); ?>
</article></div></main>
<?php require __DIR__ . '/../surface/partials/site-footer.php'; ?>
</body></html>
