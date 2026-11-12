<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

use SmartPRS\Platform\Support\Clock;

/**
 * The password half of the `auth_policy` artefact (Part XXII §XXII.7.2).
 *
 * SmartPRS 2's entire password policy is the literal `min:8`, repeated in five
 * files — and `min:6` in one, for platform Super Admins, so the most privileged
 * accounts on the installation carry the weakest rule in the product. There is
 * no complexity rule, no history, no expiry, no breach check and no identity-term
 * check. This class is the replacement, and the shape of the replacement is the
 * point: **every value here is configuration, and none of it is a constant in a
 * controller.**
 *
 * ### The floor (R-XXII-180, R-XXII-181)
 *
 * A tenant may tighten and may never loosen. The floor is a platform constant,
 * not a tenant-editable row, and `forTenant()` is the only construction path a
 * tenant's configuration reaches — it applies the overrides and then refuses the
 * whole policy if any dimension came out below `platformFloor()`. It refuses
 * rather than clamping, because a tenant that asked for `min_length = 4` and
 * silently received 12 has been told nothing, and will discover it from a
 * support ticket.
 *
 * `platformAccountFloor()` is strictly stricter than `platformFloor()` on every
 * dimension it constrains (R-XXII-181), which is the inversion of what
 * SmartPRS 2 does.
 *
 * ### What "strength" means here (R-XXII-183)
 *
 * Length, plus a breach check, plus an identity-term check. The default profile
 * requires **no** character classes: composition rules measurably push users
 * toward `Password1!` and away from length, and `Password1!` is in every corpus.
 * The classes remain configurable because some customers' own written policies
 * mandate them and we do not get to overrule a customer's auditor — but they are
 * off by default and a tenant turning them on is tightening, which the floor
 * permits.
 *
 * Truncation and character-set restriction are prohibited: `max_length` is
 * generous and floored, normalisation is not performed, and every byte the user
 * typed goes into the hash.
 */
