<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * AN ONBOARDING BOARD IS A CHECKLIST COPIED ONTO ONE EMPLOYMENT ON THE DAY IT STARTS.
 *
 * SmartPRS 2's OnboardingController: one `onboarding` row per joiner, its
 * `onboarding_items` ticked one by one, status derived from the ticks. The
 * lists are PHP constants there; here they are config/onboarding.json (a tenant's
 * own list under Customisation replaces it). The items are COPIED at start, so a
 * list edited in March does not rewrite a board that started in February.
 *
 * SmartPRS 2 also "heals" boards whose employee id died by matching NAMES. Not
 * ported: a board is linked by employee id or it is not linked, and an unlinked
 * board is shown as such rather than re-attached to whoever shares the name.
 */
final class Onboarding
{
    public const BOARDS = 'onboarding';
    public const ITEMS = 'onboarding_items';

    public function __construct(
        private readonly RowStore $store,
        private readonly string $configFile,
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return array<string, list<string>> checklist name => items */
    public function checklists(): array
    {
        $declared = json_decode((string) file_get_contents($this->configFile), true);

        if (!is_array($declared) || !is_array($declared['checklists'] ?? null)) {
            throw new \RuntimeException("config/onboarding.json is missing or unreadable at {$this->configFile}.");
        }

        $saved = $this->customisation?->live()[(string) ($declared['customisation_key'] ?? 'onboarding-checklist')]['checklists'] ?? null;

        return is_array($saved) && $saved !== [] ? $saved : (array) $declared['checklists'];
    }

    public function autoStartDays(): int
    {
        return (int) ((json_decode((string) file_get_contents($this->configFile), true) ?: [])['auto_start_days'] ?? 30);
    }

    /** @return list<array<string, mixed>> boards with their items and derived status, newest first */
    public function boards(): array
    {
        $items = [];

        foreach ($this->store->all(self::ITEMS) as $it) {
            $items[(string) ($it['onboarding_id'] ?? '')][] = $it;
        }

        $out = [];

        foreach ($this->store->all(self::BOARDS) as $b) {
            $mine = $items[(string) $b['id']] ?? [];
            usort($mine, static fn (array $a, array $c): int => (int) ($a['seq'] ?? 0) <=> (int) ($c['seq'] ?? 0));
            $done = count(array_filter($mine, static fn (array $i): bool => (int) ($i['done'] ?? 0) === 1));
            $b['items'] = $mine;
            $b['done'] = $done;
            $b['total'] = count($mine);
            $b['status'] = $b['total'] === 0 ? 'pending' : ($done >= $b['total'] ? 'completed' : ($done > 0 ? 'in_progress' : 'pending'));
            $out[] = $b;
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $out;
    }

    public function boardFor(string $employeeId): ?array
    {
        foreach ($this->boards() as $b) {
            if ((string) ($b['employee_id'] ?? '') === $employeeId) {
                return $b;
            }
        }

        return null;
    }

    /** Start a board for an employee with a named checklist. One board per employee. */
    public function start(array $employment, string $checklist, string $company = ''): array
    {
        $lists = $this->checklists();

        if (!isset($lists[$checklist])) {
            return ['ok' => false, 'why' => "'{$checklist}' is not a checklist. Declared: " . implode(', ', array_keys($lists)) . '.'];
        }

        if ($this->boardFor((string) $employment['id']) !== null) {
            return ['ok' => false, 'why' => Employment::label($employment) . ' already has an onboarding board.'];
        }

        $board = $this->store->put(self::BOARDS, [
            'employee_id' => (string) $employment['id'],
            'employee' => (string) $employment['name'],
            'employer_company_id' => (string) ($employment['employer_company_id'] ?? '') ?: null,
            'company_name' => $company ?: null,
            'stage' => $checklist,
            'joined_on' => Employment::joinedOn($employment) ?: null,
            'status' => 'pending',
        ]);

        foreach (array_values((array) $lists[$checklist]) as $i => $label) {
            $this->store->put(self::ITEMS, ['onboarding_id' => (string) $board['id'], 'label' => (string) $label, 'done' => 0, 'seq' => $i + 1]);
        }

        return ['ok' => true, 'board' => $board, 'why' => 'Onboarding started for ' . Employment::label($employment) . ' (' . count($lists[$checklist]) . ' items).'];
    }

    /** Tick or untick one item. */
    public function toggle(string $itemId, bool $done): array
    {
        $it = $this->store->find(self::ITEMS, $itemId);

        if ($it === null) {
            return ['ok' => false, 'why' => 'No such checklist item.'];
        }

        $this->store->put(self::ITEMS, ['id' => $itemId, 'done' => $done ? 1 : 0]);
        $board = $this->store->find(self::BOARDS, (string) $it['onboarding_id']);

        if ($board !== null) {
            $b = $this->boardFor((string) $board['employee_id']) ?? $board;
            $this->store->put(self::BOARDS, ['id' => $board['id'], 'status' => (string) ($b['status'] ?? 'pending')]);
        }

        return ['ok' => true, 'why' => ($done ? 'Done: ' : 'Reopened: ') . $it['label']];
    }

    /** Boards for everyone who joined within the window and has none yet. @return array{started: int, notes: list<string>} */
    public function startRecent(array $employments, string $today, string $checklist = 'New joiner'): array
    {
        $started = 0;
        $notes = [];
        $since = date('Y-m-d', strtotime($today . ' -' . $this->autoStartDays() . ' days'));

        foreach ($employments as $e) {
            $joined = Employment::joinedOn($e);

            if ($joined === '' || $joined < $since || $joined > $today || $this->boardFor((string) $e['id']) !== null) {
                continue;
            }

            $r = $this->start($e, $checklist);
            $r['ok'] ? $started++ : $notes[] = $r['why'];
        }

        return ['started' => $started, 'notes' => $notes];
    }
}
