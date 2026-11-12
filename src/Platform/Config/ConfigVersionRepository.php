<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Config;

use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * LOADING THE CANDIDATES `resolve(type, key, scope, as_of)` CHOOSES BETWEEN.
 *
 * Spine S4 / V.8.1: the resolver is a pure function over candidates, and this is
 * the only thing that issues the query. Splitting them that way is what lets the
 * precedence rules be tested exhaustively without a database, and lets this file
 * be about one question — which rows are even eligible.
 *
 * Eligibility is three predicates, and each one is a defect in SmartPRS 2 if it
 * is missing:
 *
 *   TENANT      — carried by TenantScopedQuery, not written here. There is no
 *                 method on that class that removes it.
 *   PUBLISHED   — only `published` versions are candidates. A draft that
 *                 resolves is a configuration change nobody approved.
 *   AS AT       — effective_from <= as_of AND (effective_to IS NULL OR >= as_of).
 *                 A recalculation of March, run in November, must resolve
 *                 March's configuration. SmartPRS 2 reads the current row.
 *
 * What this deliberately does NOT do is pick a winner. Two versions the resolver
 * cannot order is a configuration error that BLOCKS at publish (R-VIII-010,
 * R-IX-018), and a repository that quietly took the first row would hide exactly
 * the ambiguity those rules exist to surface.
 */
