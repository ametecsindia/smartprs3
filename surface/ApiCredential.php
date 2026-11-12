<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Identity\PasswordHasher;

/**
 * WHO IS CALLING, AND WHICH TENANT ARE THEY? — the `api_keys` table, finally used.
 *
 * `api_keys` has carried `tenant_id, name, prefix, key_hash, scopes, last_used_at,
 * expires_at, active` since the port and NOTHING has ever read a row of it. There
 * is no HTTP surface in this product at all, so a biometric device's middleware
 * cannot push a punch: attendance arrives only when a person uploads a file. This
 * class is the first half of closing that — the half that decides whether the
 * caller is anybody.
 *
 * FOUR RULES, and each one is a way this normally goes wrong.
 *
 *   THE STORED FORM IS A HASH, NEVER THE TOKEN. `key_hash` holds what
 *   `PasswordHasher` — the platform's own, the same one sign-in uses — makes of
 *   the secret. A leaked database is not a leaked key ring. Nothing in this class
 *   returns, logs or echoes a token or a hash, and `mint()` is the only place a
 *   token exists as plaintext: it hands it back ONCE, to be shown once, and the
 *   caller stores the row rather than the token.
 *
 *   THE ROW IS FOUND BY ITS `prefix`, AND THE HASH IS COMPARED ONCE. A token is
 *   `sprs3_<12 hex prefix>_<48 hex secret>`; the prefix is a public label, so it
 *   selects the row and the expensive constant-time comparison happens against
 *   that row's hash alone. Verifying against every row in the table would make
 *   authentication cost O(keys) — a denial of service anybody can trigger with a
 *   made-up token — and it would still be one comparison per row, so it buys
 *   nothing. `PasswordHasher::verify()` is `password_verify()` inside, which is
 *   `hash_equals()`; and an unknown prefix still BURNS a verify against a dummy
 *   hash of the same parameters, so "no such key" costs what "wrong secret" costs.
 *
 *   THE TENANT COMES FROM THE ROW AND FROM NOWHERE ELSE (R-XXIV-001). Not from a
 *   header, not from a path segment, not from the body. `RouteGrammar` refuses to
 *   register a route whose URL even MENTIONS a tenant, for the same reason: the
 *   moment a tenant is addressable it is forgeable, and SmartPRS 2 has queries
 *   where NULL and 0 mean "all tenants".
 *
 *   EVERY REFUSAL IS TOTAL AND HAS A STATED REASON. There is no partial success —
 *   no "authenticated but scope-less", no "expired but let it through this once".
 *   The reason is for the SERVER LOG and for the sweep; what goes on the wire is
 *   the single uniform `auth.unauthenticated`, because the difference between "no
 *   such prefix" and "wrong secret" tells an attacker which half of the guess was
 *   right (R-XXII-193, and Session.php says the same thing about a login form).
 *
 * ponytail: the lookup reads `api_keys` through `RowStore::all()` and matches the
 * prefix in PHP, because `RowStore` has no findBy — a tenant has a handful of
 * keys and this is a table with tens of rows, not millions. The ceiling is one
 * whole-table read per authenticated request; the upgrade path is a named
 * `SqlStore` method with `WHERE prefix = ?` and an index on it, and nothing above
 * this class changes when it lands.
 */
final class ApiCredential
{
    /** The ported table. There is no second one. */
    public const COLLECTION = 'api_keys';

    /** `sprs3_<prefix>_<secret>`. The prefix is a label; the secret is the password. */
    public const TOKEN_PATTERN = '/^sprs3_([0-9a-f]{12})_([0-9a-f]{48})$/';

