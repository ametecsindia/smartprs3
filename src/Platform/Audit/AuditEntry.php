<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Audit;

/**
 * One entry of the Law 5 / S14 ledger, and — more importantly — the single
 * definition of WHAT IS HASHED.
 *
 * The chain is only evidence if the bytes that were hashed at write time are
 * the bytes recomputed at verify time. Two places building that payload
 * independently is how a chain ends up "broken" everywhere with nothing
 * actually tampered, so `payload()` below is the only place it is built, and
 * both AuditLedger::append() and AuditLedger::verify() call it.
 *
 * Fields NOT in the payload are as deliberate as the fields in it. `seq`,
 * `prev_hash` and `hash` are chain mechanics, not content — `seq` is covered by
 * being part of the ordering the chain walks, and prev_hash is mixed in by
 * HashChain::next() rather than being a payload key. `created_at` is the
 * database's record of when the row was written; `occurred_at` is when the
 * ACTION happened, and that is the one the evidence is about.
 */
final readonly class AuditEntry
{
    /**
     * @param string      $action       verb.resource, matching the permission vocabulary (S13)
     * @param array<string, mixed>|null $oldValue  field-level before; mandatory for
     *                                             employee, salary, bank, role, statutory and rule changes
     * @param array<string, mixed>|null $newValue
     */
    public function __construct(
        public string $action,
        public string $entityType,
        public \DateTimeImmutable $occurredAt,
        public \DateTimeImmutable $businessOn,
        public ?int $entityId = null,
        public ?string $actorUserId = null,
        public ?string $actorLabel = null,
        public ?string $actorIp = null,
        public ?string $actorDevice = null,
        public ?array $oldValue = null,
        public ?array $newValue = null,
        public ?string $reason = null,
        public ?int $ruleVersionId = null,
        public ?int $workflowInstanceId = null,
        public ?string $requestId = null,
        public ?string $correlationId = null,
    ) {
        if (trim($action) === '') {
            throw new \InvalidArgumentException('An audit entry names the action taken. A blank action is not evidence.');
        }

        if (trim($entityType) === '') {
            throw new \InvalidArgumentException("Audit entry '{$action}' names no entity type.");
        }
    }

    /**
     * What the chain hashes.
     *
     * `occurred_at` is rendered to the second in UTC rather than handed over as
     * a DateTimeImmutable with a timezone attached: the same instant expressed
     * in two zones must hash the same, or a tenant that changes timezone breaks
     * its own history.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'action' => $this->action,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'business_on' => $this->businessOn->format('Y-m-d'),
            'actor_user_id' => $this->actorUserId,
            'actor_label' => $this->actorLabel,
            'actor_ip' => $this->actorIp,
            'actor_device' => $this->actorDevice,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
            'reason' => $this->reason,
            'rule_version_id' => $this->ruleVersionId,
            'workflow_instance_id' => $this->workflowInstanceId,
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
        ];
    }

    public function describe(): string
    {
        return $this->action . ' on ' . $this->entityType
            . ($this->entityId === null ? '' : ' #' . $this->entityId)
            . ' at ' . $this->occurredAt->format('Y-m-d H:i:s');
    }
}
