<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * THE PROMISE THIS FILE KEEPS.
 *
 * Three files in this codebase say some version of "a customer who wants one more
 * approver above ₹50,000 needs a release in SmartPRS 2; here that is a threshold
 * in `config/workflows.json`". That was true and still not enough: a threshold in
 * a JSON file on a server is a developer's edit, not a customer's. Until this
 * screen existed the promise was kept for the person with a checkout and nobody
 * else.
 *
 * WHAT CAN BE CHANGED IS THE COMMERCIAL SHAPE, NOT THE CONTROL. Thresholds, SLA
 * hours, whether a reason is demanded, and what a step is called on screen. What
 * cannot: which role decides a step, what the workflow is about, the permission
 * needed to raise it, and whether a step is an approval at all. Those are the
 * control itself — an administrator raising a threshold is doing their job, and
 * an administrator changing who signs is removing the second person the approval
 * exists for.
 *
 * A CHAIN WHOSE THRESHOLDS GO BACKWARDS IS REFUSED. If finance sees claims over
 * ₹5,000 and the director sees claims over ₹1,000, then a ₹2,000 claim reaches
 * the director and never reaches finance — the senior approver signs something
 * the junior one never checked. It routes, it approves, nothing errors, and the
 * control is inverted. This is the check that earns the file.
 *
 * Versions are stored beside the module ones and read the same way; the key is
 * prefixed so the two cannot collide.
 *
 * ponytail: prefixing the key ("workflow:expense-claim") rather than adding a
 * `kind` column. A third kind of declaration is the point at which that becomes a
 * column and this a shared base class — not before.
 */
final class WorkflowCustomisation
{
    private const PREFIX = 'workflow:';

    /** What an administrator may change about a step. `by` and `type` are NOT here. */
    public const STEP_KEYS = ['label', 'when_amount_over', 'requires_comment', 'sla_hours'];

    public const COMMENT_RULES = ['never', 'on_reject', 'always'];

    public function __construct(private readonly Customisation $customisation)
    {
    }

    /** Lay this customer's overrides over a shipped workflow declaration. */
    public function apply(array $workflow): array
    {
        $override = $this->customisation->live()[self::PREFIX . $workflow['key']] ?? [];

        if ($override === []) {
            return $workflow;
        }

        foreach ($workflow['steps'] as $index => $step) {
            $change = (array) ($override['steps'][(string) $step['key']] ?? []);

            if ($change === []) {
                continue;
            }

            $change = array_intersect_key($change, array_flip(self::STEP_KEYS));

            // A threshold set back to nothing means "this step always applies",
            // and it has to be REMOVED rather than set to null: `when_amount_over
            // => null` is not the same as absent to the routing code, which asks
            // whether the key is there.
            if (array_key_exists('when_amount_over', $change)
                && ($change['when_amount_over'] === null || $change['when_amount_over'] === '')) {
                unset($change['when_amount_over'], $step['when_amount_over']);
            }

            $workflow['steps'][$index] = array_merge($step, $change);
        }

        $workflow['customised'] = true;

        return $workflow;
    }

    /** @return list<array<string, mixed>> */
    public function history(string $workflowKey): array
    {
        return $this->customisation->history(self::PREFIX . $workflowKey);
    }