    public function __construct(
        private readonly RowStore $store,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /**
     * A NEW CREDENTIAL: the token, once, and the row to store.
     *
     * The token is returned and never stored; the row carries only the hash. The
     * caller shows the token to the administrator on the screen that minted it
     * and has nowhere to get it back from afterwards — which is the property that
     * makes "we can email you your key" impossible, and that is the point.
     *
     * @param list<string> $scopes what this credential may do, deny-by-default
     * @param ?string $expiresAt 'Y-m-d H:i:s', or null for a credential that does not expire
     * @return array{token: string, prefix: string, row: array<string, mixed>}
     */
    public static function mint(string $name, array $scopes, ?string $expiresAt = null): array
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException(
                'A credential must be named. An unnamed key is one nobody can revoke with '
                . 'confidence six months later, so the revoke that should have happened does not.'
            );
        }

        if ($scopes === []) {
            throw new \InvalidArgumentException(
                'A credential must declare at least one scope. A token that holds nothing can call '
                . 'nothing, and minting one is a mistake worth refusing at the moment it is made '
                . 'rather than discovering as a 403 on the integration\'s first request.'
            );
        }

        $prefix = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(24));

        return [
            'token' => 'sprs3_' . $prefix . '_' . $secret,
            'prefix' => $prefix,
            'row' => [
                'name' => $name,
                'prefix' => $prefix,
                // The platform's own hasher, at the platform's own parameters —
                // the same object sign-in verifies against, so a key and a
                // password are never two different ideas of what a hash is.
                'key_hash' => PasswordHasher::platformDefault()->hash($secret),
                'scopes' => array_values($scopes),
                'expires_at' => $expiresAt,
                'active' => 1,
            ],
        ];
    }

    /**
     * Is this request from a credential, and whose?
     *
     * @param ?string $authorization the raw `Authorization` header, or null
     * @return array{ok: bool, why: string, tenant_id: string, credential_id: string,
     *               name: string, scopes: list<string>}
     */
    public function authenticate(?string $authorization, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $presented = trim((string) $authorization);

        if ($presented === '') {
            return self::no('No credential was presented. This API has no anonymous read: the '
                . 'Authorization header must carry `Bearer <token>`, and a request without one is '
                . 'refused rather than served whatever needs no permission — an endpoint that is '
                . 'open by omission is the most common hole an API grows.');
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $presented, $bearer) !== 1) {
            return self::no('The Authorization header is present and is not a bearer token. It is '
                . 'refused rather than interpreted: guessing at a second credential format is how a '
                . 'scheme nobody reviewed becomes a way in.');
        }

        if (preg_match(self::TOKEN_PATTERN, $bearer[1], $parts) !== 1) {
            // No verify is burned here and there is no oracle in that: no token of
            // this shape has ever been issued, so there is no pair of inputs whose
            // timing could be compared to learn anything about a real key.
            return self::no('That is not the shape of a token this API issues, so there is no '
                . 'prefix to look up and nothing to compare a secret against.');
        }

        [, $prefix, $secret] = $parts;

        // The whole table, matched in PHP — see the ponytail note on the class.
        // Read through the UNSCOPED store on purpose: the tenant is the ANSWER to
        // this lookup, so it cannot also be its filter.
        $candidates = [];

        foreach ($this->store->all(self::COLLECTION) as $row) {
            if (hash_equals((string) ($row['prefix'] ?? ''), $prefix)) {
                $candidates[] = $row;
            }
        }

        if (count($candidates) > 1) {
            // Refused rather than resolved by taking the first, exactly as
            // PunchImport refuses an enrolment number on two employees: two rows
            // sharing a prefix means one of them will be picked, and which one
            // decides whose tenant the caller lands in.
            $this->hasher->verify($secret, null);

            return self::no('More than one credential carries that prefix. Picking either would '
                . 'decide which tenant the caller lands in by row order, so it is refused.');
        }

        $row = $candidates[0] ?? null;

        // ONE verify, always. `verify($secret, null)` burns a real verification
        // against a dummy hash of the same parameters, so an unknown prefix costs
        // what a wrong secret costs and neither is faster to guess.
        $matched = $this->hasher->verify($secret, isset($row['key_hash'])
            ? (string) $row['key_hash'] : null);

        if ($row === null) {
            return self::no('No credential carries that prefix.');
        }

        if (!$matched) {
            return self::no('The secret does not match the stored hash for that prefix.');
        }

        // A MariaDB boolean arrives as 0/1 and a never-set one arrives as NULL.
        // NULL is NOT active: `active` is nullable in the ported schema, and
        // reading an unset flag as "on" would make a half-created row a working
        // key. Session::enrolmentToken() carries the same lesson in the other
        // direction — a strict comparison against `false` is true of neither.
        if (!(bool) ($row['active'] ?? false)) {
            return self::no('That credential is not active. An inactive key is refused outright '
                . 'rather than allowed a read: revoking a key that keeps working for reads is not '
                . 'revoking it.');
        }

        $expires = trim((string) ($row['expires_at'] ?? ''));

        if ($expires !== '') {
            $expiry = date_create_immutable($expires);

            if ($expiry === false) {
                return self::no('That credential\'s expiry could not be read, so whether it has '
                    . 'expired is unknown — and an unknown expiry is refused rather than treated as '
                    . '"no expiry", which would make a corrupt date a permanent key.');
            }

            if ($expiry <= $now) {
                return self::no('That credential expired on ' . $expiry->format('Y-m-d H:i:s') . '.');
            }
        }

        $scopes = self::scopes($row['scopes'] ?? null);

        if ($scopes === null) {
            return self::no('That credential\'s scopes could not be read, so what it may do cannot '
                . 'be stated. Refused rather than treated as holding none: an unreadable grant is a '
                . 'configuration fault and answering it with a 403 sends somebody looking for a '
                . 'permission instead of for the broken row.');
        }

        $tenant = trim((string) ($row['tenant_id'] ?? ''));

        if ($tenant === '') {
            return self::no('That credential names no tenant, so there is nothing to scope its '
                . 'reads to. A store built with no tenant reads every tenant\'s rows, which is the '
                . 'SmartPRS 2 finding this rebuild exists to remove.');
        }

        return [
            'ok' => true,
            'why' => 'authenticated',
            'tenant_id' => $tenant,
            'credential_id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'scopes' => $scopes,
        ];
    }

    /**
     * Record that a key was used, so an administrator can see which are dead.
     *
     * Written through a TENANT-SCOPED store, so this cannot be the one write that
     * forgets. Best effort by design: the caller decides whether a failure here
     * should fail the request, and the answer is no — an audit stamp that can
     * refuse an authenticated read is a stamp that takes the API down.
     */
    public function markUsed(RowStore $tenantStore, string $credentialId, ?string $at = null): void
    {
        if ($credentialId === '') {
            return;
        }

        $tenantStore->put(self::COLLECTION, [
            'id' => $credentialId,
            'last_used_at' => $at ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The `scopes` column as a list of strings, or NULL when it is not readable.
     *
     * NULL and "no scopes" are different answers and must not be collapsed: the
     * first is a broken row and the second is a key that may do nothing. MySQL
     * hands a JSON column back as a string; the file store hands back the array.
     *
     * @return ?list<string>
     */
    private static function scopes(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = $decoded === null && trim($raw) !== 'null' ? false : $decoded;
        }

        if (!is_array($raw)) {
            return null;
        }

        $scopes = [];

        foreach ($raw as $scope) {
            if (!is_string($scope) || trim($scope) === '') {
                return null;
            }

            $scopes[] = trim($scope);
        }

        return $scopes;
    }

    /** @return array{ok: bool, why: string, tenant_id: string, credential_id: string, name: string, scopes: list<string>} */
    private static function no(string $why): array
    {
        return ['ok' => false, 'why' => $why, 'tenant_id' => '', 'credential_id' => '',
            'name' => '', 'scopes' => []];
    }
}
