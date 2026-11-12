<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Persistence;

use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\InstanceState;

/**
 * WHERE AN INSTANCE'S WORKFLOW VERSION IS PINNED, AND WHERE IT IS READ BACK —
 * Part IX §IX.3.4, R-IX-016: "an instance mid-flight on v1 completes on v1
 * while a new instance starts on v2."
 *
 * `workflow_instances.config_version_id` has existed since the workflow
 * migration and NOTHING READ IT. Every table in this schema can be written by
 * something and read by nothing, and the column that pins a running approval to
 * the rules it started under is the one where that matters most: without a
 * reader, "the instance completes on v1" is a sentence about a column, and the
 * only version anybody could actually load is whatever resolve() returns today.
 *
 * So there are exactly two ways to get a version here, and they are deliberately
 * not the same call:
 *
 *   `start()` RESOLVES — as at the moment the instance begins, through the
 *   ordinary spine S4 read — and stores what it resolved. That is the only
 *   resolution an instance ever does.
 *
 *   `pinnedVersion()` READS THAT ROW BACK. It does not resolve, it does not
 *   take a date, and it cannot be made to return a newer version, because a
 *   version that changes under a running approval is how somebody gets approved
 *   by a chain nobody ever agreed to.
 *
 * SmartPRS 2 has neither: its approval steps are read from the current
 * configuration on every request, so editing an approval chain silently
 * rewrites every request already in flight.
 */
final class InstanceRepository
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
        private readonly StatusLookup $states,
        private readonly ConfigVersionRepository $versions,
    ) {
    }

    /**
     * Begin an instance, pinned to the version that governs it NOW.
     *
     * @param array<string, string|int|null> $subjectDimensions the scope the
     *        workflow is resolved against — branch, grade, and the rest of S3.1
     *
     * @throws \SmartPRS\Platform\Support\UnresolvedConfiguration when no version governs
     * @throws \SmartPRS\Platform\Support\AmbiguousConfiguration  when two tie
     */
    public function start(
        string $artefactKey,
        string $subjectEntityKey,
        int $subjectId,
        string $requesterUserId,
        \DateTimeImmutable $at,
        array $subjectDimensions = [],
        ?string $firstNodeKey = null,
        ?string $instanceKey = null,
    ): int {
        $version = $this->versions->resolve('workflow', $artefactKey, $subjectDimensions, $at);

        return (int) TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_instances')->insert([
            'uuid' => random_bytes(16),
            'config_version_id' => (int) $version->versionId,
            'workflow_artefact_key' => $artefactKey,
            'status_value_id' => $this->states->id('workflow_instance_state', InstanceState::Running->value),
            'subject_entity_key' => $subjectEntityKey,
            'subject_id' => $subjectId,
            'requester_user_id' => $requesterUserId,
            'current_node_key' => $firstNodeKey,
            'instance_key' => $instanceKey,
            'next_seq' => 1,
            'started_at' => $at->format('Y-m-d H:i:s'),
            'created_at' => $at->format('Y-m-d H:i:s'),
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $instanceId): ?array
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_instances')
            ->where('id', '=', $instanceId)
            ->first();
    }

    /**
     * The config_version row this instance is running on — the pinned one.
     *
     * No date parameter, on purpose. Every other effective-dated read in this
     * codebase demands an as-at date because "today" is not an answer
     * (Persistence\AsAtDateRequired); this one refuses a date for the mirror
     * reason. The instance already chose its version, and a caller that could
     * pass a date could accidentally ask "which version governs this instance
     * today" — which is a question with a wrong answer.
     *
     * @return array<string, mixed>
     */
    public function pinnedVersion(int $instanceId): array
    {
        $instance = $this->find($instanceId);

        if ($instance === null) {
            throw new \RuntimeException("Workflow instance {$instanceId} does not exist in this tenant.");
        }

        $version = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_versions')
            ->where('id', '=', (int) $instance['config_version_id'])
            ->first();

        if ($version === null) {
            // config_versions is restrictOnDelete from here, so this is not
            // reachable by deleting the version — it means the pin points at
            // another tenant's row, which is a tenancy failure, not a lookup miss.
            throw new \RuntimeException(
                "Instance {$instanceId} is pinned to config version {$instance['config_version_id']}, which is "
                . 'not visible in this tenant. An instance whose governing version cannot be read must not '
                . 'fall back to the current one.'
            );
        }

        return $version;
    }

    /**
     * The workflow payload the instance runs on: the header row, its nodes,
     * each node's participants, and the transitions — read through the PIN.
     *
     * @return array{header: array<string, mixed>, nodes: list<array<string, mixed>>,
     *               participants: list<array<string, mixed>>, transitions: list<array<string, mixed>>}
     */
    public function pinnedDefinition(int $instanceId): array
    {
        $version = $this->pinnedVersion($instanceId);
        $payloadId = (int) $version['payload_id'];

        if ((string) $version['payload_table'] !== 'workflow_payloads') {
            throw new \RuntimeException(
                "Instance {$instanceId} is pinned to a version whose payload is in "
                . "'{$version['payload_table']}', not workflow_payloads."
            );
        }

        $header = TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_payloads')
            ->where('id', '=', $payloadId)
            ->first();

        if ($header === null) {
            throw new \RuntimeException("The pinned workflow payload {$payloadId} is not readable in this tenant.");
        }

        $nodes = TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_nodes')
            ->where('workflow_payload_id', '=', $payloadId)
            ->orderBy('display_order')
            ->select();

        $participants = [];
        $transitions = TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_definition_transitions')
            ->where('workflow_payload_id', '=', $payloadId)
            ->orderBy('id')
            ->select();

        foreach ($nodes as $node) {
            foreach (
                TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_node_participants')
                    ->where('workflow_node_id', '=', (int) $node['id'])
                    ->orderBy('seq')
                    ->select() as $participant
            ) {
                $participants[] = $participant;
            }
        }

        return [
            'header' => $header,
            'nodes' => $nodes,
            'participants' => $participants,
            'transitions' => $transitions,
        ];
    }
}
