<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\DecisionPoints;
use SmartPRS\Platform\Rules\Engine;
use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\EvaluationResult;
use SmartPRS\Platform\Rules\Rule;
use SmartPRS\Platform\Rules\RuleEvaluationFailed;
use SmartPRS\Platform\Rules\RuleSet;
use SmartPRS\Platform\Rules\SpxExpression;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * ONE BRIDGE FROM A JSON FILE TO THE RULE ENGINE, for any decision point.
 *
 * `DayRules` was written for `attendance.day.classify` and did this job inline.
 * When `leave.request.validate` needed the identical hundred lines — read the
 * file, build real `Rule` objects with real SPX, hand them to the real `Engine`,
 * report a refusal rather than a default — the choice was to copy it or to lift
 * it, and a second copy of a rules bridge is a second set of failure modes that
 * have to be discovered twice.
 *
 * Extracted at the second consumer and not before: one caller does not tell you
 * which parts are general, and the parts that turned out NOT to be general are
 * the interesting half — what to do with the actions the engine emits is
 * entirely the caller's business, so this class stops at handing back the
 * `EvaluationResult` and never interprets one.
 *
 * IT FAILS TO A REFUSAL, NOT TO AN EMPTY SET. Three things can go wrong — the
 * file is missing, a rule does not parse, the engine refuses the subject — and
 * all three produce a null result with `refusal()` explaining it. Returning "no
 * rules matched" for "the file could not be read" is the difference between a
 * policy that says nothing and a policy nobody loaded, and those look identical
 * from the calling screen unless something insists on the distinction.
 *
 * ONE BAD RULE PUBLISHES NONE OF THEM. Skipping the unparseable one and carrying
 * on leaves every decision taken by whichever rules happened to compile, and the
 * day a typo removes the rule that blocks an over-drawn leave request is the day
 * everybody's balance goes negative on a screen that looks entirely normal.
 */
final class PublishedRules
{
    /** @var list<Rule>|null built once per request, not once per subject */
    private ?array $rules = null;

    private ?string $refusal = null;

    /** @var array<string, string> rule code => the business description it carries */
    private array $descriptions = [];

    /** @var array<string, mixed> whatever else the file declares, for the caller */
    private array $declared = [];

    private readonly Engine $engine;

    public function __construct(
        private readonly string $file,
        private readonly string $decisionPoint,
        private readonly string $subjectType,
        private readonly string $tenantId = 'T-DEMO',
    ) {
        $this->engine = new Engine();
    }

    /**
     * Evaluate one subject. Null means the engine did not answer — ask `refusal()`.
     *
     * @param array<string, mixed> $values SPX symbols for this decision point
     */
    public function evaluate(string $subjectId, string $date, array $values): ?EvaluationResult
    {
        $rules = $this->rules();

        if ($rules === []) {
            $this->refusal ??= 'No rules are published for ' . $this->decisionPoint . '.';

            return null;
        }

        $context = new EvaluationContext(
            tenantId: $this->tenantId,
            subjectType: $this->subjectType,
            subjectId: $subjectId,
            subjectDate: new \DateTimeImmutable($date),
            values: $values,
        );

        try {
            return $this->engine->evaluate($this->decisionPoint,
                RuleSet::of($this->decisionPoint, ...$rules), $context);
        } catch (RuleEvaluationFailed $e) {
            // Surfaced, not swallowed. A caught-and-ignored evaluation failure is
            // a subject that silently takes whatever the caller's default was,
            // which on this decision point means an unchecked leave request.
            $this->refusal = 'The rule engine refused this subject: ' . $e->getMessage();

            return null;
        }
    }

    /** The business description of a rule, for the person the decision is about. */
    public function descriptionOf(string $code): ?string
    {
        $this->rules();

        return $this->descriptions[$code] ?? null;
    }

    /**
     * Anything else the file declares beside `rules` — the attendance file's
     * `statuses` weights, for instance. Returned raw: what it means is the
     * caller's business, and a bridge that interprets it is a bridge that has to
     * change every time a new decision point declares something new.
     */
    public function declared(string $key): mixed
    {
        $this->rules();

        return $this->declared[$key] ?? null;
    }

