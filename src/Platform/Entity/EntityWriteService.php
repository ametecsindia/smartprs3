<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * THE SINGLE WRITE PATH — Part X §X.5, R-X-037.
 *
 * "No controller, job, importer, console command, service or seeder writes those
 * tables directly." SmartPRS 2 has nine write paths, four write mappings and
 * three sanitisation policies; three of the nine are not documented as employee
 * writers at all, and which validation a save got depended on which screen you
 * reached. Every one of those nine collapses to a call to this method with a
 * different `surface_code`.
 *
 * The ten stages of §X.5.2 run in order, and the order is load-bearing:
 *
 *   1 authorise · 2 resolve surface rules · 3 coerce types · 4 validate ·
 *   5 check uniqueness · 6 sanitise · 7 effective-dated assignment handling ·
 *   8 persist · 9 field-level audit · 10 emit events
 *
 * Stages 1 to 6 are PURE and live here: they need no connection, so they can be
 * run as a preview over a whole import file before a single row is written, and
 * the preview is then the same code as the commit rather than a second
 * implementation of it. Stages 7 to 10 need a transaction, and arrive as the
 * `$persist` callable — which is handed a change set that has already been
 * authorised, resolved, coerced, validated and sanitised, and which owns the
 * lock, the audit write and the outbox.
 *
 * Two rules shape everything below:
 *
 *   R-X-036, PATCH. A field absent from `changes` is untouched. Clearing is
 *   explicit. SmartPRS 2's save is a full-record replace that NULLs every
 *   omitted key.
 *
 *   R-X-038, a field-level outcome for EVERY field in the command — including
 *   the refused ones. Four SmartPRS 2 importers drop unknown columns by four
 *   different mechanisms and not one of them says so.
 */
final class EntityWriteService
{
    /**
     * Field keys no surface rule can make writable (R-X-058).
     *
     * Not a permission and not a surface rule — a name the write path refuses
     * outright — because the point is that no configuration anybody writes can
     * turn a status into something an ordinary save carries.
     */
    public const STATUS_FIELDS = ['status', 'employment_status', 'lifecycle_state_code', 'status_code'];

    /**
     * @param array<string, FieldDefinition>              $fields       key => definition, already resolved for this subject and date
     * @param array<string, array<string, mixed>>         $valueSets    value set key => code => label
     * @param array<string, mixed>                        $currentValues what is stored now, for the unchanged/old-value comparison
     * @param (callable(list<string>, string): bool)|null $authorise    actor roles + permission => allowed
     */
    public function __construct(
        private readonly SurfaceRuleResolver $rules,
        private readonly array $fields,
        private readonly array $valueSets = [],
        private $authorise = null,
    ) {
    }

