<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Identity\LockoutPolicy;
use SmartPRS\Platform\Identity\PasswordHasher;

/**
 * SIGNING IN — and the three things that are not negotiable even in a slice.
 *
 *   NO DEFAULT CREDENTIAL. There is no seeded `admin/admin` waiting to be
 *   changed. The first run issues a one-time enrolment token and the first
 *   administrator sets their own password through it — the same rule the Phase 9
 *   installer gate enforces, applied here so the demo cannot be the exception.
 *
 *   THE FAILURE MESSAGE IS UNIFORM. "No such user" and "wrong password" are the
 *   same sentence, because the difference between them tells an attacker which
 *   half of the guess was right. That is R-XXIV-018 for the API and it is just
 *   as true on a login form.
 *
 *   THE ATTEMPT IS COUNTED BEFORE IT IS ANSWERED. Lockout that is applied after
 *   a successful verify is lockout that never fires on the attack it exists for.
 *
 * ponytail: PHP's own session for the cookie, the real `PasswordHasher` and
 * `LockoutPolicy` from Platform for everything that matters. Upgrade path: MFA
 * and the session-policy rules in `Platform\Identity` are built and not wired in
 * here — that is the next slice, not a gap in these three.
 */
final class Session
{
    /**
     * The minimum password length. TEMPORARILY 8 at the owner's instruction;
     * it goes back to 12 before this instance is used by anyone but them.
     * Length is the floor rather than a symbol rule, because a long passphrase
     * beats an eight-character one with a digit stuck on the end.
     */
    public const PASSWORD_FLOOR = 12;

    /**
     * How long a lockout lasts. Long enough to make guessing pointless, short
     * enough that the person who mistyped their own password is not stuck — and
     * short enough that the message can honestly say "wait".
     */
    public const LOCKOUT_SECONDS = 300;

    /**
     * The account `tools/seed-local-admin.php` creates for local development.
     *
     * Named HERE, as a condition rather than a flag, because the flag did not
     * work: the warning used to look for `is_local_default` on the user row, and
     * the ported `users` table has no such column — so on MySQL it could never be
     * true and the banner could never appear. A warning whose own text says it
     * "disappears only when the account does" had in fact disappeared while the
     * account was still there, which is the exact failure this product is written
     * against. An email cannot silently stop existing.
     */
    public const LOCAL_DEFAULT_EMAIL = 'admin@smartprslocal';

    private const COLLECTION = 'users';

