<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHAT HAS LAPSED, WHAT IS ABOUT TO, AND WHAT NOBODY CAN TELL.
 *
 * This exists because of the third bucket. Every compliance screen in every HR
 * system reports two numbers — in date, and expired — and a row with no date
 * recorded falls into neither, so it is quietly counted as fine. A recovery
 * agency with two hundred agents and forty blank DRA expiry dates sees "160 in
 * date, 0 expired" and believes it. The forty are the ones the audit finds.
 *
 * So NOT DETERMINED is a reported state with its own count and its own list, and
 * it is never folded into compliant. "No expiry recorded" and "in date" are
 * opposite facts that look identical in a total.
 *
 * A FINDING SAYS WHAT IT STOPS. `blocks` on each check is in the declaration
 * because a screen that reports a date without saying what the date prevents is a
 * list somebody scrolls past. "Lapsed 12 days ago — blocks allocation of recovery
 * work" is an instruction; "DRA: 2026-08-26" is a fact nobody acts on.
 *
 * IT DOES NOT ENFORCE. Nothing here refuses an allocation or blocks a screen —
 * that belongs at the point of the act, not on a dashboard, and claiming
 * otherwise would be the worst kind of check: one whose existence persuades
 * people they are covered.
 *
 * ponytail: dates only. A check that depends on a document being on file rather
 * than on a date is the next entry shape in the same JSON.
 */
final class Compliance
{
    public const LAPSED = 'lapsed';
    public const DUE = 'due';
    public const OK = 'ok';
    public const UNKNOWN = 'not determined';

    private const PREFIX = 'compliance:';

    /**
     * What a customer may change about a check. `table`, `expiry` and
     * `applies_when` are deliberately NOT here.
     *
     * A check pointed at another column reports every row as "no date recorded" —
     * which reads as two hundred people who never submitted a date and is in fact
     * one wrong word. `run()` already refuses that at the point of reading; a
     * settings form that could write it in the first place is the same defect one
     * step earlier.
     */
    public const CHECK_KEYS = ['label', 'lead_days', 'blocks'];

