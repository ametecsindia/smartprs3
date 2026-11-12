<?php
/**
 * Create (or reset the password of) a Super Admin login from the server's shell:
 *
 *   php tools/make-super-admin.php you@ametecsindia.com "Your Name"
 *
 * Prints a one-time password. Sign in at https://app.smartprs.com/super and change it
 * under Platform Staff. The web alternative is the first-run token on /super.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || filter_var($argv[1], FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php tools/make-super-admin.php email@example.com \"Name\"\n");
    exit(1);
}

require __DIR__ . '/../surface/partials/endpoint-boot.php';

$email = strtolower($argv[1]);
$password = substr(strtr(base64_encode(random_bytes(15)), '+/=', 'kQz'), 0, 16);
$hash = SmartPRS\Platform\Identity\PasswordHasher::platformDefault()->hash($password);
$q = $pdo->prepare('SELECT id FROM platform_users WHERE LOWER(email) = ?');
$q->execute([$email]);
$id = $q->fetchColumn();

if ($id !== false) {
    $pdo->prepare('UPDATE platform_users SET password = ?, disabled_at = NULL, updated_at = NOW() WHERE id = ?')->execute([$hash, $id]);
    echo "Password reset for {$email}\n";
} else {
    $pdo->prepare('INSERT INTO platform_users (name, email, password, role, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
        ->execute([$argv[2] ?? 'Super Admin', $email, $hash, 'super_admin']);
    echo "Super Admin created: {$email}\n";
}

echo "One-time password: {$password}\nSign in at /super and change it under Platform Staff.\n";
