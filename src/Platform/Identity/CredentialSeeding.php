<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * The first-administrator creation path (R-XXII-223), built so that the
 * SmartPRS 2 defect it replaces is **unrepresentable** rather than forbidden.
 *
 * What it replaces, precisely:
 *
 *  - `OnPremWorkspaceSeeder.php:78` creates `admin@smartprs.local` with
 *    `Hash::make('password')` against an arbitrary — possibly live — tenant,
 *    and the README publishes the credential.
 *  - `DemoReset.php:35` recreates `demo-admin@smartprs.in` with the password
 *    `smartprs-demo` every night at 03:30, unconditionally, printing it to
 *    stdout. Nightly re-creation defeats deletion; nightly reset defeats a
 *    password change.
 *  - `ClientProvision.php:29` takes the administrator password as a
 *    command-line **argument**, so it lands in shell history and the process
 *    table, while `$this->secret()` sits unused in the same framework.
 *
 * ### How a literal is made impossible
 *
 * **There is no string parameter for a password anywhere in this class.** The
 * constructor is private; the only two ways to obtain an instance are
 * `prompted()`, which takes a reader callable (a terminal secret prompt), and
 * `generated()`, which takes no secret at all and produces one from
 * `random_bytes`. `CredentialSeeding::prompted('password')` is a TypeError
 * before any policy runs.
 *
 * ### What this class honestly cannot do, and who does it
 *
 * A caller can still write `prompted(fn () => 'password')`. Three things catch
 * that, and only the first two live here:
 *
 *  1. `PasswordPolicy::DENYLIST` carries every credential SmartPRS 2 has ever
 *     shipped (R-XXII-230), so every *known* literal is refused at runtime.
 *  2. The full policy — length, breach corpus, identity terms — runs against
 *     the secret before an account exists.
 *  3. A *strong, novel* literal typed into a seeder is caught by R-XXII-230's
 *     build gate, which greps the release tree for password-shaped assignments
 *     outside `env()`/`config()`/test fixtures. That is a build check, not a
 *     runtime one, and pretending otherwise here would be the more dangerous
 *     mistake.
 */
final class CredentialSeeding
{
    /** Bytes of entropy in a generated secret: 192 bits, base32-encoded. */
    private const GENERATED_BYTES = 24;

    /** @param \Closure(): string $read */
    private function __construct(
        private readonly \Closure $read,
        public readonly bool $generated,
    ) {
    }

    /**
     * An operator typing the secret at a terminal (R-XXII-223: interactively,
     * no default, no command-line argument).
     *
     * @param  callable(): string  $reader  e.g. a wrapper over the console's
     *         hidden-input prompt. NOT a string, and no overload takes one.
     */
    public static function prompted(callable $reader): self
    {
        return new self($reader(...), false);
    }

    /**
     * A CSPRNG secret the installer displays once and never stores in plaintext.
     *
     * The alternative to an interactive prompt in a genuinely non-interactive
     * install (a container entrypoint), and the *only* other alternative: there
     * is no third constructor.
     */
    public static function generated(int $bytes = self::GENERATED_BYTES): self
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('A generated administrator secret below 128 bits is not one.');
        }

        return new self(static fn (): string => rtrim(Totp::base32Encode(random_bytes($bytes)), '='), true);
    }

    /**
     * Create the installation's first administrator.
     *
     * @param  bool  $anyAdministratorExists  R-XXII-227 forbids *resetting* an
     *         account as much as creating one. If an administrator is already
     *         there, this is not a first-administrator path — it is the
     *         `DemoReset` takeover, and it refuses.
     * @param  ?BreachCheck  $breach  NULL only for an air-gapped installation
     *         whose policy has `breachCheckEnabled = false`; the policy, not
     *         this method, decides whether that is acceptable.
     *
     * @return array{email: string, password_hash: string, display_once: ?string}
     *         `display_once` carries the secret ONLY when it was generated here
     *         and the operator has therefore never seen it. For the prompted
     *         path it is NULL — the operator already knows it, and echoing it
     *         back is how it reaches a terminal log.
     *
     * @throws \DomainException on every refusal, with the reason
     */
    public function createFirstAdministrator(
        string $email,
        PasswordPolicy $policy,
        PasswordHasher $hasher,
        ?BreachCheck $breach = null,
        bool $anyAdministratorExists = false,
        string $tenantName = '',
    ): array {
        if ($anyAdministratorExists) {
            throw new \DomainException(
                'An administrator already exists on this installation, so this is not a '
                . 'first-administrator path. R-XXII-227: no installer, seeder or scheduled '
                . 'task may create OR RESET an account with a credential known outside the '
                . 'installation. Use the audited set-password-link path (R-XXII-221).'
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException("'{$email}' is not an email address, so no activation could reach it.");
        }

        $context = [
            'email' => $email,
            'tenant_name' => $tenantName,
            'product_name' => 'SmartPRS',
        ];

        // ponytail: a generated secret is retried, a prompted one is not. Base32
        // over 38 characters contains a denylisted substring about once in a
        // million, and "the password you never chose does not satisfy the
        // policy" is not an error message an installer should ever print. The
        // operator's own typing gets one attempt and a real explanation.
        $attempts = $this->generated ? 5 : 1;

        do {
            $secret = ($this->read)();
            $result = $policy->validate($secret, context: $context, breach: $breach);
        } while (!$result->ok() && --$attempts > 0);

        if (!$result->ok()) {
            throw new \DomainException(
                "The first administrator's password does not satisfy the platform policy, so "
                . "no account was created:\n" . $result->render()
            );
        }

        return [
            'email' => $email,
            'password_hash' => $hasher->hash($secret),
            'display_once' => $this->generated ? $secret : null,
        ];
    }
}