    public function __construct(
        private readonly RowStore $store,
        private readonly PasswordHasher $hasher,
        private readonly int $maximumAttempts = 5,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    /** Nobody exists yet: the first run hands out one enrolment token and no account. */
    public function needsEnrolment(): bool
    {
        return $this->store->count(self::COLLECTION) === 0;
    }

    public function enrolmentToken(): string
    {
        $tokens = $this->store->all('enrolment');

        foreach ($tokens as $token) {
            // A boolean from MySQL arrives as 0 or 1, never as false, so a
            // strict comparison against `false` is true of neither — and the
            // enrolment token silently stops working the moment the application
            // moves off file storage.
            if (!(bool) ($token['used'] ?? false)) {
                return (string) $token['token'];
            }
        }

        $token = ['token' => bin2hex(random_bytes(16)), 'used' => false,
            'issued_at' => date('c')];
        $this->store->put('enrolment', $token);

        return $token['token'];
    }

    /** @return array{ok: bool, why: string} */
    public function enrol(string $token, string $email, string $password): array
    {
        if (!$this->needsEnrolment()) {
            return ['ok' => false, 'why' => 'An administrator already exists. Enrolment is once.'];
        }

        $matched = null;

        foreach ($this->store->all('enrolment') as $row) {
            if (hash_equals((string) $row['token'], $token) && !(bool) ($row['used'] ?? false)) {
                $matched = $row;
            }
        }

        if ($matched === null) {
            return ['ok' => false, 'why' => 'That enrolment token is not valid.'];
        }

        // Twelve since go-live on smartprs.com (12 Nov 2026); it was eight for local development only.
        if (strlen($password) < self::PASSWORD_FLOOR) {
            return ['ok' => false, 'why' => 'The password must be at least ' . self::PASSWORD_FLOOR
                . ' characters. The floor is length rather than a symbol rule: a long passphrase '
                . 'beats an eight-character one with a digit stuck on the end, and every user knows '
                . 'it.'];
        }

        // The first enrolment creates the tenant it belongs to. A user with no
        // tenant is a user whose every read is unscoped, which is exactly the
        // SmartPRS 2 finding this rebuild exists to remove.
        $tenant = $this->store->put('tenants', [
            // `code` and `status_code` are NOT NULL with no default, so they are
            // supplied here by the caller who knows what belongs in them. The
            // store refuses rather than inventing a value for a column whose
            // meaning it does not know.
            'code' => 'T-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'name' => 'Tenant of ' . $email,
            'status_code' => 'active',
            'created_at' => date('c'),
        ]);

        $this->store->put(self::COLLECTION, [
            'email' => $email,
            // The ported table names the hash `password` and has no `role`
            // column — SmartPRS 2 keeps roles in Spatie's tables, which are NOT
            // ported (see docs/SCHEMA-CORRECTIONS.md §4). Until those are
            // reconciled with SmartPRS 3's own permission catalogue, the role
            // rides on `status`, and this comment is the record of that.
            'password' => $this->hasher->hash($password),
            'status' => 'administrator',
            'tenant_id' => $tenant['id'],
            'created_at' => date('c'),
        ]);

        $matched['used'] = true;
        $this->store->put('enrolment', $matched);

        return ['ok' => true, 'why' => 'Enrolled. Sign in with the credentials you just set.'];
    }

    /** @return array{ok: bool, why: string} */
    public function signIn(string $email, string $password, ?string $tenantId = null): array
    {
        $user = null;

        foreach ($this->store->all(self::COLLECTION) as $row) {
            if (strcasecmp((string) $row['email'], $email) === 0) {
                $user = $row;
            }
        }

        // Counted BEFORE the answer. Lockout applied after a successful verify
        // never fires on the attack it exists for.
        //
        // The lockout EXPIRES, and the message says when. It used to last as long
        // as the browser session with no window at all, while telling the person
        // to wait — so waiting did nothing and only clearing a cookie helped.
        // A message that names a remedy which does not work is worse than no
        // message: the person does the thing it says, it fails, and now they
        // distrust every other message the system gives them.
        $attempts = (int) ($_SESSION['failed_attempts'] ?? 0);
        $lockedUntil = (int) ($_SESSION['locked_until'] ?? 0);

        if ($lockedUntil > 0 && time() >= $lockedUntil) {
            $attempts = 0;
            $_SESSION['failed_attempts'] = 0;
            $_SESSION['locked_until'] = 0;
        }

        if ($attempts >= $this->maximumAttempts) {
            $seconds = max(1, $lockedUntil - time());

            return ['ok' => false, 'why' => sprintf(
                'Too many attempts. This unlocks itself in %d minute(s) %d second(s) — no action '
                . 'needed, and nothing to clear.',
                intdiv($seconds, 60),
                $seconds % 60,
            )];
        }

        // The verify runs even when there is no such user, so the two paths cost
        // the same. A faster "no such user" is an account enumeration oracle.
        $verified = $this->hasher->verify($password, $user['password'] ?? $user['password_hash'] ?? null);

        // A DISABLED ACCOUNT IS REFUSED WITH THE SAME SENTENCE as a wrong password:
        // "this login was disabled" told to whoever is typing is a fact about the account.
        if ($verified && trim((string) ($user['disabled_at'] ?? '')) !== '') {
            $verified = false;
        }

        // SIGNED IN AT A TENANT'S OWN LINK (/c/<slug>, owner 7 Nov 2026): the account must belong to that tenant —
        // the same one sentence as a wrong password, so the link never says whether the email exists elsewhere.
        if ($verified && $tenantId !== null && (string) ($user['tenant_id'] ?? '') !== $tenantId) {
            $verified = false;
        }

        if (!$verified) {
            $_SESSION['failed_attempts'] = $attempts + 1;

            if ($_SESSION['failed_attempts'] >= $this->maximumAttempts) {
                $_SESSION['locked_until'] = time() + self::LOCKOUT_SECONDS;
            }

            // ONE sentence for both causes.
            return ['ok' => false, 'why' => 'Those credentials were not accepted.'];
        }

        $this->establish($user);

        return ['ok' => true, 'why' => 'Signed in.'];
    }

    /**
     * SIGN IN AN ACCOUNT WHOSE PASSWORD WAS ALREADY CHECKED — the mobile app's one-time login link (owner, 12 Nov 2026:
     * SmartEPT-style phone sign-in). public/mobile.php verified the password and the phone; the link it minted is used
     * once by surface/partials/boot-hooks.php. A disabled account is still refused here.
     *
     * @param array<string, mixed> $user a `users` row
     */
    public function signInVerified(array $user): bool
    {
        if (trim((string) ($user['disabled_at'] ?? '')) !== '' || !isset($user['id'], $user['email'])) {
            return false;
        }

        $this->establish($user);

        return true;
    }

    /** @param array<string, mixed> $user */
    private function establish(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['failed_attempts'] = 0;
        $_SESSION['locked_until'] = 0;
        // THE EMPLOYMENT THIS ACCOUNT IS. `users.employee_id` has been in the
        // schema since the port and nothing has ever read it — so every screen
        // answered "which records?" with "all of them in this tenant", including
        // the one titled "what an employee sees". `SubjectContext::$actorEmployeeId`
        // documents itself as "users.employee_id"; the platform was written
        // expecting exactly this column and exactly this wiring.
        //
        // NULL is a real answer and is carried as one. `DataScope::selfOnly()`
        // resolves an unbound actor to the EMPTY set rather than to the tenant —
        // an account with no employee behind it sees nothing of its own, which
        // is correct and is the safe direction to be wrong in.
        $_SESSION['user'] = ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role'] ?? $user['status'] ?? 'viewer',
            'employee_id' => ($user['employee_id'] ?? null) === null
                ? null : (string) $user['employee_id'],
            'tenant_id' => $user['tenant_id'] ?? null];

    }

