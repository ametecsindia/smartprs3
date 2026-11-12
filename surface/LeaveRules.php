<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHETHER A LEAVE REQUEST MAY BE MADE — leave.request.validate.
 *
 * `Leave::request()` checked that the dates spanned a day and that a reason was
 * typed, and nothing else. An employee with three days of earned leave could ask
 * for forty; the request was written, it joined the approvals queue looking
 * exactly like every other row, and the only thing between it and a negative
 * balance was an approver noticing something the screen does not show them.
 *
 * The rules that close that are in `config/leave-rules.json`, evaluated by the
 * same engine and the same SPX the attendance rules use.
 *
 * ALL_MATCH, NOT HIGHEST-PRIORITY-ONLY, and the difference is the person. One
 * request can be refused for its balance AND need a document for its length, and
 * a decision point that reports only the winner tells them one thing, waits for
 * them to fix it, then tells them the next. Everything that applies is returned
 * at once.
 *
 * IT REFUSES RATHER THAN PASSES WHEN IT CANNOT DECIDE. A missing rules file, an
 * unparseable rule and an engine failure all produce `undecided()`, and
 * `Leave::request()` treats that as a refusal — because a validator that cannot
 * run and a validator that found nothing wrong are the same shape on screen and
 * opposite facts, and the safe reading of "the policy did not load" is not
 * "there is no policy".
 */
final class LeaveRules
{
    public const DECISION_POINT = 'leave.request.validate';

    private readonly PublishedRules $published;

    public function __construct(string $file, string $tenantId = 'T-DEMO')
    {
        $this->published = new PublishedRules($file, self::DECISION_POINT, 'leave_request', $tenantId);
    }

    /**
     * Decide one request before it is written.
     *
     * @param array<string, mixed> $values SPX symbols for the decision point
     * @return array{
     *     allowed: bool, undecided: bool, blocks: list<array{code: string, reason: string, rule: string}>,
     *     documents: list<array{type: string, by: string, rule: string}>, why: string, trace: ?string
     * }
     */
    public function validate(string $employmentId, string $on, array $values): array
    {
        $result = $this->published->evaluate($employmentId, $on, $values);

        if ($result === null) {
            return $this->undecided((string) $this->published->refusal());
        }

        $blocks = [];

        foreach ($result->actionsOfCode('BLOCK') as $action) {
            $rule = (string) ($action->emittedBy['rule_code'] ?? '');
            $blocks[] = [
                'code' => (string) ($action->parameters['code'] ?? 'BLOCKED'),
                'reason' => (string) ($action->parameters['reason'] ?? ''),
                'rule' => $rule,
            ];
        }

        $documents = [];

        foreach ($result->actionsOfCode('REQUIRE_DOCUMENT') as $action) {
            $documents[] = [
                'type' => (string) ($action->parameters['doc_type'] ?? 'SUPPORTING'),
                'by' => (string) ($action->parameters['by'] ?? ''),
                'rule' => (string) ($action->emittedBy['rule_code'] ?? ''),
            ];
        }

        return [
            'allowed' => $blocks === [],
            'undecided' => false,
            'blocks' => $blocks,
            'documents' => $documents,
            'why' => $blocks === []
                ? 'No published rule refuses this request.'
                : implode(' ', array_map(
                    fn (array $b): string => $b['reason'] !== ''
                        ? $b['reason']
                        : ($this->published->descriptionOf($b['rule']) ?? $b['code']),
                    $blocks,
                )),
            'trace' => $result->trace->render(),
        ];
    }

    /**
     * @return array{allowed: false, undecided: true, blocks: list<array{code: string, reason: string,
     *     rule: string}>, documents: list<never>, why: string, trace: null}
     */
    private function undecided(string $why): array
    {
        // ALLOWED IS FALSE HERE ON PURPOSE. The caller could be trusted to read
        // `undecided` and decide for itself, and one caller eventually will not
        // — so the field every caller looks at first says no. A validator that
        // could not run is not a validator that found nothing.
        return [
            'allowed' => false,
            'undecided' => true,
            'blocks' => [['code' => 'POLICY_NOT_LOADED', 'reason' => $why, 'rule' => '']],
            'documents' => [],
            'why' => $why,
            'trace' => null,
        ];
    }

    public function refusal(): ?string
    {
        return $this->published->refusal();
    }

    /** @return list<\SmartPRS\Platform\Rules\Rule> */
    public function rules(): array
    {
        return $this->published->rules();
    }
}
