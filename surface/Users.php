<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Identity\PasswordHasher;

/**
 * WHO MAY SIGN IN, AND AS WHAT.
 *
 * The `manager` role has existed since Authorisation declared it and Scope wired
 * it to `direct_reports`, and until this class there was no way to give it to
 * anybody: the only route to a second account was `tools/seed-local-admin.php`,
 * and the only route to a role was an UPDATE against `users.status` typed into
 * MySQL by hand. A role nobody can assign is a role nobody has, which makes every
 * guard behind it a guard that refuses the whole world — and that reads as a pass
 * in every sweep. This is the screen that makes the role real.
 *
 * THE ROLE LIVES ON `status`. The ported `users` table has no `role` column —
 * SmartPRS 2 keeps roles in Spatie's tables and those are not ported (see the
 * note in Session::enrol(), and docs/SCHEMA-CORRECTIONS.md §4). `Session::signIn`
 * reads the role off `status`, so this class writes it there and nowhere else: a
 * second place to store a role is a second answer to "what is this person",
 * and the two disagree the first week.
 *
 * THE TENANT FILTER IS WRITTEN OUT HERE, on purpose and against the habit of the
 * rest of the surface. `users` is on `Store::PLATFORM_SCOPED` — it has to be, a
 * user must be findable before there is a tenant to scope by — so a tenant-scoped
 * store hands back EVERY tenant's users from `all('users')`. Every read below
 * therefore filters, and the filter is one private method rather than a condition
 * repeated at six call sites, because the one that gets forgotten is the leak.
 *
 * Invitations, admin-set passwords, self-service password change (own current
 * password required), disable/enable and bind/unbind all live here now — see
 * the methods below. ponytail: no audit write; that belongs in `public/app.php`
 * where `$audit` already lives and every other write records itself through it.
 */
final class Users
{
    private const COLLECTION = 'users';

