<?php

declare(strict_types=1);

/**
 * THE WEBSITE FOOTER (owner, 12 Nov 2026: "like rovexy.app", with SmartPRS 2's legal pages and the
 * "More from Ametecs" product row). One file, used by landing.php, demo.php and policy.php.
 *
 * Expects: $sfContact  array  email, phone (comma-separated), address (" · " = new line)
 *          $sfAbout    string the one-line description under the logo
 *          $sfHome     string '' on the landing page (anchors stay on the page), './' elsewhere
 */

$sfE = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$sfPolicies = (array) ((json_decode((string) @file_get_contents(__DIR__ . '/../../config/policies.json'), true) ?: [])['pages'] ?? []);
$sfPhones = array_values(array_filter(array_map('trim', explode(',', (string) ($sfContact['phone'] ?? '')))));
$sfEmail = (string) ($sfContact['email'] ?? '');
$sfAddress = (string) ($sfContact['address'] ?? '');
// ponytail: product logos are served from rovexy.app (same company); copy them into images/products/ if that site moves.
// SmartDCM and QRONEX have no logo file on rovexy.app (it shows them as wordmarks too), so they stay wordmarks.
$sfFamily = [
    ['https://www.smartdcm.app', '#22c55e', '', 'Smart<b>DCM</b>', 'Collection CRM &amp; dialer'],
    ['https://rovexy.app', '#f97316', 'https://rovexy.app/brand/rovexy-logo-dark.svg', 'ROVEXY', 'Calls, visits &amp; payments'],
    ['https://www.smartept.com', '#3b82f6', 'https://rovexy.app/brand/products/smartept.png', 'SmartEPT', 'Employee productivity'],
    ['https://www.leadger.in', '#a855f7', 'https://rovexy.app/brand/products/leadger.png', 'LeadGER', 'Telecalling CRM'],
    ['https://qronex.app', '#14b8a6', '', 'QRONEX', 'QR attendance'],
];
?>
<style>
footer.sf{background:#0a1422;color:rgba(255,255,255,.62);padding:64px 0 26px;font-size:14px;line-height:1.6}
footer.sf .sf-cols{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:32px}
footer.sf h5{color:#fff;font-size:13px;font-weight:700;letter-spacing:.3px;margin:0 0 14px}
footer.sf a{color:inherit;text-decoration:none}footer.sf .lk{display:block;padding:4px 0}footer.sf .lk:hover,footer.sf .sf-bottom a:hover{color:#fff}
footer.sf .sf-blurb{margin:14px 0 0;max-width:330px}footer.sf .sf-addr{margin:12px 0 0;font-size:13px;max-width:330px}
footer.sf .sf-family{border-top:1px solid rgba(255,255,255,.08);margin-top:44px;padding-top:30px}
footer.sf .sf-family h5{margin-bottom:16px}
footer.sf .sf-tiles{display:grid;grid-template-columns:repeat(5,1fr);gap:14px}
footer.sf .sf-tile{display:flex;flex-direction:column;justify-content:center;gap:8px;min-height:92px;padding:16px 18px;background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);border-top:3px solid var(--sf-c);border-radius:12px;transition:background .2s,transform .2s}
footer.sf .sf-tile:hover{background:rgba(255,255,255,.07);transform:translateY(-2px)}
footer.sf .sf-tile img{height:26px;width:auto;max-width:100%;object-fit:contain;object-position:left center;display:block}
footer.sf .sf-word{color:#fff;font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:26px}footer.sf .sf-word b{color:var(--sf-c)}
footer.sf .sf-tile small{font-size:12.5px;color:rgba(255,255,255,.55)}
footer.sf .sf-bottom{border-top:1px solid rgba(255,255,255,.08);margin-top:30px;padding-top:20px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13px}
@media(max-width:1020px){footer.sf .sf-cols{grid-template-columns:1fr 1fr}footer.sf .sf-tiles{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){footer.sf .sf-cols,footer.sf .sf-tiles{grid-template-columns:1fr 1fr}footer.sf .sf-cols>div:first-child{grid-column:1/-1}}
</style>
<footer class="sf"><div class="wrap">
    <div class="sf-cols">
        <div>
            <a href="<?= $sfHome === '' ? '#' : $sfE($sfHome) ?>"><img src="images/logo.png" alt="SmartPRS" style="height:40px;width:auto;display:block"></a>
            <p class="sf-blurb"><?= $sfE($sfAbout) ?></p>
            <?php if ($sfAddress !== ''): ?><p class="sf-addr"><?= nl2br($sfE(str_replace(' · ', "\n", $sfAddress))) ?></p><?php endif; ?>
        </div>
        <div><h5>Product</h5>
            <a class="lk" href="<?= $sfHome ?>#how">How it works</a><a class="lk" href="<?= $sfHome ?>#product">Modules</a>
            <a class="lk" href="<?= $sfHome ?>#qr">QR Attendance</a><a class="lk" href="<?= $sfHome ?>#customise">Customise</a>
            <a class="lk" href="<?= $sfHome ?>#security">Security</a><a class="lk" href="<?= $sfHome ?>#pricing">Pricing</a></div>
        <div><h5>Get started</h5>
            <a class="lk" href="demo.php">Explore Demo</a><a class="lk" href="<?= $sfHome ?>#contact">Book a demo</a>
            <a class="lk" href="app.php?r=signin">Sign in</a><?php if (isset($sfPolicies['faqs'])): ?><a class="lk" href="faqs">FAQs</a><?php endif; ?>
            <?php if ($sfEmail !== ''): ?><a class="lk" href="mailto:<?= $sfE($sfEmail) ?>">Contact us</a><?php endif; ?></div>
        <div><h5>Company</h5>
            <?php foreach ($sfPolicies as $sfSlug => $sfPg): if ($sfSlug === 'faqs') { continue; } ?>
            <a class="lk" href="<?= $sfE((string) $sfSlug) ?>"><?= $sfE((string) ($sfPg['link'] ?? $sfPg['title'] ?? $sfSlug)) ?></a>
            <?php endforeach; ?></div>
    </div>
    <div class="sf-family"><h5>More from Ametecs</h5>
        <div class="sf-tiles">
            <?php foreach ($sfFamily as [$u, $col, $img, $word, $sub]): ?>
            <a class="sf-tile" style="--sf-c:<?= $col ?>" href="<?= $u ?>" target="_blank" rel="noopener">
                <?php if ($img !== ''): ?><img src="<?= $img ?>" alt="<?= strip_tags($word) ?>" loading="lazy"><?php else: ?><span class="sf-word"><?= $word ?></span><?php endif; ?>
                <small><?= $sub ?></small></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="sf-bottom">
        <span>&copy; <?= date('Y') ?> Ametecs India Private Limited. All rights reserved.</span>
        <span><?php foreach ($sfPhones as $i => $ph): ?><?= $i ? ' · ' : '' ?><a href="tel:<?= $sfE((string) preg_replace('/[^\d+]/', '', $ph)) ?>" style="white-space:nowrap"><?= $sfE($ph) ?></a><?php endforeach; ?><?php if ($sfEmail !== ''): ?><?= $sfPhones ? ' · ' : '' ?><a href="mailto:<?= $sfE($sfEmail) ?>"><?= $sfE($sfEmail) ?></a><?php endif; ?></span>
    </div>
</div></footer>
