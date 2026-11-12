<?php

declare(strict_types=1);

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\ApiCredential;
use SmartPRS\Surface\Store;

/**
 * THE FIRST THING /api/v3 DOES, AND EVERY WAY IT MUST SAY NO.
 *
 * `api_keys` has been in the ported schema since day one and nothing had ever
 * read a row of it, because there was no HTTP endpoint in this product at all.
 * `ApiCredential` is the class that finally does, and it is the only thing
 * standing between a bearer token and a tenant's data — so every one of its
 * refusals is asserted here BY ITS SENTENCE, not by a boolean.
 *
 * The refusals are asserted as DIFFERENT sentences on purpose. A class that
 * returned `['ok' => false]` from every path would pass a test suite that only
 * checked `ok`, and would be indistinguishable from one that had stopped
 * checking anything — the non-vacuity section at the bottom is what makes that
 * impossible: it collects every refusal reason this file produced and asserts
 * they are all distinct, and that the one credential that should work does.
 *
 * NEVER `assert()`. `zend.assertions` is off in this build and a file written
 * with it passes without executing a single check — that has already happened in
 * this repository once.
 */

group('api credential');

$directory = sys_get_temp_dir() . '/prs3-apicred-' . bin2hex(random_bytes(4));
$platform = new Store($directory);
$hasher = PasswordHasher::platformDefault();
$credentials = new ApiCredential($platform, $hasher);

register_shutdown_function(static function () use ($directory): void {
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($directory);
});

/** Put a credential row in, exactly as an administrator's mint would. */
$issue = static function (string $tenant, array $scopes, array $overrides = [])
    use ($platform): array {
    $minted = ApiCredential::mint('probe', $scopes, $overrides['expires_at'] ?? null);
    unset($overrides['expires_at']);

    $platform->put(ApiCredential::COLLECTION,
        array_merge($minted['row'], ['tenant_id' => $tenant], $overrides));

    return $minted;
};

// ----------------------------------------------------------------- it works
$good = $issue('T-A', ['employments.read', 'attendance.write']);
$accepted = $credentials->authenticate('Bearer ' . $good['token']);

ok($accepted['ok'], 'a live credential authenticates');
same('T-A', $accepted['tenant_id'],
    'and the tenant comes from the credential ROW — never from a header, a path or a body, because '
    . 'the moment a tenant is addressable it is forgeable');
same(['employments.read', 'attendance.write'], $accepted['scopes'], 'with the scopes it was minted with');

// THE TENANT IS THE ROW'S, AND A SECOND CREDENTIAL PROVES IT IS READ AND NOT
// ASSUMED. One tenant id hard-coded in the class would pass every assertion above.
$other = $issue('T-B', ['employments.read']);
same('T-B', $credentials->authenticate('Bearer ' . $other['token'])['tenant_id'],
    'a second credential resolves to ITS tenant, so the resolution is a read rather than a constant');

// ------------------------------------------------------------ every refusal
$refusals = [];

$refuses = static function (?string $header, string $expected, string $message)
    use ($credentials, &$refusals): void {
    $answer = $credentials->authenticate($header);

    ok(!$answer['ok'] && str_contains($answer['why'], $expected),
        $message . ' (said "' . substr($answer['why'], 0, 60) . '…")');

    // NEVER A PARTIAL ANSWER. A refusal that still carries a tenant or a scope is
    // one a caller could act on, and the caller is the endpoint.
    ok($answer['tenant_id'] === '' && $answer['scopes'] === [],
        $message . ' — and carries no tenant and no scope with it');

    // Keyed by cause, so the non-vacuity check below can tell "two inputs, one
    // cause" (a null and an empty header ARE the same refusal) apart from "two
    // causes, one sentence", which is the thing that must never happen.
    $refusals[$expected][] = $answer['why'];
};

$refuses(null, 'No credential was presented',
    'no credential at all is refused: an endpoint served without one is open by omission');
$refuses('', 'No credential was presented', 'and an empty header is the same answer');
$refuses('Basic YWRtaW46YWRtaW4=', 'is not a bearer token',
    'a non-bearer scheme is refused rather than interpreted');
$refuses('Bearer wrong-shape', 'not the shape of a token this API issues',
    'a token of the wrong shape is refused before any lookup');
$refuses('Bearer sprs3_' . bin2hex(random_bytes(6)) . '_' . bin2hex(random_bytes(24)),
    'No credential carries that prefix', 'an unknown prefix is refused');

[$prefix] = [explode('_', $good['token'])[1]];
$refuses('Bearer sprs3_' . $prefix . '_' . bin2hex(random_bytes(24)),
    'does not match the stored hash',
    'the right prefix with the wrong secret is refused — the prefix selects the row, the hash '
    . 'decides, and the comparison is constant time');