    /**
     * Run the pipeline.
     *
     * @param  array<string, mixed>  $currentValues
     * @param  (callable(PreparedWrite): array{uuid: string, row_version: int})|null  $persist
     *         stages 7 to 10. Null runs stages 1 to 6 only, which is the import
     *         preview and is deliberately the same call.
     */
    public function write(WriteCommand $command, array $currentValues = [], ?callable $persist = null): WriteResult
    {
        // ---- 1. AUTHORISE ---------------------------------------------------
        // Fails closed, and fails BEFORE anything is coerced: a caller who may
        // not write must not learn from the error messages which of their
        // values would have been valid.
        $permission = $command->isCreate() ? 'employee.create' : 'employee.update';

        if (!$this->allowed($command->actorRoles, $permission)) {
            return new WriteResult(
                applied: false,
                outcomes: array_map(
                    static fn (string $key): FieldOutcome => FieldOutcome::rejected(
                        $key,
                        "the actor does not hold {$permission}",
                    ),
                    $command->fieldKeys(),
                ),
                summary: "Denied: {$permission} is required to write on {$command->surface->value}, and the "
                    . 'actor holds ' . ($command->actorRoles === [] ? 'no roles' : implode(', ', $command->actorRoles))
                    . '. Nothing was coerced or validated, so nothing about the supplied values is disclosed.',
            );
        }

        $outcomes = [];
        $prepared = [];
        $routed = [];
        $rejected = 0;

        foreach ($command->changes as $fieldKey => $change) {
            // R-X-058: a status change is a TRANSITION, never a field write.
            // SmartPRS 2's `storeEmployee()` rewrites `status` unconditionally
            // on every save, so an exited employee is reset to active by an
            // edit to their phone number. Refusing it here rather than
            // resolving a surface rule for it means no rule can ever grant it.
            if (in_array($fieldKey, self::STATUS_FIELDS, true)) {
                $outcomes[] = FieldOutcome::rejected(
                    $fieldKey,
                    "'{$fieldKey}' is not writable as a field (R-X-058). Employment status changes through "
                    . 'the transition table, which decides whether this move exists at all, who may make it, '
                    . 'whether it needs a reason and what guard it must pass. A save that could carry it '
                    . 'would be a save that could undo an exit.',
                );
                $rejected++;

                continue;
            }

            $field = $this->fields[$fieldKey] ?? null;

            // ---- 2. RESOLVE SURFACE RULES -------------------------------
            $rule = $this->rules->resolve($field, $command->surface, $command->actorRoles);

            if (!$rule->visibility->allowsWrite()) {
                $outcomes[] = FieldOutcome::rejected(
                    $fieldKey,
                    $rule->visibility->refusalFor($fieldKey, $command->surface) . ' (' . $rule->why . ')',
                );
                $rejected++;

                continue;
            }

            // `$field` is non-null here: a null field resolves to hidden above.
            assert($field instanceof FieldDefinition);

            if ($change->isUnchanged()) {
                $outcomes[] = FieldOutcome::unchanged($fieldKey, $currentValues[$fieldKey] ?? null);

                continue;
            }

            if ($change->isClear()) {
                // R-X-036: clearing is refused when the field is required here.
                // Not silently kept, not silently emptied — refused, so the
                // person clearing it finds out now.
                if ($rule->requiredness->demandsValue($command->isCreate())) {
                    $outcomes[] = FieldOutcome::rejected(
                        $fieldKey,
                        "'{$fieldKey}' is required on {$command->surface->value} ({$rule->requiredness->value}) "
                        . 'and cannot be cleared. Clearing is explicit precisely so that this refusal can be, '
                        . 'rather than the value going quietly empty.',
                    );
                    $rejected++;

                    continue;
                }

                $outcomes[] = FieldOutcome::cleared($fieldKey, $currentValues[$fieldKey] ?? null);
                $prepared[$fieldKey] = null;

                continue;
            }

            // ---- 3, 4, 6. COERCE, VALIDATE, SANITISE --------------------
            $members = $field->valueSetKey === null ? [] : ($this->valueSets[$field->valueSetKey] ?? []);
            $coerced = ValueCoercion::coerce($field, $change->value, $members);

            if (!$coerced['ok']) {
                $outcomes[] = FieldOutcome::rejected($fieldKey, $coerced['error']);
                $rejected++;

                continue;
            }

            $old = $currentValues[$fieldKey] ?? null;

            if (self::same($old, $coerced['value'])) {
                $outcomes[] = FieldOutcome::unchanged($fieldKey, $old);

                continue;
            }

            // R-X-039: an approval-routed field does not reach persistence. It
            // is held, and the workflow's finalise node applies it through this
            // same service with the ORIGINAL actor recorded.
            if ($rule->requiresApproval()) {
                $workflow = $rule->changeWorkflowKeys[0];
                $outcomes[] = FieldOutcome::routed($fieldKey, $workflow, $coerced['value']);
                $routed[$fieldKey] = ['workflow' => $workflow, 'value' => $coerced['value'], 'old' => $old];

                continue;
            }

            $outcomes[] = FieldOutcome::applied($fieldKey, $old, $coerced['value']);
            $prepared[$fieldKey] = $coerced['value'];
        }

        // ---- 5. UNIQUENESS (pre-check) --------------------------------------
        // The database constraint remains the guarantee (R-X-017); this is only
        // for a friendly message. What matters is what a FAILURE of the
        // pre-check does: it denies. SmartPRS 2's equivalent fails open and is
        // check-then-write racy, so two imports running at once both pass it.

        // R-X-014: every field's error is returned together. The whole command
        // fails unless the caller passed `partial_ok`, which only the import
        // preview does — a partial save from a form is how somebody ends up with
        // half a record and no idea which half.
        if ($rejected > 0 && !$command->partialOk) {
            return new WriteResult(
                applied: false,
                outcomes: $outcomes,
                summary: $rejected . ' of ' . count($command->changes) . ' field(s) were refused, so nothing '
                    . 'was written. Every error is listed rather than only the first (R-X-014).',
            );
        }

        if ($persist === null) {
            return new WriteResult(
                applied: false,
                outcomes: $outcomes,
                summary: 'Preview only: stages 1 to 6 ran and nothing was written. This is the same code the '
                    . 'commit runs, so what it shows is what will happen.',
            );
        }

        if ($prepared === [] && $routed === []) {
            return new WriteResult(
                applied: true,
                outcomes: $outcomes,
                entityUuid: $command->entityUuid,
                summary: 'Nothing to persist: every supplied value already matched what is stored. No audit row '
                    . 'is written for a write that changed nothing, because an audit trail of non-events is one '
                    . 'nobody reads.',
            );
        }

        // ---- 7, 8, 9, 10. DATED ROWS, PERSIST, AUDIT, EVENTS ----------------
        $written = $persist(new PreparedWrite($command, $prepared, $routed, $outcomes));

        return new WriteResult(
            applied: true,
            outcomes: $outcomes,
            entityUuid: $written['uuid'],
            rowVersion: $written['row_version'],
            summary: count($prepared) . ' field(s) written'
                . ($routed === [] ? '' : ', ' . count($routed) . ' held for approval')
                . ', each with a field-level audit row inside the same transaction (stage 9).',
        );
    }