    public function __construct(
        private readonly RowStore $store,
        private readonly Authorisation $access,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /**
     * The tenant's accounts, each with the employee it is bound to.
     *
     * @return array{ok: bool, why: string, users: list<array<string, mixed>>}
     */
    public function all(): array
    {
        if ($this->tenantId() === null) {
            return ['ok' => false, 'why' => self::UNSCOPED, 'users' => []];
        }

        $users = [];

        foreach ($this->rows() as $row) {
            $boundTo = (string) ($row['employee_id'] ?? '');
            $employment = $this->employmentOf($row);

            $users[] = [
                'id' => (string) ($row['id'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                // Whatever is on `status`, including a value no role declares —
                // shown rather than corrected, because an account whose role this
                // build does not know grants nothing and the person looking at
                // this list is the only one who can fix it.
                'role' => $this->roleOf($row),
                'role_declared' => in_array($this->roleOf($row), $this->access->roles(), true),
                'employment_id' => $boundTo,
                // Code and name through Employee, which is the one place that
                // knows the ported column is `emp_code` and not `employee_code`.
                // A link that resolves to nothing SAYS SO rather than rendering as
                // an unlinked account: those are different problems — one is a
                // setting nobody made, the other is a row that has gone.
                'employment' => match (true) {
                    $boundTo === '' => '',
                    $employment === null => 'employee ' . $boundTo . ' is not in this organisation',
                    default => Employment::label($employment),
                },
                'bound' => $boundTo !== '',
                'disabled' => trim((string) ($row['disabled_at'] ?? '')) !== '',
                'is_local_default' => strcasecmp((string) ($row['email'] ?? ''),
                    Session::LOCAL_DEFAULT_EMAIL) === 0,
            ];
        }

        return ['ok' => true, 'why' => '', 'users' => $users];
    }

    /**
     * A new account.
     *
     * @return array{ok: bool, why: string, user?: array<string, mixed>}
     */
    public function create(string $email, string $password, string $role,
        string $employmentId = ''): array
    {
        if ($this->tenantId() === null) {
            return ['ok' => false, 'why' => self::UNSCOPED];
        }

        $email = trim($email);

        if ($email === '') {
            return ['ok' => false, 'why' => 'An account needs an email address. It is the only thing '
                . 'sign-in matches on, so an account without one is an account nobody can ever use '
                . 'and nobody can tell apart from the next one.'];
        }

        if ($this->byEmail($email) !== null) {
            return ['ok' => false, 'why' => sprintf(
                'There is already an account for %s in this organisation. Two accounts on one '
                . 'address make sign-in ambiguous — whichever row is read last wins, which is not '
                . 'a rule anybody can predict. If this person needs a different role, change the '
                . 'role on the account they already have.', $email)];
        }

        if (strlen($password) < Session::PASSWORD_FLOOR) {
            return ['ok' => false, 'why' => 'The password must be at least ' . Session::PASSWORD_FLOOR
                . ' characters — the same floor the sign-in screen enforces. An account created '
                . 'below it would be one the person could never re-set to what it is now, and the '
                . 'floor being lower on the create form than on the login form is how a weak '
                . 'password gets in through the side door.'];
        }

        $roleProblem = $this->undeclared($role);

        if ($roleProblem !== null) {
            return ['ok' => false, 'why' => $roleProblem];
        }

        $employmentProblem = $this->employmentProblem($employmentId, null);

        if ($employmentProblem !== null) {
            return ['ok' => false, 'why' => $employmentProblem];
        }

        $user = $this->store->put(self::COLLECTION, [
            'email' => $email,
            // Hashed by the platform's own hasher at the platform's own
            // parameters. Nothing in this class ever holds the plaintext past
            // this line and nothing writes it anywhere.
            'password' => $this->hasher->hash($password),
            'status' => $role,
            'employee_id' => $employmentId === '' ? null : $employmentId,
            // `users` is platform-scoped, so the store does NOT stamp this. It is
            // stamped here or the account belongs to no tenant, and a user with
            // no tenant is a user whose every read is unscoped — the SmartPRS 2
            // finding this rebuild exists to remove.
            'tenant_id' => $this->tenantId(),
            'created_at' => date('c'),
        ]);

        return ['ok' => true, 'why' => sprintf(
            'Created %s as %s%s.', $email, $role,
            $employmentId === '' ? ', linked to no employee — it sees nothing of its own until '
                . 'somebody links it' : ''), 'user' => $user];
    }

    /**
     * Change what an account is.
     *
     * @param string $actorId the signed-in account making the change. Passed in
     *     rather than read from the session, so this class stays testable and so
     *     the caller cannot forget that self-promotion is the thing being guarded.
     * @return array{ok: bool, why: string, user?: array<string, mixed>}
     */
    public function setRole(string $userId, string $role, string $actorId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        $roleProblem = $this->undeclared($role);

        if ($roleProblem !== null) {
            return ['ok' => false, 'why' => $roleProblem];
        }

        // BOTH DIRECTIONS, and the downward one is not the lesser of them. Upward
        // is an administrator's password becoming an administrator's account for
        // anybody who takes it; downward is somebody removing their own last
        // permission and then being the only person who could have restored it.
        // Neither is a mistake this product can undo for them.
        if ((string) ($user['id'] ?? '') === $actorId) {
            return ['ok' => false, 'why' => 'You cannot change your own role. Upwards that is simply '
                . 'granting yourself a permission nobody gave you; downwards it locks you out of the '
                . 'screen you would need to put it back, and the person who can fix that is the '
                . 'person who has just stopped being able to. Ask another administrator.'];
        }

        if ($this->roleOf($user) === self::ADMINISTRATOR && $role !== self::ADMINISTRATOR
            && $this->administrators() <= 1) {
            return ['ok' => false, 'why' => 'That is the last administrator in this organisation, so '
                . 'the change is refused. A tenant with no administrator cannot be administered by '
                . 'anybody: no account left could create one, restore this one, or reach this '
                . 'screen again — and there is no support desk in this product to undo it from '
                . 'outside. Make somebody else an administrator first, then demote this account.'];
        }

        $before = $this->roleOf($user);
        $saved = $this->store->put(self::COLLECTION, ['id' => $user['id'], 'status' => $role]);

        return ['ok' => true, 'why' => sprintf('%s is now %s, and was %s.',
            (string) ($user['email'] ?? $userId), $role, $before), 'user' => $saved];
    }

    // ------------------------------------------------------------ SMARTPRS 2's USER ACCESS (10 Sep)
    // Edit name / login email, set a password by hand, disable on exit and enable
    // again, and an invitation token for a set-your-own-password link.

    /** @return array{ok: bool, why: string, user?: array<string, mixed>} */
    public function update(string $userId, string $name, string $email): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        $email = trim($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'why' => 'The login needs a valid email address.'];
        }

        foreach ($this->rows() as $row) {
            if ((string) $row['id'] !== (string) $user['id'] && strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                return ['ok' => false, 'why' => "Another account already signs in as {$email}."];
            }
        }

        $saved = $this->store->put(self::COLLECTION, ['id' => $user['id'], 'name' => mb_substr(trim($name), 0, 120), 'email' => $email]);

        return ['ok' => true, 'why' => 'Account updated.', 'user' => $saved];
    }

    /** A password set by an administrator — through the same hasher and floor as sign-up. */
    public function setPassword(string $userId, string $password, string $actorId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        if (strlen($password) < Session::PASSWORD_FLOOR) {
            return ['ok' => false, 'why' => 'The password must be at least ' . Session::PASSWORD_FLOOR . ' characters.'];
        }

        $saved = $this->store->put(self::COLLECTION, ['id' => $user['id'], 'password' => $this->hasher->hash($password)]);

        return ['ok' => true, 'why' => 'Password set for ' . (string) ($user['email'] ?? $userId) . '. Tell them in person or over the phone — it is written nowhere.', 'user' => $saved];
    }

    /**
     * A password an account sets for ITSELF — the self-service half `setPassword()`
     * above deliberately does not cover (see this class's own doc-comment). Unlike
     * an administrator's `setPassword()`, this one demands the CURRENT password
     * first: an administrator resetting somebody else's account already proved who
     * they are by signing in as an administrator, but here the only thing standing
     * between "I forgot my password" and "I am changing yours" is this check.
     */
    public function changeOwnPassword(string $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        if (!$this->hasher->verify($currentPassword, $user['password'] ?? $user['password_hash'] ?? null)) {
            return ['ok' => false, 'why' => 'That is not your current password.'];
        }

        if (strlen($newPassword) < Session::PASSWORD_FLOOR) {
            return ['ok' => false, 'why' => 'The new password must be at least ' . Session::PASSWORD_FLOOR . ' characters.'];
        }

        $saved = $this->store->put(self::COLLECTION, ['id' => $user['id'], 'password' => $this->hasher->hash($newPassword)]);

        return ['ok' => true, 'why' => 'Your password has been changed.', 'user' => $saved];
    }

    /** Disable (on exit) or enable. The actor cannot disable themselves, nor the last administrator. */
    public function setDisabled(string $userId, bool $disabled, string $actorId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        if ($disabled && (string) ($user['id'] ?? '') === $actorId) {
            return ['ok' => false, 'why' => 'You cannot disable your own login. Ask another administrator.'];
        }

        if ($disabled && $this->roleOf($user) === self::ADMINISTRATOR && $this->administrators() <= 1) {
            return ['ok' => false, 'why' => 'That is the last administrator; disabling it would leave nobody who can sign in and manage accounts.'];
        }

        $saved = $this->store->put(self::COLLECTION, ['id' => $user['id'], 'disabled_at' => $disabled ? date('Y-m-d H:i:s') : null]);

        return ['ok' => true, 'why' => (string) ($user['email'] ?? $userId) . ($disabled ? ' is disabled and cannot sign in.' : ' is enabled again.'), 'user' => $saved];
    }

    /** An invitation: a one-time token for a set-your-own-password link, valid for `$hours`. @return array{ok: bool, why: string, token?: string, user?: array<string, mixed>} */
    public function invite(string $userId, int $hours): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        $token = bin2hex(random_bytes(24));
        $this->store->put('user_password_resets', ['email' => (string) $user['email'], 'token' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600), 'used_at' => null]);

