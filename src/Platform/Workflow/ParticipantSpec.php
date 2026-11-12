<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * One participant specification on a node (Part IX §IX.3.4, §IX.5.2).
 *
 * Five kinds, plus three convenience kinds that exist "because they are what
 * customers actually say". `spx_expression` is the escape hatch and is
 * deliberately the narrowest one: it returns identifiers, never side effects,
 * and any evaluation error FAILS the node rather than yielding an empty set —
 * because an empty set silently means "nobody needs to approve this".
 */
final readonly class ParticipantSpec
{
    public const KINDS = [
        'role_in_scope',
        'named_user',
        'reporting_line',
        'group',
        'spx_expression',
        // Shorthand for the above (§IX.5.2).
        'requester',
        'previous_node_actor',
        'initiator_manager',
    ];

    /** §IX.5.2 `on_short_line`, for reporting_line. */
    public const SHORT_LINE = ['use_topmost', 'fail', 'fallback_participant'];

    /**
     * @param array<string, scalar|null> $params
     */
    public function __construct(
        public string $kind,
        public array $params = [],
        public int $seq = 0,
        public bool $optional = false,
        public bool $excludeRequester = true,
        public bool $excludePriorActors = true,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(
                "'{$kind}' is not a participant kind. One of: " . implode(', ', self::KINDS) . ' (§IX.5.2).'
            );
        }

        if ($kind === 'named_user' && ($params['user_id'] ?? '') === '') {
            throw new \InvalidArgumentException('A named_user participant needs a user_id.');
        }

        if ($kind === 'role_in_scope' && ($params['role_key'] ?? '') === '') {
            throw new \InvalidArgumentException('A role_in_scope participant needs a role_key.');
        }

        if ($kind === 'reporting_line') {
            $level = $params['level_n'] ?? null;

            if (!is_int($level) || $level < 1) {
                throw new \InvalidArgumentException('A reporting_line participant needs level_n >= 1.');
            }

            $onShort = $params['on_short_line'] ?? 'fail';

            if (!in_array($onShort, self::SHORT_LINE, true)) {
                throw new \InvalidArgumentException(
                    "on_short_line is one of: " . implode(', ', self::SHORT_LINE) . ' (§IX.5.2).'
                );
            }
        }
    }

    /**
     * §IX.5.2: a named user is the most brittle kind. The validator warns at
     * publish that it is a single point of failure and suggests a role — a warn
     * rather than a block, because a five-person tenant with one proprietor is
     * a real shape, and refusing it would push them back to a spreadsheet.
     */
    public function isSinglePointOfFailure(): bool
    {
        return $this->kind === 'named_user';
    }

    public function describe(): string
    {
        $detail = match ($this->kind) {
            'named_user' => (string) ($this->params['user_id'] ?? '?'),
            'role_in_scope' => (string) ($this->params['role_key'] ?? '?')
                . ' in ' . (string) ($this->params['data_scope_code'] ?? 'their scope'),
            'reporting_line' => 'level ' . (string) ($this->params['level_n'] ?? '?'),
            'group' => (string) ($this->params['group_id'] ?? '?'),
            'previous_node_actor' => (string) ($this->params['node_key'] ?? '?'),
            default => '',
        };

        return $this->kind . ($detail === '' ? '' : "({$detail})");
    }
}