final readonly class PasswordPolicy
{
    public const CLASS_LOWER = 'lower';
    public const CLASS_UPPER = 'upper';
    public const CLASS_DIGIT = 'digit';
    public const CLASS_SYMBOL = 'symbol';

    public const CLASSES = [self::CLASS_LOWER, self::CLASS_UPPER, self::CLASS_DIGIT, self::CLASS_SYMBOL];

    /**
     * Trivial values refused on every profile, floor included. This is not a
     * breach corpus — that is BreachCheck's job over hundreds of millions of
     * entries — it is the short list that must be refused even on an air-gapped
     * installation with breach checking off, and it carries every credential
     * SmartPRS 2 has ever shipped (R-XXII-230).
     */
    public const DENYLIST = [
        'password', 'password1', 'password123', 'passw0rd',
        'smartprs', 'smartprs-demo', 'smartprs123', 'ametecs', 'ametecs123',
        'admin', 'admin123', 'administrator', 'welcome', 'welcome123',
        'changeme', 'letmein', 'qwerty', 'iloveyou', 'secret',
        '12345678', '123456789', '1234567890', 'abc123', 'india123',
    ];

    /**
     * @param  list<string>  $requiredClasses  subset of self::CLASSES
     * @param  list<string>  $denylist         lowercase; matched as a whole value
     *                       and as a substring, because `password2026` is not a
     *                       different password
     * @param  int           $historyDepth     R-XXII-185
     * @param  int           $minAgeHours      stops cycling back to a favourite
     * @param  ?int          $maxAgeDays       NULL = no expiry, and that is the
     *                       default (R-XXII-186); forced rotation drives users
     *                       to predictable increments
     * @param  bool          $rejectWhenBreachCheckUnavailable  R-XXII-184, fail
     *                       closed at set time; see BreachCheck's class comment
     *                       for the reasoning and the one deployment that turns
     *                       it off
     */
    private function __construct(
        public int $minLength,
        public int $maxLength,
        public array $requiredClasses,
        public array $denylist,
        public bool $disallowIdentityTerms,
        public bool $breachCheckEnabled,
        public bool $rejectWhenBreachCheckUnavailable,
        public int $historyDepth,
        public int $minAgeHours,
        public ?int $maxAgeDays,
        public int $expiryWarningDays,
    ) {
        if ($minLength > $maxLength) {
            throw new \InvalidArgumentException(
                "min_length {$minLength} exceeds max_length {$maxLength}; no password can satisfy this policy."
            );
        }

        foreach ($requiredClasses as $class) {
            if (!in_array($class, self::CLASSES, true)) {
                throw new \InvalidArgumentException(
                    "'{$class}' is not a character class. Known: " . implode(', ', self::CLASSES) . '.'
                );
            }
        }
    }

    /**
     * The absolute platform minimum. A tenant may not go under any of this.
     *
     * Not "what we recommend" — what we refuse to run below. `tenantDefault()`
     * is the recommendation and sits above it.
     */
    public static function platformFloor(): self
    {
        return new self(
            minLength: 12,
            maxLength: 128,
            requiredClasses: [],
            denylist: self::DENYLIST,
            disallowIdentityTerms: true,
            breachCheckEnabled: true,
            rejectWhenBreachCheckUnavailable: false,
            historyDepth: 3,
            minAgeHours: 0,
            maxAgeDays: null,
            expiryWarningDays: 0,
        );
    }

    /**
     * R-XXII-181 — the floor for accounts in the platform tenant, strictly
     * stricter than the tenant floor on every dimension it constrains.
     */
    public static function platformAccountFloor(): self
    {
        return new self(
            minLength: 16,
            maxLength: 256,
            requiredClasses: [],
            denylist: self::DENYLIST,
            disallowIdentityTerms: true,
            breachCheckEnabled: true,
            rejectWhenBreachCheckUnavailable: true,
            historyDepth: 10,
            minAgeHours: 24,
            maxAgeDays: null,
            expiryWarningDays: 0,
        );
    }

    /** The shipped secure default: what a tenant gets before it configures anything. */
    public static function tenantDefault(): self
    {
        return new self(
            minLength: 14,
            maxLength: 256,
            requiredClasses: [],
            denylist: self::DENYLIST,
            disallowIdentityTerms: true,
            breachCheckEnabled: true,
            rejectWhenBreachCheckUnavailable: true,
            historyDepth: 5,
            minAgeHours: 1,
            maxAgeDays: null,
            expiryWarningDays: 14,
        );
    }

    /**
     * A tenant's configured policy: the default, plus its overrides, refused as
     * a whole if the result is anywhere below the floor (R-XXII-180).
     *
     * @param  array<string, mixed>  $overrides  constructor parameter names
     */
    public static function forTenant(array $overrides = []): self
    {
        return self::tenantDefault()->with($overrides)->assertAtLeastAsStrictAs(self::platformFloor(), 'the platform tenant floor');
    }

    /** The same, for an account in the platform tenant (R-XXII-181). */
    public static function forPlatformAccounts(array $overrides = []): self
    {
        return self::platformAccountFloor()->with($overrides)->assertAtLeastAsStrictAs(self::platformAccountFloor(), 'the platform-account floor');
    }

    /** @param array<string, mixed> $overrides */
    public function with(array $overrides): self
    {
        $known = [
            'minLength', 'maxLength', 'requiredClasses', 'denylist', 'disallowIdentityTerms',
            'breachCheckEnabled', 'rejectWhenBreachCheckUnavailable', 'historyDepth',
            'minAgeHours', 'maxAgeDays', 'expiryWarningDays',
        ];

        $unknown = array_diff(array_keys($overrides), $known);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown auth_policy.password key(s): ' . implode(', ', $unknown)
                . '. A misspelt key that is silently ignored is a policy nobody is enforcing.'
            );
        }

        $v = static fn (string $key, mixed $fallback): mixed => $overrides[$key] ?? $fallback;

        return new self(
            minLength: (int) $v('minLength', $this->minLength),
            maxLength: (int) $v('maxLength', $this->maxLength),
            requiredClasses: array_values((array) $v('requiredClasses', $this->requiredClasses)),
            // A REPLACEMENT, not a merge. Merging would make removal impossible
            // and therefore make the floor's denylist check unreachable — the
            // rule would look enforced while never being exercised. Replacing
            // means a tenant that drops a floor value is refused by
            // loosenedAgainst(), which is a rule with a test behind it.
            denylist: array_values(array_unique(array_map('strval', (array) $v('denylist', $this->denylist)))),
            disallowIdentityTerms: (bool) $v('disallowIdentityTerms', $this->disallowIdentityTerms),
            breachCheckEnabled: (bool) $v('breachCheckEnabled', $this->breachCheckEnabled),
            rejectWhenBreachCheckUnavailable: (bool) $v('rejectWhenBreachCheckUnavailable', $this->rejectWhenBreachCheckUnavailable),
            historyDepth: (int) $v('historyDepth', $this->historyDepth),
            minAgeHours: (int) $v('minAgeHours', $this->minAgeHours),
            maxAgeDays: $v('maxAgeDays', $this->maxAgeDays) === null ? null : (int) $v('maxAgeDays', $this->maxAgeDays),
            expiryWarningDays: (int) $v('expiryWarningDays', $this->expiryWarningDays),
        );
    }

    /**
     * @return list<string> the dimensions on which $this is weaker than $floor,
     *         empty when it is at least as strict everywhere
     */
    public function loosenedAgainst(self $floor): array
    {
        $weak = [];

        if ($this->minLength < $floor->minLength) {
            $weak[] = "min_length {$this->minLength} is below the floor of {$floor->minLength}";
        }

        if ($this->maxLength < $floor->maxLength) {
            // A LOWER maximum is a loosening, not a tightening: it caps how much
            // length — the one thing that actually helps — a user may choose.
            $weak[] = "max_length {$this->maxLength} is below the floor of {$floor->maxLength}";
        }

        foreach ($floor->requiredClasses as $class) {
            if (!in_array($class, $this->requiredClasses, true)) {
                $weak[] = "required character class '{$class}' has been dropped";
            }
        }

        foreach ($floor->denylist as $value) {
            if (!in_array($value, $this->denylist, true)) {
                $weak[] = "denylisted value '{$value}' has been removed";
            }
        }

        if ($floor->disallowIdentityTerms && !$this->disallowIdentityTerms) {
            $weak[] = 'the identity-term check has been switched off';
        }

        if ($floor->breachCheckEnabled && !$this->breachCheckEnabled) {
            $weak[] = 'the breach check has been switched off';
        }

        if ($floor->rejectWhenBreachCheckUnavailable && !$this->rejectWhenBreachCheckUnavailable) {
            $weak[] = 'an unavailable breach corpus would no longer refuse the change';
        }

        if ($this->historyDepth < $floor->historyDepth) {
            $weak[] = "history_depth {$this->historyDepth} is below the floor of {$floor->historyDepth}";
        }

        if ($this->minAgeHours < $floor->minAgeHours) {
            $weak[] = "min_age_hours {$this->minAgeHours} is below the floor of {$floor->minAgeHours}";
        }

        if ($floor->maxAgeDays !== null && ($this->maxAgeDays === null || $this->maxAgeDays > $floor->maxAgeDays)) {
            $weak[] = 'max_age_days is longer than the floor allows';
        }

        return $weak;
    }

    public function assertAtLeastAsStrictAs(self $floor, string $floorName): self
    {
        $weak = $this->loosenedAgainst($floor);

        if ($weak !== []) {
            throw new \DomainException(
                'This auth policy loosens ' . $floorName . ' (R-XXII-180: a tenant may '
                . "tighten and may never loosen):\n  · " . implode("\n  · ", $weak)
            );
        }

        return $this;
    }

    /**
     * Every rule the candidate fails, in one pass. Never the first one only.
     *
     * @param  array<string, ?string>  $context  identity terms belonging to the
     *         subject, by label: `email`, `name`, `employee_code`, `tenant_name`,
     *         `product_name`. Depended on **by shape** — the Organisation module
     *         owns the tenant record and this class never reads it, it is handed
     *         the strings.
     * @param  list<string>  $historyHashes  R-XXII-185; needs $hasher to compare
     * @param  ?BreachCheck  $breach  NULL is not "skip the check": when the
     *         policy has `breachCheckEnabled`, a missing checker takes the same
     *         outage path as an unreachable one, and the same configured
     *         decision applies. A control that is switched on and silently does
     *         nothing is the defect this whole part exists to remove.
     * @param  ?\DateTimeImmutable  $lastChangedAt  for the min_age check
     */
    public function validate(
        #[\SensitiveParameter] string $candidate,
        array $context = [],
        array $historyHashes = [],
        ?PasswordHasher $hasher = null,
        ?BreachCheck $breach = null,
        ?\DateTimeImmutable $lastChangedAt = null,
        ?Clock $clock = null,
    ): PolicyResult {
        $failures = [];

        // Length is measured in BYTES, which is what the hash consumes and what
        // max_length must bound. Counting characters would let a policy of
        // max 256 hand bcrypt a 700-byte input.
        $length = strlen($candidate);

        if ($length < $this->minLength) {
            $failures[PolicyResult::TOO_SHORT] =
                "Use at least {$this->minLength} characters (this one has {$length}).";
        }

        if ($length > $this->maxLength) {
            $failures[PolicyResult::TOO_LONG] =
                "Use at most {$this->maxLength} characters (this one has {$length}). "
                . 'Nothing is truncated — a password that does not fit is refused, not shortened.';
        }

        $missing = array_values(array_filter(
            $this->requiredClasses,
            static fn (string $class): bool => match ($class) {
                self::CLASS_LOWER => preg_match('/\p{Ll}/u', $candidate) !== 1,
                self::CLASS_UPPER => preg_match('/\p{Lu}/u', $candidate) !== 1,
                self::CLASS_DIGIT => preg_match('/\p{Nd}/u', $candidate) !== 1,
                self::CLASS_SYMBOL => preg_match('/[^\p{L}\p{Nd}]/u', $candidate) !== 1,
                default => false,
            },
        ));

        if ($missing !== []) {
            $failures[PolicyResult::MISSING_CLASS] =
                'Include at least one character of each of: ' . implode(', ', $missing) . '.';
        }

        $folded = mb_strtolower($candidate);

        foreach ($this->denylist as $value) {
            if ($value !== '' && str_contains($folded, mb_strtolower($value))) {
                $failures[PolicyResult::DENYLISTED] =
                    'This is a well-known password, or contains one. Choose something else.';

                break;
            }
        }

        if ($this->disallowIdentityTerms) {
            $hit = $this->identityTermIn($folded, $context);

            if ($hit !== null) {
                $failures[PolicyResult::IDENTITY_TERM] =
                    "A password must not contain your own {$hit}, or your organisation's name.";
            }
        }

        if ($this->breachCheckEnabled) {
            try {
                if ($breach === null) {
                    // A policy that says it breach-checks, and a caller that
                    // wired no checker, is the SmartPRS 2 pattern exactly: a
                    // control that is configured on and never runs. It takes the
                    // outage path, so it is refused or accepted by the SAME
                    // recorded decision as a real outage — never skipped.
                    throw new BreachCheckUnavailable(
                        'breach_check_enabled is set but no BreachCheck was supplied to validate()'
                    );
                }

                if ($breach->isBreached($candidate)) {
                    $failures[PolicyResult::BREACHED] =
                        'This password appears in a public breach corpus. It is being tried '
                        . 'against accounts right now; choose something else.';
                }
            } catch (BreachCheckUnavailable $e) {
                // R-XXII-184: an unreachable corpus degrades toward REFUSING the
                // change, never toward accepting it. The alternative branch is a
                // configured, recorded tenant decision — not a catch that shrugs.
                if ($this->rejectWhenBreachCheckUnavailable) {
                    $failures[PolicyResult::BREACH_CHECK_UNAVAILABLE] =
                        'The breach-corpus check could not run just now, so this password '
                        . 'change has not been accepted. Please try again shortly. ('
                        . $e->getMessage() . ')';
                }
            }
        }

        if ($this->historyDepth > 0 && $hasher !== null) {
            foreach (array_slice($historyHashes, 0, $this->historyDepth) as $old) {
                if ($hasher->verify($candidate, $old)) {
                    $failures[PolicyResult::REUSED] =
                        "This is one of your last {$this->historyDepth} passwords. Choose one you have not used.";

                    break;
                }
            }
        }

        if ($this->minAgeHours > 0 && $lastChangedAt !== null && $clock !== null) {
            $earliest = $lastChangedAt->modify('+' . $this->minAgeHours . ' hours');

            if ($clock->now() < $earliest) {
                $failures[PolicyResult::CHANGED_TOO_RECENTLY] =
                    'Your password was changed too recently. You may change it again after '
                    . $earliest->format('d-M-Y H:i') . ' UTC.';
            }
        }

        return PolicyResult::of($failures);
    }

    /** R-XXII-186 — must this user change their password before doing anything else? */
    public function hasExpired(\DateTimeImmutable $lastChangedAt, Clock $clock): bool
    {
        return $this->maxAgeDays !== null
            && $clock->now() >= $lastChangedAt->modify('+' . $this->maxAgeDays . ' days');
    }

    /** Days until expiry, or null when expiry is off or the warning window has not opened. */
    public function expiryWarningDaysRemaining(\DateTimeImmutable $lastChangedAt, Clock $clock): ?int
    {
        if ($this->maxAgeDays === null) {
            return null;
        }

        $expiresAt = $lastChangedAt->modify('+' . $this->maxAgeDays . ' days');
        $secondsLeft = $expiresAt->getTimestamp() - $clock->now()->getTimestamp();
        $days = $secondsLeft <= 0 ? 0 : intdiv($secondsLeft, 86_400);

        return $days <= $this->expiryWarningDays ? $days : null;
    }

    /** @param array<string, ?string> $context */
    private function identityTermIn(string $folded, array $context): ?string
    {
        foreach ($context as $label => $term) {
            if (!is_string($term) || $term === '') {
                continue;
            }

            foreach ($this->identityTerms($term) as $part) {
                if (str_contains($folded, $part)) {
                    return str_replace('_', ' ', (string) $label);
                }
            }
        }

        return null;
    }

    /**
     * The terms derived from one identity value. An email contributes its local
     * part and its domain label as well as the whole address, because
     * `priya@acme.com` yields `priya1234` far more often than it yields the
     * literal address.
     *
     * ponytail: a 4-character floor, so short surnames and two-letter tenant
     * codes do not reject every password containing "an". Substrings shorter
     * than that carry no useful signal.
     *
     * @return list<string>
     */
    private function identityTerms(string $term): array
    {
        $term = mb_strtolower(trim($term));
        $parts = [$term];

        foreach (preg_split('/[^\p{L}\p{Nd}]+/u', $term) ?: [] as $piece) {
            $parts[] = $piece;
        }

        return array_values(array_filter(
            array_unique($parts),
            static fn (string $p): bool => mb_strlen($p) >= 4,
        ));
    }
}
