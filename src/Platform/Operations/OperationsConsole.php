<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Operations;

/**
 * ONE CONSOLE, AND EVERY ITEM IS ACTIONABLE — Part XXXI §XXXI.7, R-XXXI-090.
 *
 * *"Every operations-console item MUST be actionable: it MUST offer at least one
 * action, a runbook link, or both. A read-only number with no next step MUST NOT
 * be shown."*
 *
 * That rule is enforced in `panel()`, not in a review checklist, because the
 * pressure on an operations console is always toward adding one more number, and
 * the number that gets added under pressure is the one with nowhere to go.
 *
 * The panel this build cares about most is DATA PROTECTION, and specifically
 * *"last successful restore drill and its date"*. A backup nobody has restored is
 * a belief. R-XXXI-093's own acceptance test is an on-premise operator answering
 * "when was my last good backup, and when was it last proven restorable?" from
 * one screen — two questions, and only the second one is evidence.
 *
 * Every value renders with its FRESHNESS (R-XXXI-091), and a stale value renders
 * as stale rather than as a number.
 */
final class OperationsConsole
{
    /** The twelve panels of §XXXI.7. The set is the specification's, not this class's. */
    public const PANELS = [
        'scheduler', 'queues', 'failed_jobs', 'notifications', 'webhooks', 'integrations',
        'data_protection', 'licence_and_packs', 'migrations_and_version', 'capacity',
        'health_and_conditions', 'audit_and_compliance',
    ];

    /** @var array<string, list<array{label: string, value: string, action: ?string, runbook: ?string, as_of: ?string}>> */
    private array $panels = [];

    /**
     * @throws \InvalidArgumentException on an item with no next step
     */
    public function item(
        string $panel,
        string $label,
        string $value,
        ?string $action = null,
        ?string $runbook = null,
        ?string $asOf = null,
    ): void {
        if (!in_array($panel, self::PANELS, true)) {
            throw new \InvalidArgumentException(
                "'{$panel}' is not one of the twelve operations panels. The set comes from Part XXXI "
                . '§XXXI.7; an invented panel is a screen the specification does not describe and nobody '
                . 'reviews.'
            );
        }

        if (($action === null || trim($action) === '') && ($runbook === null || trim($runbook) === '')) {
            throw new \InvalidArgumentException(
                "Console item '{$panel}/{$label}' offers neither an action nor a runbook. R-XXXI-090: a "
                . 'read-only number with no next step must not be shown. The pressure on a console is '
                . 'always toward one more number, and the one added under pressure is the one with nowhere '
                . 'to go.'
            );
        }

        if ($asOf === null || trim($asOf) === '') {
            throw new \InvalidArgumentException(
                "Console item '{$panel}/{$label}' has no freshness. R-XXXI-091: every value renders with "
                . 'when it was observed, and a stale value renders as stale — a figure that looks live and '
                . 'is not is the one nobody questions.'
            );
        }

        $this->panels[$panel][] = [
            'label' => $label,
            'value' => $value,
            'action' => $action,
            'runbook' => $runbook,
            'as_of' => $asOf,
        ];
    }

    /** @return list<string> panels §XXXI.7 requires that this console does not show */
    public function missingPanels(): array
    {
        return array_values(array_diff(self::PANELS, array_keys($this->panels)));
    }

    /** @return list<string> items that slipped through with no next step */
    public function unactionableItems(): array
    {
        $problems = [];

        foreach ($this->panels as $panel => $items) {
            foreach ($items as $item) {
                if (($item['action'] ?? null) === null && ($item['runbook'] ?? null) === null) {
                    $problems[] = "{$panel}/{$item['label']}";
                }
            }
        }

        return $problems;
    }

    public function itemCount(): int
    {
        return array_sum(array_map('count', $this->panels));
    }

    /** @return list<array{label: string, value: string, action: ?string, runbook: ?string, as_of: ?string}> */
    public function panel(string $panel): array
    {
        return $this->panels[$panel] ?? [];
    }
}
