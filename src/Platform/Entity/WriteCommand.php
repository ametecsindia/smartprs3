<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * ONE WRITE, STATED IN FULL — Part X §X.5.1.
 *
 * The parameter that does the most work is `surface`: "mandatory, never
 * inferred". SmartPRS 2 has nine write paths and no surface concept, so which
 * rules applied to a save depended on which controller you happened to reach —
 * and three of the nine were not documented as employee writers at all.
 * Requiring the surface here means a caller cannot write employee data without
 * saying, in one word, which of the thirteen it is acting as.
 *
 * `actorRoles` is here rather than being looked up because the write service
 * holds no repository, the same stance the rule and formula engines take. What
 * it does mean is that a caller supplying no roles gets a write that resolves to
 * hidden everywhere and applies nothing, which is the correct answer to "somebody
 * with no roles edited an employee".
 */
final readonly class WriteCommand
{
    /**
     * @param array<string, mixed> $changes ONLY the fields being changed (R-X-036); declared wide
     *        because this constructor is the write API boundary — a caller can hand it anything,
     *        and the loop below is what actually proves each value is a ChangeValue
     * @param list<string>               $actorRoles
     * @param list<array{collection: string, op: string, key: ?string, values: array<string, mixed>}> $childOperations
     */
    public function __construct(
        public string $entityKey,
        public ?string $entityUuid,
        public Surface $surface,
        public string $actorUserId,
        public array $actorRoles,
        public array $changes,
        public \DateTimeImmutable $effectiveFrom,
        public array $childOperations = [],
        public ?string $reasonCode = null,
        public ?string $reasonText = null,
        public ?string $idempotencyKey = null,
        public ?string $sourceReference = null,
        public ?int $expectedRowVersion = null,
        public bool $partialOk = false,
    ) {
        if (!$surface->writes()) {
            throw new \InvalidArgumentException(
                "{$surface->value} is a read surface ({$surface->label()}). A write naming it would be a write "
                . 'nobody could have intended, and the thirteen surfaces exist so that this is a type error '
                . 'rather than a discovery (R-X-029).'
            );
        }

        if ($changes === [] && $childOperations === []) {
            throw new \InvalidArgumentException(
                'A write command changes something. An empty command is not a no-op to be absorbed quietly: it '
                . 'means a caller built a command from an empty form, and it should say so where it happened.'
            );
        }

        foreach ($changes as $fieldKey => $change) {
            if (!$change instanceof ChangeValue) {
                throw new \InvalidArgumentException(
                    "The change for '{$fieldKey}' is a " . get_debug_type($change) . ', not a ChangeValue. '
                    . 'A bare value cannot say whether null means "clear this" or "I am not touching it" '
                    . '(R-X-036).'
                );
            }
        }

        foreach ($childOperations as $operation) {
            if (!in_array($operation['op'] ?? '', ['add', 'update', 'remove'], true)) {
                throw new \InvalidArgumentException(
                    'A child operation is add, update or remove, keyed on the child (R-X-041). '
                    . 'Delete-all-then-reinsert is forbidden: SmartPRS 2 deletes every employee reference on '
                    . 'each save and re-inserts from the payload, so a save that omits them wipes the '
                    . 'verification history with no audit and no undo.'
                );
            }

            if (($operation['op'] ?? '') !== 'add' && ($operation['key'] ?? null) === null) {
                throw new \InvalidArgumentException(
                    "A child {$operation['op']} names the child it acts on. An unkeyed update is an update to "
                    . 'whichever row the query happened to return first.'
                );
            }
        }

        // R-X-044: an unsafe API call carries an idempotency key. Interactive
        // surfaces do not, because a person clicking Save twice is a different
        // problem with a different answer (the row_version check).
        if ($surface === Surface::Api && $idempotencyKey === null) {
            throw new \InvalidArgumentException(
                'An API write carries an idempotency_key (R-X-044, spine S17). Without one a retried request '
                . 'after a timeout applies the change twice, and the caller cannot tell which of the two '
                . 'happened.'
            );
        }
    }

    public function isCreate(): bool
    {
        return $this->entityUuid === null;
    }

    /** @return list<string> */
    public function fieldKeys(): array
    {
        return array_keys($this->changes);
    }
}