        return ['ok' => true, 'why' => 'A set-password link was issued for ' . (string) $user['email'] . '.', 'token' => $token, 'user' => $user];
    }

    /** The account a live (unused, unexpired) reset token belongs to, else null. */
    public function byResetToken(string $token): ?array
    {
        $hash = hash('sha256', $token);

        foreach ($this->store->all('user_password_resets') as $r) {
            if ((string) ($r['token'] ?? '') === $hash && (string) ($r['used_at'] ?? '') === '' && (string) ($r['expires_at'] ?? '') >= date('Y-m-d H:i:s')) {
                foreach ($this->rows() as $u) {
                    if (strcasecmp((string) ($u['email'] ?? ''), (string) $r['email']) === 0) {
                        return $u + ['_reset_id' => (string) $r['id']];
                    }
                }
            }
        }

        return null;
    }

    /** Redeem a reset token: set the password, burn the token. */
    public function redeemResetToken(string $token, string $password): array
    {
        $user = $this->byResetToken($token);

        if ($user === null) {
            return ['ok' => false, 'why' => 'This link has expired or was already used. Ask your administrator for a new invitation.'];
        }

        if (strlen($password) < Session::PASSWORD_FLOOR) {
            return ['ok' => false, 'why' => 'The password must be at least ' . Session::PASSWORD_FLOOR . ' characters.'];
        }

        $this->store->put(self::COLLECTION, ['id' => $user['id'], 'password' => $this->hasher->hash($password)]);
        $this->store->put('user_password_resets', ['id' => $user['_reset_id'], 'used_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => 'Your password is set. Sign in with it.', 'user' => $user];
    }

    /**
     * Link an account to the employee it IS.
     *
     * @return array{ok: bool, why: string, user?: array<string, mixed>}
     */
    public function bind(string $userId, string $employmentId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        if (trim($employmentId) === '') {
            return ['ok' => false, 'why' => 'Choose the employee this account is. Linking to '
                . 'nothing is unlinking, and it is a different act with a different consequence — '
                . 'use the unlink action so the person doing it is told what it costs.'];
        }

        $problem = $this->employmentProblem($employmentId, $userId);

        if ($problem !== null) {
            return ['ok' => false, 'why' => $problem];
        }

        $employment = $this->store->find('employees', $employmentId);
        $saved = $this->store->put(self::COLLECTION,
            ['id' => $user['id'], 'employee_id' => $employmentId]);

        return ['ok' => true, 'why' => sprintf('%s is now %s. It sees that employee\'s records as '
            . 'its own, and a manager role on it reaches the people who report to it.',
            (string) ($user['email'] ?? $userId),
            Employment::label((array) $employment)), 'user' => $saved];
    }

    /** @return array{ok: bool, why: string, user?: array<string, mixed>} */
    public function unbind(string $userId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'why' => self::NO_SUCH_USER];
        }

        // The RAW link, not the employee it resolves to: an account pointing at
        // a row that has gone is still linked, and refusing to unlink it because
        // the target cannot be found would leave the only account that needs
        // unlinking as the one account that cannot be.
        if ((string) ($user['employee_id'] ?? '') === '') {
            return ['ok' => false, 'why' => 'That account is not linked to an employee, so there '
                . 'is nothing to unlink. It already sees nothing of its own — if that is the '
                . 'problem you are looking at, the fix is to link it, not to unlink it again.'];
        }

        $saved = $this->store->put(self::COLLECTION, ['id' => $user['id'], 'employee_id' => null]);

        return ['ok' => true, 'why' => sprintf(
            '%s is no longer linked to an employee. It now sees NOTHING of its own — not '
            . 'everything — because "we could not work out who you are" resolves to none rather '
            . 'than all: its own payslip, its own leave and its own attendance are all gone from '
            . 'it until somebody links it again. Its permissions are untouched, so this will look '
            . 'to them like a screen that has broken rather than a setting that changed.',
            (string) ($user['email'] ?? $userId)), 'user' => $saved];
    }

    /** The role string this build treats as holding everything. */
    private const ADMINISTRATOR = 'administrator';

    private const NO_SUCH_USER = 'There is no such account in this organisation. Another '
        . 'organisation\'s account is reported the same way rather than as "forbidden", because '
        . '"forbidden" confirms the id exists, which is worth having if you are guessing ids.';

    private const UNSCOPED = 'This screen needs to know which organisation it is administering, and '
        . 'nothing told it. `users` is platform-scoped — it has to be, an account must be findable '
        . 'before there is a tenant to scope by — so an unscoped read here would list and edit '
        . 'every customer\'s accounts. It refuses instead of guessing.';

    /** @return ?string the refusal, or null when the role is one this build declares */
    private function undeclared(string $role): ?string
    {
        if (in_array($role, $this->access->roles(), true)) {
            return null;
        }

        return sprintf(
            '"%s" is not a role this build declares. The declared ones are %s. An undeclared role '
            . 'is not a smaller role: the decider finds no assignments for it and grants NOTHING, '
            . 'so the account would be created, would sign in, and would be silently unable to do '
            . 'anything at all — and the person it belongs to would report it as the software being '
            . 'broken, because from where they sit it is.',
            $role, implode(', ', $this->access->roles()),
        );
    }

    /**
     * Why this employee cannot be the one behind that account.
     *
     * Shared by `create()` and `bind()` deliberately: two accounts claiming to be
     * the same person is the same defect whichever door it comes in through, and
     * a check on one path only is a check somebody routes around by accident.
     *
     * @param ?string $exceptUserId the account being bound, whose own existing
     *     link is not a clash with itself
     */
    private function employmentProblem(string $employmentId, ?string $exceptUserId): ?string
    {
        if (trim($employmentId) === '') {
            return null;
        }

        // Tenancy is structural: the store is scoped, so another tenant's
        // employee is NOT FOUND here rather than refused, and the two cases
        // share this sentence because telling them apart would confirm the id.
        if ($this->store->find('employees', $employmentId) === null) {
            return sprintf(
                'There is no employee %s in this organisation. An employee from another '
                . 'organisation reads the same way, and that is not vagueness: tenancy here is '
                . 'structural rather than a filter, so a row outside this tenant does not exist '
                . 'from where this screen is standing.', $employmentId);
        }

        foreach ($this->rows() as $row) {
            if ((string) ($row['employee_id'] ?? '') !== $employmentId) {
                continue;
            }

            if ($exceptUserId !== null && (string) ($row['id'] ?? '') === $exceptUserId) {
                continue;
            }

            return sprintf(
                'That employee is already linked to %s. Two accounts claiming to be the same '
                . 'person makes "whose row is this" unanswerable — and that is the exact question '
                . 'the record scope exists to answer, so the ambiguity would show up as one of '
                . 'them silently seeing the other\'s payslip. Unlink %s first if the employee '
                . 'has genuinely moved to this account.',
                (string) ($row['email'] ?? 'another account'),
                (string) ($row['email'] ?? 'that account'));
        }

        return null;
    }

    /** How many administrators this tenant has. Counted, never assumed. */
    private function administrators(): int
    {
        $count = 0;

        foreach ($this->rows() as $row) {
            if ($this->roleOf($row) === self::ADMINISTRATOR) {
                $count++;
            }
        }

        return $count;
    }

    /** @return ?array<string, mixed> */
    public function find(string $userId): ?array
    {
        if ($this->tenantId() === null) {
            return null;
        }

        foreach ($this->rows() as $row) {
            if ((string) ($row['id'] ?? '') === $userId) {
                return $row;
            }
        }

        return null;
    }

    /** @return ?array<string, mixed> */
    private function byEmail(string $email): ?array
    {
        foreach ($this->rows() as $row) {
            if (strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * This tenant's user rows, and only this tenant's.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * A ROLE WAS RENAMED in Role / Designation Setup (28 Sep): every login of
     * this tenant holding the old name moves to the new one, or it would hold a
     * role nobody declares and so no permission at all. Returns how many moved.
     */
    public function renameRole(string $from, string $to): int
    {
        $moved = 0;

        foreach ($this->rows() as $row) {
            if (strcasecmp(trim((string) ($row['status'] ?? '')), trim($from)) === 0) {
                $this->store->put(self::COLLECTION, ['id' => $row['id'], 'status' => $to]);
                $moved++;
            }
        }

        return $moved;
    }

    private function rows(): array
    {
        $tenantId = $this->tenantId();

        // MySQL returns `tenant_id` as an int and the session carries it as a
        // string, so this compares as strings. `===` on the raw values was right
        // on the file store and false for every row on MySQL — the shape of every
        // defect this rebuild keeps finding.
        return array_values(array_filter(
            $this->store->all(self::COLLECTION),
            static fn (array $row): bool => (string) ($row['tenant_id'] ?? '') === $tenantId,
        ));
    }

    /** @param array<string, mixed> $row */
    private function roleOf(array $row): string
    {
        return (string) ($row['role'] ?? $row['status'] ?? '');
    }

    /**
     * The employee behind an account, or null when nobody established one.
     *
     * @param array<string, mixed> $row
     * @return ?array<string, mixed>
     */
    private function employmentOf(array $row): ?array
    {
        $id = (string) ($row['employee_id'] ?? '');

        return $id === '' ? null : $this->store->find('employees', $id);
    }

    private function tenantId(): ?string
    {
        return $this->store->tenantId();
    }
}
