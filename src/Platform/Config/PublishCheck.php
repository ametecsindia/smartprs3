<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Config;

/**
 * One publish gate's recorded outcome.
 *
 * Shared by every configuration artefact that has publish gates — a formula's
 * twelve (Part VIII §9), a workflow's eighteen (Part IX §IX.3.6), and whatever
 * comes next — because spine S4 makes them one mechanism: an artefact, a
 * version, a set of blocking checks, a stored outcome per check.
 *
 * "All twelve gates run on every publication, in order, and the stored outcome
 * of each is written to `formula_publication_checks` so a blocked publication is
 * explainable and a passed one is evidenced."
 *
 * Both halves matter. A blocked publication that does not say which gate refused
 * it and why is an administrator raising a support ticket; a passed publication
 * with no record is a payslip nobody can defend three years later.
 *
 * There is no short-circuit: a gate that blocks does not stop the ones after it,
 * because an author fixing one error and immediately hitting the next is the
 * experience §2 catalogues as the reason nobody configures SmartPRS 2.
 */
final readonly class PublishCheck
{
    /** §9: block refuses publication; warn and lint do not; pass is evidence. */
    public const SEVERITIES = ['pass', 'lint', 'warn', 'block'];

    /**
     * @param list<string> $messages
     */
    private function __construct(
        public string $gate,
        public string $name,
        public string $severity,
        public array $messages = [],
    ) {
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException("'{$severity}' is not a check severity (§9).");
        }
    }

    public static function pass(string $gate, string $name): self
    {
        return new self($gate, $name, 'pass');
    }

    /** @param list<string>|string $messages */
    public static function block(string $gate, string $name, array|string $messages): self
    {
        return new self($gate, $name, 'block', (array) $messages);
    }

    /** @param list<string>|string $messages */
    public static function warn(string $gate, string $name, array|string $messages): self
    {
        return new self($gate, $name, 'warn', (array) $messages);
    }

    /** @param list<string>|string $messages */
    public static function lint(string $gate, string $name, array|string $messages): self
    {
        return new self($gate, $name, 'lint', (array) $messages);
    }

    /**
     * A gate that could not check what it exists to check.
     *
     * Recorded as a warn naming what was missing, never as a pass. A gate with
     * nothing to read reporting `pass` is the failure mode this codebase has
     * already been bitten by once, in the enforcement gates: four of them were
     * green over zero files for weeks because the directory they select did not
     * exist yet.
     */
    public static function couldNotCheck(string $gate, string $name, string $why): self
    {
        return new self($gate, $name, 'warn', ['NOT CHECKED: ' . $why]);
    }

    public function blocks(): bool
    {
        return $this->severity === 'block';
    }

    public function render(): string
    {
        $head = sprintf('%-4s %-28s %s', $this->gate, $this->name, strtoupper($this->severity));

        foreach ($this->messages as $message) {
            $head .= "\n       " . str_replace("\n", "\n       ", $message);
        }

        return $head;
    }
}