    /**
     * @param array<string, mixed> $override
     * @return array{ok: bool, why: string, version?: int}
     */
    public function save(string $workflowKey, array $override, ?array $shipped, string $byId,
        string $byLabel, string $note = ''): array
    {
        if ($shipped === null) {
            return ['ok' => false, 'why' => "There is no workflow '{$workflowKey}' to customise."];
        }

        $problems = self::problems($override, $shipped);

        if ($problems !== []) {
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        // Reuses the module store deliberately: same table, same versioning, same
        // append-only privileges. A second table for the same idea is the defect
        // this rebuild exists to remove, not one to introduce.
        return $this->customisation->saveRaw(self::PREFIX . $workflowKey, $override, $byId,
            $byLabel, $note);
    }

    /**
     * What is wrong with a proposed workflow customisation.
     *
     * @return list<string>
     */
    public static function problems(array $override, array $shipped): array
    {
        $problems = [];
        $steps = [];

        foreach ((array) $shipped['steps'] as $step) {
            $steps[(string) $step['key']] = $step;
        }

        foreach (array_keys($override) as $key) {
            if ($key !== 'steps') {
                $problems[] = sprintf(
                    '"%s" cannot be customised. What a workflow is about, the permission needed to '
                    . 'raise it and which role decides each step are the control itself — raising a '
                    . 'threshold is doing the job, changing who signs is removing the second person '
                    . 'the approval exists for.', (string) $key);
            }
        }

        foreach ((array) ($override['steps'] ?? []) as $name => $change) {
            $name = (string) $name;

            if (!isset($steps[$name])) {
                $problems[] = sprintf('This workflow has no step "%s".', $name);

                continue;
            }

            foreach (array_keys((array) $change) as $key) {
                if (!in_array((string) $key, self::STEP_KEYS, true)) {
                    $problems[] = sprintf('"%s" is not something that can be set on a step.',
                        (string) $key);
                }
            }

            if (array_key_exists('requires_comment', $change)
                && !in_array((string) $change['requires_comment'], self::COMMENT_RULES, true)) {
                $problems[] = sprintf('"%s" must be one of: %s.', $name,
                    implode(', ', self::COMMENT_RULES));
            }

            $sla = $change['sla_hours'] ?? null;

            // HOURS, not money — but the same "is this a positive number" test,
            // in integers. An SLA is declared in whole hours everywhere it is
            // read, so the digits answer it without a float.
            if ($sla !== null && $sla !== ''
                && (preg_match('/^\d+$/', trim((string) $sla)) !== 1 || (int) $sla <= 0)) {
                $problems[] = sprintf(
                    'The SLA on "%s" must be more than zero hours. Zero means every request is '
                    . 'overdue the moment it is raised, which makes the overdue list useless rather '
                    . 'than urgent.', (string) ($steps[$name]['label'] ?? $name));
            }

            $threshold = $change['when_amount_over'] ?? null;

            if ($threshold !== null && $threshold !== '' && Amount::of($threshold)->isNegative()) {
                $problems[] = sprintf('The threshold on "%s" cannot be negative.',
                    (string) ($steps[$name]['label'] ?? $name));
            }
        }

        return array_merge($problems, self::backwards($override, $shipped));
    }

    /**
     * THE CHECK THIS FILE EXISTS FOR.
     *
     * An approval chain is meant to add people as the amount grows. If a later
     * step has a LOWER threshold than an earlier one, there is a band of amounts
     * that reaches the senior approver and skips the junior one — a ₹2,000 claim
     * signed by the director that finance never saw. Nothing errors. The routing
     * is doing exactly what it was told; what it was told is inverted.
     *
     * @return list<string>
     */
    private static function backwards(array $override, array $shipped): array
    {
        $problems = [];
        // THE THRESHOLDS ARE COMPARED EXACTLY, in minor units. This ran on
        // floats, and it is the check that decides whether an approval chain is
        // inverted — the one place a hundredth of a paisa decides who signs.
        $highest = Money::zero();
        $highestLabel = '';

        foreach ((array) $shipped['steps'] as $step) {
            if ((string) ($step['type'] ?? '') !== 'approval') {
                continue;
            }

            $key = (string) $step['key'];
            $change = (array) ($override['steps'][$key] ?? []);
            $threshold = array_key_exists('when_amount_over', $change)
                ? $change['when_amount_over']
                : ($step['when_amount_over'] ?? null);
            $label = (string) ($change['label'] ?? $step['label']);

            if ($threshold === null || $threshold === '') {
                // A step with no threshold always applies, so it can never be
                // skipped and cannot invert anything below it.
                continue;
            }

            $amount = Amount::of($threshold);

            if ($amount->compareTo($highest) < 0) {
                $problems[] = sprintf(
                    '%s would see requests from %s while %s only sees them from %s, so anything '
                    . 'between the two reaches the later approver and skips the earlier one — a '
                    . 'claim signed by %s that %s never saw. Nothing would error; the chain would '
                    . 'simply be inverted.',
                    $label, Requests::amountLabel($amount->toDecimalString(), $shipped),
                    $highestLabel, Requests::amountLabel($highest->toDecimalString(), $shipped),
                    $label, $highestLabel,
                );
            }

            if ($amount->compareTo($highest) > 0) {
                $highest = $amount;
                $highestLabel = $label;
            }
        }

        return $problems;
    }
}