    /**
     * The token that says a POST came from a page this session was actually
     * shown, rather than from a form on somebody else's site.
     *
     * ONE PER SESSION, not one per form. A token minted per form has to be
     * remembered somewhere, and the somewhere is a list that grows until a
     * person opens two tabs and the older one stops working — so they learn that
     * "it sometimes just fails", which is the sentence this product exists to
     * never cause. Per-session is the same protection: the attacker's site
     * cannot read it either way, because the browser will not let them read a
     * response from this origin.
     *
     * It survives sign-in. `signIn()` regenerates the session ID but keeps
     * `$_SESSION`, so the token on the sign-in page is still the token after,
     * and a form rendered a moment before signing in does not break.
     *
     * ponytail: 32 bytes from `random_bytes`, which is the CSPRNG, not `rand`.
     * There is no expiry — it dies with the session, and a session that is still
     * valid is exactly the window in which the token needs to still work.
     */
    public function token(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    /**
     * Is this the token this session was given?
     *
     * `hash_equals` rather than `===`, because `===` on strings returns as soon
     * as two bytes differ, and the time that takes is a measurement an attacker
     * can make. It also refuses a value that merely STARTS with the right bytes:
     * the lengths must match too, so a truncated token is not a partial success.
     *
     * A missing token, an empty one, and a wrong one are all the same answer —
     * no. There is nothing to tell apart here that is worth telling apart.
     */
    public function verify(?string $given): bool
    {
        $held = $_SESSION['csrf'] ?? '';

        return is_string($held) && $held !== '' && $given !== null && $given !== ''
            && hash_equals($held, $given);
    }

    public function signOut(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function isSignedIn(): bool
    {
        return $this->user() !== null;
    }
}