    /** @return list<Rule> empty when nothing could be published; see refusal() */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $this->rules = [];
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['rules'] ?? null)) {
            $this->refusal = basename($this->file) . ' is missing or unreadable.';

            return [];
        }

        $this->declared = array_diff_key($decoded, ['rules' => null]);
        $symbols = DecisionPoints::symbols($this->decisionPoint);

        foreach ($decoded['rules'] as $declared) {
            $code = (string) ($declared['code'] ?? '');

            try {
                $actions = [];

                foreach ($declared['then'] ?? [] as $step) {
                    $parameters = [];

                    foreach (array_diff_key($step, ['action' => null]) as $name => $value) {
                        // AN EXPRESSION SAYS SO. A parameter written as
                        // {"expr": "attendance.ot_minutes"} is parsed and
                        // resolved against the subject; anything else is a
                        // literal and is passed through untouched.
                        //
                        // Explicit, because the alternative is guessing whether
                        // a string looks like SPX — and the first thing that
                        // guess gets wrong is a `reason` written in English that
                        // happens to parse. It also caught its own absence:
                        // `"minutes": "attendance.ot_minutes"` was emitting the
                        // NAME of the symbol as the value of an overtime entry,
                        // silently, because Action::resolve() only evaluates
                        // Expression instances and a bare string is not one.
                        $parameters[$name] = is_array($value) && isset($value['expr'])
                            ? SpxExpression::parse((string) $value['expr'], $symbols, false)
                            : $value;
                    }

                    $actions[] = new Action((string) ($step['action'] ?? ''), $parameters);
                }

                $this->rules[] = new Rule(
                    code: $code,
                    decisionPoint: $this->decisionPoint,
                    module: (string) ($declared['module'] ?? 'platform'),
                    name: (string) ($declared['name'] ?? $code),
                    businessDescription: (string) ($declared['description'] ?? ''),
                    scope: Scope::tenantDefault(),
                    priority: (int) ($declared['priority'] ?? 0),
                    range: EffectiveRange::fromInclusive((string) ($declared['from'] ?? '2026-01-01'),
                        $declared['to'] ?? null),
                    versionNo: 1,
                    versionId: strtolower($code) . '-v1',
                    status: 'published',
                    when: SpxExpression::parse((string) ($declared['when'] ?? ''), $symbols, true),
                    then: $actions,
                    except: isset($declared['except'])
                        ? SpxExpression::parse((string) $declared['except'], $symbols, true)
                        : null,
                    // THE DECISION POINT'S OWN POLICY, asked of the platform.
                    // This defaulted to HighestPriorityOnly and read an
                    // `on_conflict` key out of the JSON — so config/leave-rules.json
                    // could declare `"policy": "all_match"` in prose at the top,
                    // be believed by everybody reading it, and evaluate as
                    // highest-priority-only: a request over its balance reported
                    // the balance and stayed silent about the document it also
                    // needed. `DecisionPoints::conflictPolicy()` is where that
                    // fact actually lives, and a second copy of it in a config
                    // file is a second copy that can disagree.
                    onConflict: DecisionPoints::conflictPolicy($this->decisionPoint),
                );

                $this->descriptions[$code] = (string) ($declared['description'] ?? '');
            } catch (\Throwable $e) {
                $this->rules = [];
                $this->refusal = "Rule '{$code}' in " . basename($this->file) . ' was refused: '
                    . $e->getMessage() . ' No subject is decided while a declared rule is invalid.';

                return [];
            }
        }

        return $this->rules;
    }

    /**
     * The aggregation counters every published rule needs PREPARED before it can
     * be evaluated (§5.9).
     *
     * The engine never queries anything — it reads what the caller assembled and
     * nothing else, which is what makes a trace reproducible and the simulator
     * safe. So a rule counting late arrivals over a payroll month needs its
     * counter supplied under a canonical key, and an unprepared counter is a
     * named ERR_AGG_UNAVAILABLE rather than a silent zero. This tells the caller
     * exactly which keys to fill.
     *
     * @return list<string>
     */
    public function preparedKeys(): array
    {
        $keys = [];

        foreach ($this->rules() as $rule) {
            foreach ([$rule->when, $rule->except] as $expression) {
                if (!$expression instanceof SpxExpression) {
                    continue;
                }

                foreach ($expression->preparedKeys() as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /** Why nothing was published, or why the last evaluation did not answer. */
    public function refusal(): ?string
    {
        $this->rules();

        return $this->refusal;
    }
}
