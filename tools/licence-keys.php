<?php
/**
 * THE LICENCE SIGNING KEY PAIR (SmartPRS 2's smartprs:make-license-keys).
 *
 *   php tools/licence-keys.php status     is storage/keys/license_private.pem present and does it
 *                                          match the public key built into surface/Licence.php?
 *   php tools/licence-keys.php generate   make a NEW pair (refuses if one exists; --force replaces)
 *
 * The private key stays on the licence server (app.smartprs.com) only — storage/ is never
 * in git. Generating a new pair means pasting the printed public key into
 * surface/Licence.php::PUBLIC_KEY and shipping that build: every .lic issued before stops
 * verifying. Do it once.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/Licence.php';

$dir = __DIR__ . '/../storage/keys';
$priv = $dir . '/license_private.pem';
$cmd = $argv[1] ?? 'status';

if ($cmd === 'status') {
    if (!is_readable($priv)) {
        echo "No private key at storage/keys/license_private.pem — .lic files cannot be signed here.\n";
        exit(1);
    }

    $key = openssl_pkey_get_private((string) file_get_contents($priv));
    $pub = $key === false ? '' : trim((string) openssl_pkey_get_details($key)['key']);
    echo $pub === trim(SmartPRS\Surface\Licence::PUBLIC_KEY) ? "OK — private key present and matches the built-in public key.\n"
        : "MISMATCH — this private key does not belong to the public key in surface/Licence.php.\n";
    exit($pub === trim(SmartPRS\Surface\Licence::PUBLIC_KEY) ? 0 : 1);
}

if ($cmd === 'generate') {
    if (is_file($priv) && !in_array('--force', $argv, true)) {
        fwrite(STDERR, "A key already exists. --force replaces it and invalidates every .lic already issued.\n");
        exit(1);
    }

    @mkdir($dir, 0700, true);
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    file_put_contents($priv, $privatePem);
    @chmod($priv, 0600);
    echo "Private key written to storage/keys/license_private.pem (keep it here, back it up, never commit it).\n";
    echo "Paste this into surface/Licence.php PUBLIC_KEY:\n\n" . openssl_pkey_get_details($res)['key'] . "\n";
    exit(0);
}

fwrite(STDERR, "Usage: php tools/licence-keys.php status|generate [--force]\n");
exit(1);