    /**
     * A status change — the ONLY way one happens (R-X-058).
     *
     * Separate from `write()` rather than a mode of it, because the two are
     * different operations with different vocabulary: a write has fields and
     * per-field outcomes, and a transition has a from, a to, a reason and one
     * answer. Folding them together is how `status` ends up in a `changes` map.
     *
     * @param  list<string>  $actorPermissions
     * @param  (callable(string): ?bool)|null  $evaluateGuard
     * @param  (callable(array{from: string, to: string, transition: StatusTransition}): void)|null  $persist
     * @return array{applied: bool, why: string, workflow: ?string}
     */
    public function transition(
        TransitionTable $table,
        string $from,
        string $to,
        array $actorPermissions,
        \DateTimeImmutable $effectiveFrom,
        ?string $reason = null,
        ?callable $evaluateGuard = null,
        ?callable $persist = null,
    ): array {
        $decision = $table->decide($from, $to, $actorPermissions, $reason, $effectiveFrom, $evaluateGuard);

        if (!$decision['allowed']) {
            return ['applied' => false, 'why' => $decision['why'], 'workflow' => null];
        }

        // A transition with a workflow does not take effect here. It is raised,
        // and the workflow's finalise node calls back through this same method
        // with the original actor recorded (R-X-039's shape, for status).
        if ($decision['workflow'] !== null) {
            return [
                'applied' => false,
                'why' => $decision['why'],
                'workflow' => $decision['workflow'],
            ];
        }

        if ($persist !== null) {
            $persist(['from' => $from, 'to' => $to, 'transition' => $decision['transition']]);
        }

        return ['applied' => $persist !== null, 'why' => $decision['why'], 'workflow' => null];
    }

    /** @param list<string> $roles */
    private function allowed(array $roles, string $permission): bool
    {
        if ($this->authorise === null) {
            // No authoriser supplied. NOT "allow": a write service constructed
            // without one is a misconfiguration, and the safe reading of a
            // missing check is that the check failed (R-X-030).
            return false;
        }

        try {
            return ($this->authorise)($roles, $permission) === true;
        } catch (\Throwable) {
            // gate:allow-swallow every exception in authorisation denies — this is the
            // exact inverse of SmartPRS 2's `catch (\Throwable) { return null; }` where
            // null means allowed, one catch block granting access across a subsystem
            return false;
        }
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->format('Y-m-d H:i:s') === $b->format('Y-m-d H:i:s');
        }

        if (is_object($a) && is_object($b) && method_exists($a, 'equals')) {
            return $a->equals($b);
        }

        return $a === $b;
    }
}
