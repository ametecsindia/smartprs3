<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * A permission code, per Part XXII §XXII.2.1.
 *
 *     permission_code ::= <module>.<resource>[.<qualifier>].<verb>
 *
 * It names a functional capability and nothing else — never a record set, never
 * an org unit, never a person. `payroll.run.approve.hyderabad` is a schema error
 * (R-XXII-013): Hyderabad is a *scope*, which is DataScope's job.
 *
 * The point of this class is that a permission is a value, not a string. In
 * SmartPRS 2 there is no permission type at all — there are 62 comparisons
 * against role *name* strings and the 13 seeded permission rows are never read
 * by anything. A free string cannot be validated at a boundary, cannot be
 * compared safely, and cannot be checked against a catalogue; a value object is
 * checked once, at construction, and is trustworthy everywhere after.
 *
 * What this class does NOT do:
 *
 *  - It does not check the verb against the verb vocabulary of R-XXII-012.
 *    The vocabulary is a platform constant *table*, and the codes the blueprint
 *    actually uses do not put the verb last: `attendance.day.view.history` and
 *    `attendance.recalculate.locked` both qualify after the verb. Position is
 *    therefore not a reliable verb test, and a wrong one would reject 21 real
 *    codes. Closure is enforced where it is decidable — by PermissionCatalogue.
 *  - It does not treat a lexical prefix as an implication. `payroll.approve` does
 *    NOT grant `payroll.approve.finance`. Implication is explicit and declared
 *    (R-XXII-037, Role::publish); a prefix rule would silently widen every grant
 *    whose code happened to be a stem of another.
 */
final readonly class Permission
{
    /**
     * Two to five dot-separated lowercase segments. Two, because `rules.publish`
     * and `attendance.ingest` are real codes with no separate resource; five,
     * because nothing in the corpus is deeper than four and an unbounded depth
     * is an unbounded namespace.
     */
    private const SHAPE = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,4}$/';

    /** @param list<string> $segments */
    private function __construct(
        public string $code,
        public array $segments,
    ) {
    }

    public static function of(self|string $code): self
    {
        if ($code instanceof self) {
            return $code;
        }

        if (preg_match(self::SHAPE, $code) !== 1) {
            throw new \InvalidArgumentException(
                "'{$code}' is not a permission code. Part XXII §XXII.2.1: two to five "
                . 'dot-separated segments, each lowercase a-z followed by [a-z0-9_], '
                . 'e.g. payroll.run.approve.'
            );
        }

        return new self($code, explode('.', $code));
    }

    /** The owning bounded module — the first segment, always (§XXII.2.1). */
    public function module(): string
    {
        return $this->segments[0];
    }

    public function equals(self|string $other): bool
    {
        return $this->code === self::of($other)->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