    /** @var list<array<string, mixed>>|null */
    private ?array $checks = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->checks !== null) {
            return $this->checks;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['checks'] ?? null)) {
            throw new \RuntimeException(
                "The compliance registry at {$this->file} is missing or unreadable. Refused rather "
                . 'than treated as "no checks", which would render a clean compliance screen for an '
                . 'agency that is checking nothing — the most dangerous screen in the product.'
            );
        }

        // The customer's warning period and wording laid over the shipped check, at
        // the one point every read passes through — so `runAll()`, the screen and
        // the summary are all running the same check.
        return $this->checks = array_map($this->overlay(...), $decoded['checks']);
    }

    private function overlay(array $check): array
    {
        $override = $this->customisation?->live()[self::PREFIX . $check['key']] ?? [];

        return $override === []
            ? $check
            : array_merge($check, array_intersect_key($override, array_flip(self::CHECK_KEYS)))
                + ['customised' => true];
    }

    /** The check AS SHIPPED — what a change is compared against. */
    public function shipped(string $key): ?array
    {
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        foreach ((array) ($decoded['checks'] ?? []) as $check) {
            if ((string) $check['key'] === $key) {
                return $check;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function history(string $key): array
    {
        return $this->customisation?->history(self::PREFIX . $key) ?? [];
    }

    /**
     * Record a customer's own version of a check.
     *
     * @param array<string, mixed> $override
     * @return array{ok: bool, why: string, version?: int}
     */
    public function customise(string $key, array $override, ?array $shipped, string $byId,
        string $byLabel, string $note = ''): array
    {
        if ($shipped === null) {
            return ['ok' => false, 'why' => "There is no compliance check '{$key}' to customise."];
        }

        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        $problems = self::checkProblems($override, $shipped);

        if ($problems !== []) {
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        return $this->customisation->saveRaw(self::PREFIX . $key, $override, $byId, $byLabel, $note);
    }

    /**
     * What is wrong with a proposed check customisation.
     *
     * TWO REFUSALS EARN THIS. A lead time of zero is a warning period that never
     * warns: the credential is reported the day it lapses, which is the day it was
     * already too late to renew. And `blocks` emptied leaves a finding with no
     * stated consequence — "DRA: 2026-08-26" rather than "lapsed 12 days ago,
     * blocks allocation of recovery work" — which is a line nobody acts on. The
     * shipped checks are asserted to carry one in tests/compliance_test.php; a
     * settings form must not be the way round that.
     *
     * @return list<string>
     */
    public static function checkProblems(array $override, array $shipped): array
    {
        $problems = [];

        foreach (array_keys($override) as $key) {
            if (in_array((string) $key, self::CHECK_KEYS, true)) {
                continue;
            }

            $problems[] = sprintf(
                '"%s" cannot be customised on a compliance check. Which table it reads, which column '
                . 'holds the date and which rows the obligation is on are what the check IS — point '
                . 'it at another column and every row comes back as "no date recorded", which looks '
                . 'like two hundred people who never submitted a date and is in fact one wrong '
                . 'word.', (string) $key);
        }

        if (array_key_exists('lead_days', $override)) {
            $lead = $override['lead_days'];

            // Whole days, because `run()` reads this as an int: half a day of
            // warning would round to none at all, silently. This is a DAY COUNT
            // and not money, but it is checked in integers all the same — the
            // whole test is "is this exactly a positive whole number", which the
            // digits answer without a float being involved at all.
            if (preg_match('/^\d+$/', trim((string) $lead)) !== 1 || (int) $lead <= 0) {
                $problems[] = sprintf(
                    'The warning period on "%s" must be a positive whole number of days. Zero means '
                    . 'the '
                    . 'first warning arrives on the day the credential lapses, which is the day it '
                    . 'was already too late to renew; a negative one means nothing at all.',
                    (string) ($override['label'] ?? $shipped['label'] ?? ''));
            }
        }

        if (array_key_exists('blocks', $override) && trim((string) $override['blocks']) === '') {
            $problems[] = sprintf(
                'What "%s" blocks cannot be emptied. A finding that states no consequence is a date '
                . 'on a screen — "DRA: 2026-08-26" is a fact nobody acts on, and "lapsed 12 days '
                . 'ago, blocks allocation of recovery work" is an instruction.',
                (string) ($override['label'] ?? $shipped['label'] ?? ''));
        }

        if (array_key_exists('label', $override) && trim((string) $override['label']) === '') {
            $problems[] = 'A check with no name is a card on the compliance screen nobody can '
                . 'refer to.';
        }

        return $problems;
    }

    /**
     * Run one check.
     *
     * @return array{key: string, label: string, group: string, blocks: string, why: string,
     *     counts: array<string, int>, findings: list<array<string, mixed>>, error: ?string}
     */
    public function run(array $check, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $base = [
            'key' => (string) $check['key'],
            'label' => (string) $check['label'],
            'group' => (string) ($check['group'] ?? 'Other'),
            'blocks' => (string) ($check['blocks'] ?? ''),
            'why' => (string) ($check['why'] ?? ''),
            'lead_days' => (int) ($check['lead_days'] ?? 30),
            'customised' => (bool) ($check['customised'] ?? false),
        ];

        try {
            $rows = $this->store->all((string) $check['table']);
        } catch (\Throwable $e) {
            // A check that cannot run reports that it cannot run. Returning zero
            // findings would be a clean line on a compliance screen produced by a
            // check that never looked at anything.
            return $base + ['counts' => [], 'findings' => [], 'error' => $e->getMessage()];
        }

        // A CHECK POINTED AT A COLUMN THAT IS NOT THERE reports every row as
        // "not determined" for ever, which reads as a data problem — somebody
        // spends a week chasing two hundred agents for dates that are already
        // recorded, under a column name the declaration got wrong. Asked of the
        // store rather than of the first row, because an empty table has no row
        // to ask and that is exactly when nobody notices.
        try {
            $columns = $this->store->columnsOf((string) $check['table']);
        } catch (\Throwable) {
            $columns = [];
        }

        if ($columns === []) {
            return $base + ['counts' => [], 'findings' => [], 'error' => sprintf(
                'This check could not run: there is no table `%s` to read, so it has looked at '
                . 'nothing. Reported rather than returned as zero findings — a clean line produced '
                . 'by a check that never ran is the most dangerous thing on a compliance screen.',
                (string) $check['table'],
            )];
        }

        if (!in_array((string) $check['expiry'], $columns, true)) {
            return $base + ['counts' => [], 'findings' => [], 'error' => sprintf(
                'This check could not run: `%s` has no column `%s`. Every row would have come back '
                . 'as "no date recorded", which looks like two hundred people who never submitted a '
                . 'date and is in fact one wrong word in the declaration.',
                (string) $check['table'], (string) $check['expiry'],
            )];
        }

        foreach ((array) ($check['applies_when'] ?? []) as $column => $expected) {
            $wanted = array_map('strval', (array) $expected);
            $rows = array_values(array_filter($rows, static fn (array $r): bool
                => in_array((string) ($r[$column] ?? ''), $wanted, true)));
        }

        $expiryColumn = (string) $check['expiry'];
        $counts = [self::LAPSED => 0, self::DUE => 0, self::OK => 0, self::UNKNOWN => 0];
        $findings = [];

        foreach ($rows as $row) {
            $raw = trim((string) ($row[$expiryColumn] ?? ''));
            $state = self::state($raw, $today, $base['lead_days']);
            $counts[$state]++;

            if ($state === self::OK) {
                continue;
            }

            $findings[] = [
                'state' => $state,
                'expiry' => $raw,
                'days' => $state === self::UNKNOWN ? null : self::daysBetween($today, $raw),
                'subject' => $this->describe($check, $row),
                'row_id' => (string) ($row['id'] ?? ''),
            ];
        }

        // Worst first: what has already lapsed, longest-lapsed at the top, then
        // what is about to, then the ones nobody can answer for.
        usort($findings, static function (array $a, array $b): int {
            $rank = [self::LAPSED => 0, self::DUE => 1, self::UNKNOWN => 2];

            return [$rank[$a['state']], $a['days'] ?? PHP_INT_MAX]
                <=> [$rank[$b['state']], $b['days'] ?? PHP_INT_MAX];
        });

        return $base + ['counts' => $counts, 'findings' => $findings, 'error' => null];
    }

    /** @return list<array<string, mixed>> every check, run */
    public function runAll(?string $today = null): array
    {
        return array_map(fn (array $c): array => $this->run($c, $today), $this->all());
    }

    /**
     * The state of one date.
     *
     * A DATE THAT WILL NOT PARSE IS NOT DETERMINED, not expired and not fine.
     * '0000-00-00' is what MySQL used to store for "no date" and it is still in
     * ported data; read as a date it is the year zero, which would report every
     * such row as two thousand years lapsed and bury the real findings.
     */
    public static function state(string $expiry, string $today, int $leadDays): string
    {
        if ($expiry === '' || $expiry === '0000-00-00' || strtotime($expiry) === false) {
            return self::UNKNOWN;
        }

        $days = self::daysBetween($today, $expiry);

        return match (true) {
            $days < 0 => self::LAPSED,
            $days <= $leadDays => self::DUE,
            default => self::OK,
        };
    }

    /** Whole days from $from to $to, negative when $to is in the past. */
    public static function daysBetween(string $from, string $to): int
    {
        $a = strtotime($from . ' 00:00:00');
        $b = strtotime($to . ' 00:00:00');

        return $a === false || $b === false ? 0 : (int) round(($b - $a) / 86400);
    }

    /**
     * A finding names a PERSON, not a row id. "Row 4182 lapsed" is a line
     * somebody has to go and decode before they can act on it, which means they
     * do not act on it.
     */
    private function describe(array $check, array $row): string
    {
        $parts = [];

        foreach ((array) ($check['show'] ?? []) as $column) {
            if (trim((string) ($row[$column] ?? '')) !== '') {
                $parts[] = (string) $row[$column];
            }
        }

        $subjectId = (string) ($check['subject_id'] ?? '');

        if ($subjectId !== '' && (string) ($row[$subjectId] ?? '') !== '') {
            try {
                $person = $this->store->find('employees', (string) $row[$subjectId]);
            } catch (\Throwable) {
                $person = null;
            }

            if ($person !== null) {
                array_unshift($parts, trim((string) ($person['emp_code'] ?? '') . ' '
                    . (string) ($person['name'] ?? '')));
            }
        }

        return $parts === [] ? 'row ' . (string) ($row['id'] ?? '?') : implode(' · ', $parts);
    }

    /**
     * One sentence for the top of the screen.
     *
     * It leads with what is lapsed and, separately, with what is unanswerable —
     * a summary that says "3 lapsed" while forty rows have no date is a summary
     * that lies by omission.
     *
     * @param list<array<string, mixed>> $results
     */
    public static function summary(array $results): string
    {
        $totals = [self::LAPSED => 0, self::DUE => 0, self::UNKNOWN => 0];
        $broken = 0;

        foreach ($results as $result) {
            if ($result['error'] !== null) {
                $broken++;

                continue;
            }

            foreach (array_keys($totals) as $state) {
                $totals[$state] += (int) ($result['counts'][$state] ?? 0);
            }
        }

        $said = [];

        if ($totals[self::LAPSED] > 0) {
            $said[] = $totals[self::LAPSED] . ' lapsed';
        }

        if ($totals[self::DUE] > 0) {
            $said[] = $totals[self::DUE] . ' expiring soon';
        }

        if ($totals[self::UNKNOWN] > 0) {
            $said[] = $totals[self::UNKNOWN] . ' with no date recorded, which is not the same as '
                . 'in date';
        }

        if ($broken > 0) {
            $said[] = $broken . ' check(s) could not run at all';
        }

        return $said === []
            ? 'Nothing lapsed, nothing expiring, and every row has a date — this is a clean result '
                . 'because every check ran, not because none did.'
            : ucfirst(implode('; ', $said)) . '.';
    }
}
