<?php

declare(strict_types=1);

namespace SmartPRS\Platform\ModulePack;

use SmartPRS\Platform\Compliance\ItemType;

/**
 * INSTALLING A VERTICAL INTO A CLEAN TENANT — Part XXXII Phase 7.
 *
 * The exit criterion says "installs into a CLEAN tenant", and clean is the hard
 * part: a pack that only works on a tenant somebody prepared by hand is a pack
 * whose real installation instructions live in an engineer's head. So the
 * installer takes nothing but the pack and an empty tenant, and everything the
 * pack needs it must declare.
 *
 * ALL OR NOTHING. Every section is built before anything is committed, so a pack
 * with one malformed item type installs none of it. A half-installed vertical
 * leaves a tenant holding obligations whose workflows never arrived, and the
 * register then shows people failing checks nobody can discharge.
 *
 * RE-INSTALLING IS REFUSED, NOT SILENTLY REDONE. The second install of a pack
 * version is a question — an upgrade, a repair, a mistake — and answering it by
 * quietly overwriting is how a tenant's own edits to a seeded type disappear.
 */
final class ModulePackInstaller
{
    /** @var array<string, string> installed pack key => version */
    private array $installed = [];

    /**
     * @return array{item_types: array<string, ItemType>, sections: array<string, int>, log: list<string>}
     * @throws ModulePackRejected
     */
    public function install(ModulePack $pack, array $tenantState = []): array
    {
        $problems = [];

        if ($tenantState !== []) {
            $problems[] = 'the target tenant is not clean: it already holds '
                . implode(', ', array_keys($tenantState)) . '. The reference pack is proven against an EMPTY '
                . 'tenant on purpose — a pack that only installs onto a tenant somebody prepared by hand is '
                . 'a pack whose real instructions live in an engineer\'s head';
        }

        if (isset($this->installed[$pack->key])) {
            $problems[] = "pack {$pack->key} is already installed at version {$this->installed[$pack->key]}. "
                . 'A second install is a question — upgrade, repair or mistake — and answering it by '
                . 'overwriting is how a tenant\'s own edits to a seeded type disappear';
        }

        $itemTypes = [];
        $log = [];

        // Build EVERYTHING first. Nothing is committed until every section is
        // known to be constructible.
        foreach ($pack->section('compliance_item_types') as $declared) {
            try {
                $type = new ItemType(
                    code: (string) $declared['code'],
                    name: (string) $declared['name'],
                    validStateCodes: array_values((array) $declared['valid_state_codes']),
                    appliesExpression: (string) $declared['applies_expression'],
                    appliesReasonTemplate: (string) $declared['applies_reason_template'],
                    subjectKinds: array_values((array) ($declared['subject_kinds'] ?? ['person'])),
                    evidenceClass: (string) ($declared['evidence_class'] ?? 'operational'),
                    onNotAssessed: (string) ($declared['on_not_assessed'] ?? ItemType::BLOCK),
                    scoreWeight: (int) ($declared['score_weight'] ?? 1),
                    partialCredit: (array) ($declared['partial_credit'] ?? []),
                    origin: 'module_pack',
                    versionId: $pack->key . '@' . $pack->version,
                );
            } catch (\InvalidArgumentException $e) {
                $problems[] = "compliance_item_types.{$declared['code']}: " . $e->getMessage();

                continue;
            }

            $itemTypes[$type->code] = $type;
        }

        // A gate binding naming an item type the pack does not ship is a gate
        // that silently never fires — the failure mode this whole part exists to
        // remove, arriving through the back door of a typo.
        foreach ($pack->section('gate_bindings') as $binding) {
            foreach ((array) $binding['requires'] as $code) {
                if (!isset($itemTypes[$code])) {
                    $problems[] = "gate_bindings.{$binding['action_type']} requires item type '{$code}', "
                        . 'which this pack does not ship. A gate bound to a type that does not exist never '
                        . 'fires, and nothing on any screen says so';
                }
            }
        }

        if ($problems !== []) {
            throw new ModulePackRejected($problems, $pack->key);
        }

        $sections = [];

        foreach ($pack->sections() as $section) {
            $count = count($pack->section($section));
            $sections[$section] = $count;
            $log[] = "installed {$count} {$section}";
        }

        $this->installed[$pack->key] = $pack->version;

        return ['item_types' => $itemTypes, 'sections' => $sections, 'log' => $log];
    }

    /** @return array<string, string> */
    public function installedPacks(): array
    {
        return $this->installed;
    }
}