$inactive = $issue('T-A', ['employments.read'], ['active' => 0]);
$refuses('Bearer ' . $inactive['token'], 'not active',
    'an inactive credential is refused: revocation that keeps working for reads is not revocation');

$expired = $issue('T-A', ['employments.read'],
    ['expires_at' => (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
$refuses('Bearer ' . $expired['token'], 'expired on',
    'an expired credential is refused, and the refusal says when it expired');

$unreadableExpiry = $issue('T-A', ['employments.read'], ['expires_at' => 'whenever']);
$refuses('Bearer ' . $unreadableExpiry['token'], 'expiry could not be read',
    'an unreadable expiry is refused rather than read as "no expiry", which would turn a corrupt '
    . 'date into a permanent key');

$unreadableScopes = $issue('T-A', ['employments.read'], ['scopes' => 'not-json-at-all']);
$refuses('Bearer ' . $unreadableScopes['token'], 'scopes could not be read',
    'a credential whose scopes cannot be read is refused, not treated as holding none — an '
    . 'unreadable grant is a broken row and a 403 sends somebody looking for a permission instead');

$wrongScopeType = $issue('T-A', ['employments.read'], ['scopes' => [1, 2, 3]]);
$refuses('Bearer ' . $wrongScopeType['token'], 'scopes could not be read',
    'and scopes that are not strings are the same refusal');

$noTenant = $issue('T-A', ['employments.read'], ['tenant_id' => '']);
$refuses('Bearer ' . $noTenant['token'], 'names no tenant',
    'a credential with no tenant is refused: a store built without one reads every tenant\'s rows');

// TWO ROWS, ONE PREFIX. Refused rather than resolved by taking the first, exactly
// as PunchImport refuses an enrolment number that is on two employments: picking
// either decides whose tenant the caller lands in by row order.
$twin = ApiCredential::mint('twin', ['employments.read']);
$twin['row']['prefix'] = $prefix;
$platform->put(ApiCredential::COLLECTION, array_merge($twin['row'], ['tenant_id' => 'T-C']));
$refuses('Bearer ' . $good['token'], 'More than one credential carries that prefix',
    'two credentials sharing a prefix are refused rather than resolved by row order');

// ---------------------------------------------------------------- the minting
$fresh = ApiCredential::mint('payroll bridge', ['employments.read']);

ok(preg_match(ApiCredential::TOKEN_PATTERN, $fresh['token']) === 1,
    'a minted token has the published shape');
ok(!isset($fresh['row']['token']) && !str_contains(json_encode($fresh['row'], JSON_THROW_ON_ERROR),
    explode('_', $fresh['token'])[2]),
    'and the STORED ROW does not contain the secret anywhere — a leaked database is not a leaked '
    . 'key ring');
ok($hasher->verify(explode('_', $fresh['token'])[2], (string) $fresh['row']['key_hash']),
    'the stored hash is the platform hasher\'s, so a key and a password are not two ideas of what '
    . 'a hash is');
ok(ApiCredential::mint('x', ['a'])['token'] !== ApiCredential::mint('x', ['a'])['token'],
    'two mints are two tokens');

throws(InvalidArgumentException::class, static fn () => ApiCredential::mint('', ['a']),
    'an unnamed credential is refused: nobody revokes with confidence a key they cannot identify');
throws(InvalidArgumentException::class, static fn () => ApiCredential::mint('x', []),
    'a credential with no scopes is refused at the moment it is minted rather than discovered as a '
    . '403 on the integration\'s first request');

// ------------------------------------------------------------- NON-VACUITY
// Everything above asserts a refusal. A class that refused EVERYTHING — including
// the good credential — would satisfy all of them, and so would one that returned
// the same sentence every time. Both are excluded here, out loud.
group('api credential — non-vacuity');

ok($credentials->authenticate('Bearer ' . $other['token'])['ok'],
    'a valid credential is still accepted after every refusal above, so the refusals are decisions '
    . 'rather than a class that says no to everybody');

$sentences = array_map(static fn (array $said): string => $said[0], $refusals);

same(count($refusals), count(array_unique($sentences)),
    'each of the ' . count($refusals) . ' causes gave a DIFFERENT sentence, so no two of them were '
    . 'collapsed into one an operator cannot act on');

foreach ($refusals as $cause => $said) {
    same(1, count(array_unique($said)),
        "every input refused for '{$cause}' got the same sentence, so the reason belongs to the "
        . 'cause rather than to the input');
}

$secret = explode('_', $good['token'])[2];

foreach ($sentences as $cause => $why) {
    ok(!str_contains($why, $secret) && !str_contains($why, $good['token']),
        "the '{$cause}' refusal does not echo the token or its secret back");
}

ok(!str_contains(implode(' ', $sentences), (string) $fresh['row']['key_hash']),
    'and no refusal carries a stored hash');