final class ConfigVersionRepository
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
        private readonly StatusLookup $statuses,
    ) {
    }

    /**
     * Every published version of one artefact that is in force on a date.
     *
     * @return list<ConfigVersionCandidate>
     */
    public function candidates(string $artefactType, string $artefactKey, \DateTimeImmutable $asOf): array
    {
        $artefact = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_artefacts')
            ->where('artefact_type', '=', $artefactType)
            ->where('artefact_key', '=', $artefactKey)
            ->where('is_active', '=', 1)
            ->first(['id', 'artefact_type', 'artefact_key']);

        if ($artefact === null) {
            return [];
        }

        $date = $asOf->format('Y-m-d');

        $rows = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_versions')
            ->where('config_artefact_id', '=', (int) $artefact['id'])
            // PUBLISHED **OR SUPERSEDED**, and this is the subtle one.
            //
            // §4.1 says "only published is evaluated", and read as a status
            // filter alone that makes historical recalculation impossible: the
            // moment a new version supersedes April's, April's stops resolving,
            // and a re-run of April produces nothing. That is precisely the
            // defect effective dating exists to prevent.
            //
            // A superseded version WAS published and DID govern its window. What
            // supersession changes is its effective range, not whether it was
            // real. So eligibility is status ∈ {published, superseded} AND in
            // force on the date — and the date does the work, which is the whole
            // point. draft, testing, pending_approval and archived stay out.
            ->whereIn('status_value_id', [
                $this->statuses->id('config_version_status', 'published'),
                $this->statuses->id('config_version_status', 'superseded'),
            ])
            ->whereInForceOn('effective_from', 'effective_to', $date)
            // Deterministic order in, deterministic order out. The resolver
            // breaks ties by specificity and priority, never by arrival, but a
            // stable input means two runs produce byte-identical traces.
            // The stored specificity column orders the read; the AUTHORITY is
            // Scope::specificity(), recomputed from the predicates below. One
            // source of truth for the weights, and a denormalised copy for the
            // index — never two answers.
            ->orderBy('specificity', 'DESC')
            ->orderBy('priority', 'DESC')
            ->orderBy('version_no', 'DESC')
            ->select();

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = new ConfigVersionCandidate(
                (string) $artefact['artefact_type'],
                (string) $artefact['artefact_key'],
                (string) $row['id'],
                (int) $row['version_no'],
                $this->scopeOf((int) $row['id'], (string) $row['scope_json']),
                (int) $row['priority'],
                EffectiveRange::fromInclusive(
                    (string) $row['effective_from'],
                    $row['effective_to'] === null ? null : (string) $row['effective_to'],
                ),
                ['payload_table' => $row['payload_table'], 'payload_id' => $row['payload_id']],
            );
        }

        return $candidates;
    }

    /**
     * The version that governs a subject on a date.
     *
     * Throws rather than returning null, and does so for BOTH ways of finding
     * nothing — no candidates at all, and candidates none of which match the
     * subject. Those are the same answer to the caller ("nothing governs this"),
     * and giving them two shapes means every call site handles one and forgets
     * the other. Spine S4: an unresolved configuration is a named condition
     * somebody acts on, never a null that propagates into a calculation.
     *
     * @param array<string, string|int|null> $subjectDimensions
     * @throws \SmartPRS\Platform\Support\UnresolvedConfiguration
     * @throws \SmartPRS\Platform\Support\AmbiguousConfiguration
     */
    public function resolve(
        string $artefactType,
        string $artefactKey,
        array $subjectDimensions,
        \DateTimeImmutable $asOf,
    ): ConfigVersionCandidate {
        return (new ArtefactResolver($this->candidates($artefactType, $artefactKey, $asOf)))
            ->resolve($artefactType, $artefactKey, $subjectDimensions, $asOf);
    }

    /**
     * The payload row a resolved version points at.
     *
     * §4.1 keeps payload_table and payload_id on the version because the target
     * is chosen by artefact_type and no single-target foreign key can express
     * that. The table name is therefore data, and is checked against the
     * registered payload tables rather than being interpolated — a payload
     * pointer is written by the publisher, but a table name from a row is still
     * a string from a row.
     *
     * @return array<string, mixed>|null
     */
    public function payload(ConfigVersionCandidate $candidate): ?array
    {
        $pointer = is_array($candidate->payload) ? $candidate->payload : [];
        $table = (string) ($pointer['payload_table'] ?? '');
        $id = (int) ($pointer['payload_id'] ?? 0);

        if (!in_array($table, self::PAYLOAD_TABLES, true)) {
            throw new \RuntimeException(
                "'{$table}' is not a registered payload table. A version points at one of: "
                . implode(', ', self::PAYLOAD_TABLES) . '.'
            );
        }

        return TenantScopedQuery::on($this->pdo, $this->tenant, $table)
            ->where('id', '=', $id)
            ->first();
    }

    /**
     * The payload tables a config_version may point at. A new configurable
     * concept adds a row here and a migration — which is R-V-050's "an
     * artefact_type and a typed payload table, never a second versioning
     * mechanism", made mechanical.
     */
    public const PAYLOAD_TABLES = [
        'rule_payloads',
        'formula_payloads',
        'workflow_payloads',
    ];

    /**
     * The scope predicates of a version.
     *
     * `config_version_scopes` is the indexed, queryable form; `scope_json` on
     * the version is the same thing as the publisher wrote it. The rows are
     * authoritative, because they are what an index can be built on — the JSON
     * is kept for diffing and for reading, and disagreement between them is a
     * publisher bug this surfaces rather than papers over.
     */
    private function scopeOf(int $versionId, string $scopeJson): Scope
    {
        $rows = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_version_scopes')
            ->where('config_version_id', '=', $versionId)
            ->orderBy('dimension')
            ->select(['dimension', 'value']);

        $predicates = [];

        foreach ($rows as $row) {
            $predicates[(string) $row['dimension']][] = (string) $row['value'];
        }

        $fromRows = $predicates === [] ? Scope::tenantDefault() : Scope::of($predicates);

        $decoded = json_decode($scopeJson, true);

        if (is_array($decoded) && $this->normalise($decoded) !== $this->normalise($predicates)) {
            throw new \RuntimeException(
                "config_versions.{$versionId}: scope_json and config_version_scopes disagree. "
                . 'The rows are what the index resolves on and the JSON is what the publisher wrote; '
                . 'a version whose two records of its own scope differ governs a set nobody declared.'
            );
        }

        return $fromRows;
    }

    /** @param array<string, mixed> $predicates */
    private function normalise(array $predicates): string
    {
        $out = [];

        foreach ($predicates as $dimension => $values) {
            $values = array_map(strval(...), (array) $values);
            sort($values);
            $out[(string) $dimension] = $values;
        }

        ksort($out);

        return json_encode($out, JSON_THROW_ON_ERROR);
    }
}
