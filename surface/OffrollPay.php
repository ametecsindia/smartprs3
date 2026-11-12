<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * OFF-ROLL AGENTS — PAY STRUCTURE, DAILY ACTIVITY, PAYOUT RUNS, TDS AND PAYMENTS (owner, 23 Oct 2026).
 *
 * Vendor / contract field agents are NOT on payroll. What they are paid is worked out here, month by
 * month, from three things that are each recorded once:
 *
 *   1. THE PAY STRUCTURE (offroll_pay_plans) — effective-dated per agent: fixed per month, per day
 *      present, per visit, per promise-to-pay, per account paid, % of the amount collected (flat or in
 *      slabs), and an optional monthly cap. A revision is a NEW row from a date; nothing is overwritten.
 *      Variable pay uses the plan in force on each day worked; the fixed amount uses the plan in force
 *      at month end. An agent with no plan row falls back to the register's old payout type and rate.
 *   2. THE DAILY ACTIVITY (offroll_activity) — one row per agent per day: present / half day / absent /
 *      leave, check-in / out, accounts allotted, visits, PTPs, accounts paid, amount collected. Entered
 *      on screen, uploaded from the sample sheet, or sent by the mobile app (with each visit kept in
 *      offroll_visits). Each row is approved by a second person (Administrator excepted, D-OWN-01).
 *   3. THE LEDGER (agent_earnings) — manual adjustments (+), deductions before TDS (−) and recoveries
 *      after TDS (−), each approved.
 *
 * A PAYOUT RUN is per company per month. Generating it prices every agent's month into one line with
 * its full derivation (`explain`), links the activity and ledger rows it counted (payout_id) so nothing
 * is counted twice, deducts TDS by the Government rule in config/offroll-agents.json `tds`, and holds
 * or flags a line whose agent worked with an expired DRA or PCC (Organization Configuration's choice).
 * A draft may be regenerated; an approved run is fixed. Approved lines are then PENDING PAYMENT until
 * marked paid (date, mode, UTR) — a vendor-paid agent's lines are paid together as the vendor's.
 *
 * The rules a customer may change (fixed-pay pro-rating, what activity counts, expired-certificate
 * handling, TDS on/off and section) are Organization Configuration → Off-roll Agent Payouts, versioned
 * in Customisation under `customisation_key`. Nothing here knows a rate.
 */
final class OffrollPay
{
    public const AGENTS = 'offroll_agents';
    public const VENDORS = 'offroll_vendors';
    public const PLANS = 'offroll_pay_plans';
    public const ACTIVITY = 'offroll_activity';
    public const VISITS = 'offroll_visits';
    public const PAYOUTS = 'offroll_payouts';
    public const LINES = 'offroll_payout_lines';
    public const EARNINGS = 'agent_earnings';

    /** @var array<string, mixed>|null */
    private ?array $declared = null;

    /** @var array<string, array<string, mixed>>|null activity rows by id, read once per request and kept current */
    private ?array $act = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
        private readonly string $configFile,
    ) {
    }

    // ------------------------------------------------------------------ configuration

    /** @return array<string, mixed> */
    public function declared(): array
    {
        if ($this->declared === null) {
            $d = json_decode((string) @file_get_contents($this->configFile), true);

            if (!is_array($d) || !is_array($d['tds'] ?? null) || !is_array($d['settings_defaults'] ?? null)) {
                throw new \RuntimeException("config/offroll-agents.json is missing or unreadable at {$this->configFile}.");
            }

            $this->declared = $d;
        }

        return $this->declared;
    }

    public function key(): string
    {
        return (string) ($this->declared()['customisation_key'] ?? 'offroll-payouts');
    }

    /** The settings in force: the saved version over the shipped defaults. @return array<string, string> */
    public function settings(): array
    {
        $saved = (array) ($this->customisation->live()[$this->key()] ?? []);

        return array_map('strval', array_merge((array) $this->declared()['settings_defaults'], array_intersect_key($saved, (array) $this->declared()['settings_defaults'])));
    }

    /** @return array{ok: bool, why: string, clean?: array<string, string>} */
    public function checkSettings(array $input): array
    {
        $opt = (array) $this->declared()['settings_options'];
        $clean = [];

        foreach (['fixed_basis', 'count_basis', 'expired_certificate', 'tds_enabled'] as $k) {
            $v = trim((string) ($input[$k] ?? ''));

            if (!isset($opt[$k][$v])) {
                return ['ok' => false, 'why' => 'Pick a value for every setting (' . str_replace('_', ' ', $k) . ').'];
            }

            $clean[$k] = $v;
        }

        $days = trim((string) ($input['fixed_days'] ?? ''));

        if (preg_match('/^\d{1,2}$/', $days) !== 1 || (int) $days < 1 || (int) $days > 31) {
            return ['ok' => false, 'why' => 'The fixed number of days must be a whole number from 1 to 31.'];
        }

        $clean['fixed_days'] = (string) (int) $days;
        $section = trim((string) ($input['tds_section'] ?? ''));

        if (!isset($this->declared()['tds']['sections'][$section])) {
            return ['ok' => false, 'why' => 'Pick the TDS section that applies to off-roll payouts.'];
        }

        $clean['tds_section'] = $section;

        return ['ok' => true, 'why' => '', 'clean' => $clean];
    }

    // ------------------------------------------------------------------ agents & vendors

    /** Agents on the register (retired ones left out), by name. @return list<array<string, mixed>> */
    public function agents(): array
    {
        $rows = array_values(array_filter(self::rows($this->store, self::AGENTS), static fn (array $a): bool => !Modules::isRetired($a)));
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $rows;
    }

    public function agent(string $id): ?array
    {
        $a = $id === '' ? null : $this->store->find(self::AGENTS, $id);

        return $a === null || Modules::isRetired($a) ? null : $a;
    }

    /** @return array<string, array<string, mixed>> id => vendor */
    public function vendors(): array
    {
        $out = [];

        foreach (self::rows($this->store, self::VENDORS) as $v) {
            Modules::isRetired($v) || $out[(string) $v['id']] = $v;
        }

        return $out;
    }

    /** The agent's display code: its own code, else the prefix and the row id. */
    public function code(array $agent): string
    {
        $c = trim((string) ($agent['agent_code'] ?? ''));

        return $c !== '' ? $c : (string) ($this->declared()['code_prefix'] ?? 'OFR-') . str_pad((string) ($agent['id'] ?? ''), 4, '0', STR_PAD_LEFT);
    }

    public function label(array $agent): string
    {
        return $this->code($agent) . ' · ' . trim((string) ($agent['name'] ?? ''));
    }

    /** The next free code in the declared series. */
    public function nextCode(): string
    {
        $prefix = (string) ($this->declared()['code_prefix'] ?? 'OFR-');
        $max = 0;

        foreach (self::rows($this->store, self::AGENTS) as $a) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', trim((string) ($a['agent_code'] ?? '')), $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Who the money goes to: 'vendor' only when the agent says so AND names a vendor. */
    public static function paidTo(array $agent): string
    {
        return strtolower(trim((string) ($agent['paid_to'] ?? ''))) === 'vendor' && trim((string) ($agent['vendor_id'] ?? '')) !== '' ? 'vendor' : 'agent';
    }

    /**
     * The certificate's state on a day: valid / due (within the window) / expired / none.
     * A certificate that expires on the day is expired that day (the bank asks on the day of the visit).
     */
    public static function certBand(?string $expiry, string $onDate, int $windowDays): string
    {
        return FieldReports::band($expiry, $onDate, $windowDays);
    }

    // ------------------------------------------------------------------ pay structure

    /** Every plan row of an agent, newest effective date first. @return list<array<string, mixed>> */
    public function plans(string $agentId): array
    {
        $rows = array_values(array_filter(self::rows($this->store, self::PLANS), static fn (array $p): bool
            => (string) ($p['agent_id'] ?? '') === $agentId && !Modules::isRetired($p)));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['effective_from'] ?? ''), (string) ($a['effective_from'] ?? '')) ?: (int) $b['id'] <=> (int) $a['id']);

        return $rows;
    }

    /** @return array<string, list<array<string, mixed>>> agent id => plans newest first */
    public function plansByAgent(): array
    {
        $out = [];

        foreach (self::rows($this->store, self::PLANS) as $p) {
            Modules::isRetired($p) || $out[(string) ($p['agent_id'] ?? '')][] = $p;
        }

        foreach ($out as &$list) {
            usort($list, static fn (array $a, array $b): int => strcmp((string) ($b['effective_from'] ?? ''), (string) ($a['effective_from'] ?? '')) ?: (int) $b['id'] <=> (int) $a['id']);
        }

        unset($list);

        return $out;
    }

    /**
     * THE PLAN IN FORCE ON A DATE — the latest row effective on or before it; with no row, the
     * register's old payout type and rate as a one-component plan (so existing agents keep paying).
     *
     * @param list<array<string, mixed>> $plans newest first
     * @return array<string, mixed>|null  null = nothing to pay on
     */
    public static function planOn(array $plans, array $agent, string $date): ?array
    {
        foreach ($plans as $p) {
            if ((string) ($p['effective_from'] ?? '') !== '' && (string) $p['effective_from'] <= $date) {
                return $p;
            }
        }

        if ($plans !== []) {
            return null; // the first plan starts after this day
        }

        $rate = trim((string) ($agent['rate'] ?? ''));

        if ($rate === '' || Amount::of($rate)->isZero()) {
            return null;
        }

        return match (strtolower(trim((string) ($agent['payout_type'] ?? '')))) {
            'per_visit' => ['id' => '', 'effective_from' => '', 'per_visit_rate' => $rate, 'note' => 'from the register (payout type Per visit)'],
            'fixed' => ['id' => '', 'effective_from' => '', 'fixed_monthly' => $rate, 'note' => 'from the register (payout type Fixed)'],
            'percent' => ['id' => '', 'effective_from' => '', 'collection_percent' => $rate, 'note' => 'from the register (payout type Percent)'],
            default => null,
        };
    }

    /**
     * COLLECTION SLABS: [{"upto": "100000", "percent": "2"}, {"upto": "", "percent": "3"}] — tiered:
     * each band's percent applies to the part of the month's collection inside it; blank upto = no top.
     *
     * @return list<array{upto: string, percent: string}>|null null = not a valid slab list
     */
    public static function parseSlabs(string $json): ?array
    {
        $json = trim($json);

        if ($json === '') {
            return [];
        }

        $d = json_decode($json, true);

        if (!is_array($d) || !array_is_list($d)) {
            return null;
        }

        $out = [];
        $last = Money::zero();

        foreach ($d as $i => $s) {
            $upto = trim((string) ($s['upto'] ?? ''));
            $pct = trim((string) ($s['percent'] ?? ''));

            if (preg_match('/^\d+(\.\d{1,4})?$/', $pct) !== 1 || ($upto !== '' && preg_match('/^\d+(\.\d{1,4})?$/', $upto) !== 1)) {
                return null;
            }

            if ($upto === '' && $i !== count($d) - 1) {
                return null; // only the last band may be open-ended
            }

            if ($upto !== '' && Amount::of($upto)->compareTo($last) <= 0) {
                return null; // bands must rise
            }

            $upto === '' || $last = Amount::of($upto);
            $out[] = ['upto' => $upto, 'percent' => $pct];
        }

        return $out;
    }

    /** Tiered collection pay and its explanation. @return array{0: Money, 1: list<string>} */
    public static function slabPay(Money $collected, array $slabs): array
    {
        $pay = Money::zero();
        $why = [];
        $floor = Money::zero();

        foreach ($slabs as $s) {
            if ($collected->compareTo($floor) <= 0) {
                break;
            }

            $top = $s['upto'] === '' ? $collected : Amount::of($s['upto']);
            $top->compareTo($collected) > 0 && $top = $collected;
            $part = $top->minus($floor);

            if ($part->isNegative() || $part->isZero()) {
                $floor = $top;
                continue;
            }

            $amt = $part->multipliedBy(Rational::fromDecimalString($s['percent'])->dividedBy(Rational::of(100)), RoundingMode::HalfUp);
            $pay = $pay->plus($amt);
            $why[] = $s['percent'] . '% of ' . $part->format() . ' (' . $floor->format() . ' – ' . $top->format() . ') = ' . $amt->format();
            $floor = $top;
        }

        return [$pay, $why];
    }

    /** @return array{ok: bool, why: string, row?: array<string, mixed>} */
    public function savePlan(array $agent, array $input, string $by): array
    {
        $from = Dates::toIso((string) ($input['effective_from'] ?? '')) ?? '';

        if ($from === '') {
            return ['ok' => false, 'why' => 'Give the date this pay structure starts from.'];
        }

        $row = ['agent_id' => (string) $agent['id'], 'effective_from' => $from, 'created_by' => $by];
        $any = false;

        foreach (array_keys((array) $this->declared()['plan_components']) as $k) {
            $v = trim(str_replace([',', '₹', ' '], '', (string) ($input[$k] ?? '')));

            if ($v === '') {
                continue;
            }

            if (preg_match('/^\d+(\.\d{1,4})?$/', $v) !== 1) {
                return ['ok' => false, 'why' => "'{$v}' is not an amount (" . $this->declared()['plan_components'][$k]['label'] . ').'];
            }

            $row[$k] = Amount::of($v)->toDecimalString();
            Amount::of($v)->isZero() || $any = true;
        }

        $slabs = self::parseSlabs((string) ($input['collection_slabs'] ?? ''));

        if ($slabs === null) {
            return ['ok' => false, 'why' => 'Collection slabs: each band needs a rising "up to" amount and a percent; only the last band may be open (no top).'];
        }

        if ($slabs !== []) {
            $row['collection_slabs'] = json_encode($slabs);
            $any = true;
        }

        if (!$any) {
            return ['ok' => false, 'why' => 'The pay structure pays nothing: fill at least one rate.'];
        }

        $cap = trim(str_replace([',', '₹', ' '], '', (string) ($input['monthly_cap'] ?? '')));

        if ($cap !== '') {
            if (preg_match('/^\d+(\.\d{1,4})?$/', $cap) !== 1) {
                return ['ok' => false, 'why' => "'{$cap}' is not an amount (monthly cap)."];
            }

            $row['monthly_cap'] = Amount::of($cap)->toDecimalString();
        }

        $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 300);
        $note === '' || $row['note'] = $note;

        // THE SAME START DATE TWICE IS A CORRECTION: the older row is retired, never edited.
        foreach ($this->plans((string) $agent['id']) as $p) {
            if ((string) $p['effective_from'] === $from) {
                $this->store->put(self::PLANS, ['id' => $p['id'], 'deleted_at' => date('Y-m-d H:i:s')]);
            }
        }

        $saved = $this->store->put(self::PLANS, $row);

        return ['ok' => true, 'row' => $saved, 'why' => 'Pay structure for ' . $this->label($agent) . ' saved, in force from ' . Dates::dmy($from) . '.'];
    }

    /** A plan in plain words. */
    public function planSummary(?array $plan): string
    {
        if ($plan === null) {
            return 'No pay structure';
        }

        $parts = [];

        foreach ((array) $this->declared()['plan_components'] as $k => $c) {
            $v = trim((string) ($plan[$k] ?? ''));

            if ($v === '' || Amount::of($v)->isZero()) {
                continue;
            }

            $parts[] = $k === 'collection_percent'
                ? rtrim(rtrim(Amount::of($v)->toDecimalString(), '0'), '.') . '% of collection'
                : Amount::of($v)->format() . ' ' . match ((string) $c['basis']) {
                    'fixed' => 'fixed / month', 'days' => 'per day', 'visits' => 'per visit', 'ptp' => 'per PTP', 'paid_accounts' => 'per account paid', default => '',
                };
        }

        $slabs = self::parseSlabs((string) ($plan['collection_slabs'] ?? '')) ?? [];

        if ($slabs !== []) {
            $parts[] = 'collection slabs ' . implode(' / ', array_map(static fn (array $s): string => $s['percent'] . '%' . ($s['upto'] === '' ? '+' : ' to ' . Amount::of($s['upto'])->format()), $slabs));
        }

        trim((string) ($plan['monthly_cap'] ?? '')) === '' || $parts[] = 'cap ' . Amount::of((string) $plan['monthly_cap'])->format();

        return $parts === [] ? 'No pay structure' : implode(' · ', $parts);
    }

    // ------------------------------------------------------------------ daily activity

    /** @return list<array<string, mixed>> live activity rows, optionally of one month */
    public function activity(string $month = ''): array
    {
        if ($this->act === null) {
            $this->act = [];

            foreach (self::rows($this->store, self::ACTIVITY) as $r) {
                $this->act[(string) $r['id']] = $r;
            }
        }

        return array_values(array_filter($this->act, static fn (array $r): bool
            => !Modules::isRetired($r) && ($month === '' || str_starts_with((string) ($r['activity_date'] ?? ''), $month))));
    }

    public function day(string $agentId, string $date): ?array
    {
        foreach ($this->activity(substr($date, 0, 7)) as $r) {
            if ((string) $r['agent_id'] === $agentId && (string) $r['activity_date'] === $date) {
                return $r;
            }
        }

        return null;
    }

    /** Is this row inside an APPROVED payout (and so fixed)? */
    public function locked(array $row): bool
    {
        $pid = trim((string) ($row['payout_id'] ?? ''));

        if ($pid === '') {
            return false;
        }

        $run = $this->store->find(self::PAYOUTS, $pid);

        return $run !== null && !Modules::isRetired($run) && (string) ($run['status'] ?? '') === 'approved';
    }

    /**
     * Record (or correct) one agent-day. A correction of a not-yet-paid day goes back to pending;
     * a day inside an approved payout cannot change.
     *
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    public function saveDay(array $agent, array $input, string $by, string $source): array
    {
        $d = $this->declared();
        $date = Dates::toIso((string) ($input['activity_date'] ?? '')) ?? '';

        if ($date === '') {
            return ['ok' => false, 'why' => 'Give the date (dd-mm-yyyy).'];
        }

        if ($date > date('Y-m-d')) {
            return ['ok' => false, 'why' => Dates::dmy($date) . ' is in the future — activity is recorded for a day that has happened.'];
        }

        $exit = trim((string) ($agent['exit_date'] ?? ''));
        $join = trim((string) ($agent['joining_date'] ?? ''));

        if ($join !== '' && $date < substr($join, 0, 10)) {
            return ['ok' => false, 'why' => $this->label($agent) . ' joined on ' . Dates::dmy(substr($join, 0, 10)) . '; ' . Dates::dmy($date) . ' is before that.'];
        }

        if ($exit !== '' && $date > substr($exit, 0, 10)) {
            return ['ok' => false, 'why' => $this->label($agent) . ' left on ' . Dates::dmy(substr($exit, 0, 10)) . '; ' . Dates::dmy($date) . ' is after that.'];
        }

        $status = strtolower(trim((string) ($input['day_status'] ?? '')));

        if (!isset($d['day_statuses'][$status])) {
            return ['ok' => false, 'why' => 'Pick the day: ' . implode(' / ', array_column((array) $d['day_statuses'], 'label')) . '.'];
        }

        $row = ['agent_id' => (string) $agent['id'], 'activity_date' => $date, 'day_status' => $status];

        foreach (['check_in', 'check_out'] as $t) {
            $v = trim((string) ($input[$t] ?? ''));

            if ($v !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
                return ['ok' => false, 'why' => "'{$v}' is not a time (hh:mm, 24-hour)."];
            }

            $row[$t] = $v === '' ? null : $v;
        }

        if ($row['check_in'] !== null && $row['check_out'] !== null && $row['check_out'] < $row['check_in']) {
            return ['ok' => false, 'why' => 'Check-out ' . $row['check_out'] . ' is before check-in ' . $row['check_in'] . '.'];
        }

        foreach (['allocated_accounts', 'visits', 'ptp_count', 'paid_accounts'] as $n) {
            $v = trim((string) ($input[$n] ?? ''));

            if ($v !== '' && preg_match('/^\d{1,5}$/', $v) !== 1) {
                return ['ok' => false, 'why' => "'{$v}' is not a whole number (" . str_replace('_', ' ', $n) . ').'];
            }

            $row[$n] = $v === '' ? null : (int) $v;
        }

        $amt = trim(str_replace([',', '₹', ' '], '', (string) ($input['amount_collected'] ?? '')));

        if ($amt !== '' && preg_match('/^\d+(\.\d{1,4})?$/', $amt) !== 1) {
            return ['ok' => false, 'why' => "'{$amt}' is not an amount (amount collected)."];
        }

        $row['amount_collected'] = $amt === '' ? null : Amount::of($amt)->toDecimalString();

        if (in_array($status, ['absent', 'leave', 'week_off'], true) && ((int) ($row['visits'] ?? 0) > 0 || $amt !== '' && !Amount::of($amt)->isZero())) {
            return ['ok' => false, 'why' => 'A day marked ' . $d['day_statuses'][$status]['label'] . ' cannot carry visits or collections — mark it Present or Half day.'];
        }

        if ((int) ($row['paid_accounts'] ?? 0) > (int) ($row['visits'] ?? 0) && (int) ($row['visits'] ?? 0) > 0) {
            return ['ok' => false, 'why' => 'Accounts paid (' . $row['paid_accounts'] . ') cannot be more than visits (' . $row['visits'] . ').'];
        }

        $remarks = mb_substr(trim((string) ($input['remarks'] ?? '')), 0, 300);
        $row['remarks'] = $remarks === '' ? null : $remarks;
        isset($input['check_in_geo']) && $row['check_in_geo'] = mb_substr((string) $input['check_in_geo'], 0, 64) ?: null;
        isset($input['check_out_geo']) && $row['check_out_geo'] = mb_substr((string) $input['check_out_geo'], 0, 64) ?: null;
        $have = $this->day((string) $agent['id'], $date);

        if ($have !== null && $this->locked($have)) {
            return ['ok' => false, 'why' => Dates::dmy($date) . ' for ' . $this->label($agent) . ' is inside an approved payout and cannot change.'];
        }

        $row += ['source' => $source, 'approval' => 'pending', 'created_by' => $by, 'decided_by' => null, 'decided_at' => null, 'decision_remarks' => null];
        $have === null || $row['id'] = $have['id'];
        $saved = $this->putAct($row);

        return ['ok' => true, 'row' => $saved, 'why' => ($have === null ? 'Recorded ' : 'Corrected ') . Dates::dmy($date) . ' for ' . $this->label($agent) . ' — awaiting approval.'];
    }

    /**
     * Approve / reject days. The person who recorded a day does not approve it — except the
     * Administrator, who has no restriction (D-OWN-01).
     *
     * @param list<string> $ids
     * @return array{ok: bool, why: string, done: int}
     */
    public function decideDays(array $ids, bool $approve, string $by, string $remarks, bool $isAdmin): array
    {
        $done = 0;
        $skipped = [];

        foreach ($ids as $id) {
            $this->activity();
            $r = $this->act[(string) $id] ?? null;

            if ($r === null || Modules::isRetired($r)) {
                continue;
            }

            if ($this->locked($r)) {
                $skipped[] = Dates::dmy((string) $r['activity_date']) . ' (in an approved payout)';
                continue;
            }

            if (!$isAdmin && (string) ($r['created_by'] ?? '') === $by) {
                $skipped[] = Dates::dmy((string) $r['activity_date']) . ' (you recorded it — a second person decides)';
                continue;
            }

            $this->putAct(['id' => $r['id'], 'approval' => $approve ? 'approved' : 'rejected', 'decided_by' => $by,
                'decided_at' => date('Y-m-d H:i:s'), 'decision_remarks' => mb_substr(trim($remarks), 0, 300) ?: null]);
            $done++;
        }

        return ['ok' => $done > 0, 'done' => $done, 'why' => ($approve ? 'Approved ' : 'Rejected ') . $done . ' day(s).'
            . ($skipped === [] ? '' : ' Not decided: ' . implode('; ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ' …' : '') . '.')];
    }

    /**
     * THE MOBILE APP'S EVENTS for an agent — login / logout (check-in / out, with GPS) and visits.
     * Each event carries the app's own external_id, so a resend is a duplicate, never a second visit.
     * Login / logout set that day's times; a visit is kept in offroll_visits and rolls up into the
     * day (visits, PTPs, accounts paid, amount collected). The day returns to pending for approval.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>> one result per event
     */
    public function ingestApp(array $events): array
    {
        $byCode = [];

        foreach ($this->agents() as $a) {
            $byCode[strtolower($this->code($a))] = $a;
            trim((string) ($a['mobile'] ?? '')) === '' || $byCode['m:' . preg_replace('/\D/', '', (string) $a['mobile'])] = $a;
        }

        $seen = [];

        foreach (self::rows($this->store, self::VISITS) as $v) {
            trim((string) ($v['external_id'] ?? '')) === '' || $seen[(string) $v['external_id']] = true;
        }

        $outcomes = (array) $this->declared()['visit_outcomes'];
        $out = [];

        foreach ($events as $e) {
            $e = is_array($e) ? $e : [];
            $ext = mb_substr(trim((string) ($e['external_id'] ?? '')), 0, 96);
            $type = strtolower(trim((string) ($e['type'] ?? '')));
            $code = strtolower(trim((string) ($e['agent_code'] ?? '')));
            $agent = $byCode[$code] ?? $byCode['m:' . preg_replace('/\D/', '', (string) ($e['mobile'] ?? ''))] ?? null;
            $iso = trim((string) ($e['at'] ?? ''));
            $at = preg_match('~(?:Z|[+\-]\d{2}:?\d{2})$~i', $iso) === 1 ? SmartEpt::local($iso) : null;

            if ($ext === '' || !in_array($type, ['login', 'logout', 'visit'], true) || $at === null) {
                $out[] = ['external_id' => $ext ?: null, 'status' => 'rejected', 'reason' => 'VALIDATION'];
                continue;
            }

            if ($agent === null) {
                $out[] = ['external_id' => $ext, 'status' => 'rejected', 'reason' => 'UNKNOWN_AGENT'];
                continue;
            }

            if (isset($seen[$ext])) {
                $out[] = ['external_id' => $ext, 'status' => 'duplicate'];
                continue;
            }

            $date = substr($at, 0, 10);
            $have = $this->day((string) $agent['id'], $date);

            if ($have !== null && $this->locked($have)) {
                $out[] = ['external_id' => $ext, 'status' => 'rejected', 'reason' => 'DAY_LOCKED'];
                continue;
            }

            $geo = self::coord($e['lat'] ?? null) !== null && self::coord($e['lng'] ?? null) !== null ? self::coord($e['lat']) . ',' . self::coord($e['lng']) : null;
            $day = $have ?? ['agent_id' => (string) $agent['id'], 'activity_date' => $date, 'day_status' => 'present', 'source' => 'app', 'created_by' => 'mobile-app'];
            $time = substr($at, 11, 5);
            $put = ['agent_id' => $day['agent_id'], 'activity_date' => $date, 'day_status' => (string) ($day['day_status'] ?? 'present') ?: 'present',
                'approval' => 'pending', 'decided_by' => null, 'decided_at' => null];
            isset($day['id']) && $put['id'] = $day['id'];
            isset($day['id']) || $put += ['source' => 'app', 'created_by' => 'mobile-app'];

            if ($type === 'login') {
                $in = trim((string) ($day['check_in'] ?? ''));
                ($in === '' || $time < $in) && $put += ['check_in' => $time, 'check_in_geo' => $geo];
            } elseif ($type === 'logout') {
                $o = trim((string) ($day['check_out'] ?? ''));
                ($o === '' || $time > $o) && $put += ['check_out' => $time, 'check_out_geo' => $geo];
            } else {
                $outcome = strtolower(trim((string) ($e['outcome'] ?? 'visited')));
                isset($outcomes[$outcome]) || $outcome = 'visited';
                $amt = trim((string) ($e['amount'] ?? ''));
                $money = preg_match('/^\d+(\.\d{1,4})?$/', $amt) === 1 ? Amount::of($amt) : Money::zero();
                $put['visits'] = (int) ($day['visits'] ?? 0) + 1;
                $put['ptp_count'] = (int) ($day['ptp_count'] ?? 0) + ($outcome === 'ptp' ? 1 : 0);
                $put['paid_accounts'] = (int) ($day['paid_accounts'] ?? 0) + ($outcome === 'paid' ? 1 : 0);
                $put['amount_collected'] = Amount::of((string) ($day['amount_collected'] ?? '0'))->plus($money)->toDecimalString();
            }

            $saved = $this->putAct($put);

            if ($type === 'visit') {
                $this->store->put(self::VISITS, ['agent_id' => (string) $agent['id'], 'activity_id' => (string) $saved['id'], 'visit_at' => $at,
                    'account_ref' => mb_substr(trim((string) ($e['account_ref'] ?? '')), 0, 64) ?: null, 'outcome' => $outcome,
                    'amount' => $money->isZero() ? null : $money->toDecimalString(), 'geo' => $geo,
                    'note' => mb_substr(trim((string) ($e['note'] ?? '')), 0, 300) ?: null, 'external_id' => $ext, 'source' => 'app']);
            } else {
                // A login / logout is remembered by its id too, so a resend is a duplicate.
                $this->store->put(self::VISITS, ['agent_id' => (string) $agent['id'], 'activity_id' => (string) $saved['id'], 'visit_at' => $at,
                    'outcome' => $type, 'geo' => $geo, 'external_id' => $ext, 'source' => 'app']);
            }

            $seen[$ext] = true;
            $out[] = ['external_id' => $ext, 'status' => 'accepted', 'agent_code' => $this->code($agent), 'date' => $date];
        }

        return $out;
    }

    // ------------------------------------------------------------------ the month, priced

    /**
     * ONE AGENT'S MONTH, PRICED — pure: everything it needs is passed in.
     *
     * @param list<array<string, mixed>> $plans newest first
     * @param list<array<string, mixed>> $days  this agent's activity rows of the month that COUNT
     * @param list<array<string, mixed>> $ledger this agent's approved ledger rows of the month
     * @param array<string, string> $settings
     * @return array<string, mixed>
     */
    public static function priceMonth(array $agent, array $plans, array $days, array $ledger, array $settings, array $declared,
        string $month, string $daysBasis, int $windowDays): array
    {
        $statuses = (array) $declared['day_statuses'];
        $kinds = (array) $declared['kinds'];
        $monthEnd = (new \DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d');
        $present = Money::zero(); // day counts kept exact as hundredths
        $visits = $ptp = $paidAcc = 0;
        $collected = Money::zero();
        $dayPay = $visitPay = $ptpPay = $paidPay = $collPay = Money::zero();
        $why = [];
        $expiredDays = [];
        $collectedByPlan = [];
        $plansSeen = [];

        usort($days, static fn (array $a, array $b): int => strcmp((string) $a['activity_date'], (string) $b['activity_date']));

        foreach ($days as $d) {
            $date = (string) $d['activity_date'];
            $plan = self::planOn($plans, $agent, $date);
            $w = Amount::quantity((string) ($statuses[(string) ($d['day_status'] ?? '')]['days'] ?? '0'));
            $present = $present->plus($w);
            $v = (int) ($d['visits'] ?? 0);
            $p = (int) ($d['ptp_count'] ?? 0);
            $pa = (int) ($d['paid_accounts'] ?? 0);
            $c = Amount::of((string) ($d['amount_collected'] ?? ''));
            $visits += $v;
            $ptp += $p;
            $paidAcc += $pa;
            $collected = $collected->plus($c);

            if (!$w->isZero()) {
                foreach (['dra_expiry' => 'DRA', 'pcc_expiry' => 'PCC'] as $col => $name) {
                    $band = self::certBand((string) ($agent[$col] ?? ''), $date, $windowDays);
                    in_array($band, [FieldReports::EXPIRED, FieldReports::NONE], true) && $expiredDays[$name][] = $date;
                }
            }

            if ($plan === null) {
                continue;
            }

            $key = (string) ($plan['id'] ?? '') . '|' . (string) ($plan['effective_from'] ?? '');
            $plansSeen[$key] = $plan;
            // per day present: a half day earns half
            $rate = Amount::of((string) ($plan['per_day_rate'] ?? ''));
            $rate->isZero() || $dayPay = $dayPay->plus($rate->multipliedBy(Rational::fromDecimalString($w->toDecimalString()), RoundingMode::HalfUp));
            $visitPay = $visitPay->plus(Amount::of((string) ($plan['per_visit_rate'] ?? ''))->multipliedBy($v));
            $ptpPay = $ptpPay->plus(Amount::of((string) ($plan['per_ptp_rate'] ?? ''))->multipliedBy($p));
            $paidPay = $paidPay->plus(Amount::of((string) ($plan['per_paid_account_rate'] ?? ''))->multipliedBy($pa));
            $collectedByPlan[$key] = ($collectedByPlan[$key] ?? Money::zero())->plus($c);
        }

        // COLLECTION PAY per plan in force on the days the money was collected (slabs apply to the month's
        // collection under that plan; a flat percent to each rupee).
        foreach ($collectedByPlan as $key => $amount) {
            $plan = $plansSeen[$key];
            $slabs = self::parseSlabs((string) ($plan['collection_slabs'] ?? '')) ?? [];

            if ($slabs !== []) {
                [$pay, $lines] = self::slabPay($amount, $slabs);
                $collPay = $collPay->plus($pay);
                $why = array_merge($why, array_map(static fn (string $l): string => 'Collection slab: ' . $l, $lines));
            } elseif (!Amount::of((string) ($plan['collection_percent'] ?? ''))->isZero()) {
                $pct = Amount::of((string) $plan['collection_percent'])->toDecimalString();
                $pay = $amount->multipliedBy(Rational::fromDecimalString($pct)->dividedBy(Rational::of(100)), RoundingMode::HalfUp);
                $collPay = $collPay->plus($pay);
                $why[] = 'Collection: ' . rtrim(rtrim($pct, '0'), '.') . '% of ' . $amount->format() . ' = ' . $pay->format();
            }
        }

        // FIXED: the plan in force at month end (or on the agent's last day, if they left in the month).
        $exit = substr(trim((string) ($agent['exit_date'] ?? '')), 0, 10);
        $fixedOn = $exit !== '' && $exit < $monthEnd ? $exit : $monthEnd;
        $fixedPlan = self::planOn($plans, $agent, $fixedOn);
        $fixedFull = Amount::of((string) ($fixedPlan['fixed_monthly'] ?? ''));
        $fixed = Money::zero();
        $basis = (string) ($settings['fixed_basis'] ?? 'full');
        $basisDays = Amount::quantity($daysBasis);

        if (!$fixedFull->isZero()) {
            if ($basis === 'full') {
                $fixed = $fixedFull;
                $why[] = 'Fixed: ' . $fixedFull->format() . ' (full month, as set in Organization Configuration)';
            } elseif (!$basisDays->isZero()) {
                $ratio = Rational::fromDecimalString($present->toDecimalString())->dividedBy(Rational::fromDecimalString($basisDays->toDecimalString()));
                $ratio->compareTo(Rational::of(1)) > 0 && $ratio = Rational::of(1); // never more than the full fixed
                $fixed = $fixedFull->multipliedBy($ratio, RoundingMode::HalfUp);
                $why[] = 'Fixed: ' . $fixedFull->format() . ' × ' . self::qty($present) . ' day(s) present ÷ ' . self::qty($basisDays) . ' = ' . $fixed->format();
            }
        }

        $dayPay->isZero() || $why[] = 'Per day: ' . self::qty($present) . ' day(s) present = ' . $dayPay->format();
        $visitPay->isZero() || $why[] = 'Visits: ' . $visits . ' = ' . $visitPay->format();
        $ptpPay->isZero() || $why[] = 'Promises to pay: ' . $ptp . ' = ' . $ptpPay->format();
        $paidPay->isZero() || $why[] = 'Accounts paid: ' . $paidAcc . ' = ' . $paidPay->format();

        $earned = Money::sum($fixed, $dayPay, $visitPay, $ptpPay, $paidPay, $collPay);
        $capPlan = $fixedPlan ?? ($plansSeen === [] ? null : end($plansSeen));
        $cap = Amount::of((string) ($capPlan['monthly_cap'] ?? ''));

        if (!$cap->isZero() && $earned->compareTo($cap) > 0) {
            $why[] = 'Capped: earned ' . $earned->format() . ', the monthly cap is ' . $cap->format();
            $earned = $cap;
        }

        $adj = $ded = $rec = Money::zero();

        foreach ($ledger as $l) {
            $k = (array) ($kinds[(string) ($l['kind'] ?? '')] ?? ['sign' => '+', 'label' => (string) ($l['kind'] ?? '')]);
            $m = Amount::of((string) ($l['amount'] ?? ''));
            $label = (string) ($k['label'] ?? '') . ' ' . Dates::dmy((string) ($l['entry_date'] ?? '')) . (trim((string) ($l['note'] ?? '')) !== '' ? ' (' . trim((string) $l['note']) . ')' : '');

            if ((string) ($k['sign'] ?? '+') === '-') {
                if ((bool) ($k['after_tds'] ?? false)) {
                    $rec = $rec->plus($m);
                    $why[] = 'Recovery after TDS: − ' . $m->format() . ' — ' . $label;
                } else {
                    $ded = $ded->plus($m);
                    $why[] = 'Deduction: − ' . $m->format() . ' — ' . $label;
                }
            } else {
                $adj = $adj->plus($m);
                $why[] = 'Adjustment: + ' . $m->format() . ' — ' . $label;
            }
        }

        $gross = $earned->plus($adj)->minus($ded);
        $gross->isNegative() && $gross = Money::zero();
        $flags = [];

        foreach ($expiredDays as $name => $dates) {
            $flags[] = $name . ' not valid / not on file on ' . count($dates) . ' working day(s) (' . Dates::dmy($dates[0]) . (count($dates) > 1 ? ' … ' . Dates::dmy(end($dates)) : '') . ')';
        }

        return [
            'days_present' => $present->toDecimalString(), 'days_basis' => $basisDays->toDecimalString(),
            'visits' => $visits, 'ptp_count' => $ptp, 'paid_accounts' => $paidAcc, 'amount_collected' => $collected->toDecimalString(),
            'fixed_amount' => $fixed->toDecimalString(), 'day_amount' => $dayPay->toDecimalString(), 'visit_amount' => $visitPay->toDecimalString(),
            'ptp_amount' => $ptpPay->toDecimalString(), 'paid_account_amount' => $paidPay->toDecimalString(), 'collection_amount' => $collPay->toDecimalString(),
            'adjustments' => $adj->toDecimalString(), 'deductions' => $ded->toDecimalString(), 'recoveries' => $rec->toDecimalString(),
            'gross' => $gross->toDecimalString(), 'explain' => $why, 'flags' => $flags, 'has_plan' => $plansSeen !== [] || $fixedPlan !== null,
        ];
    }

    /** A GPS coordinate as text, at most 6 decimals — never through a float. */
    private static function coord(mixed $v): ?string
    {
        $t = is_scalar($v) ? trim((string) $v) : '';

        return preg_match('/^-?\d{1,3}(\.\d+)?$/', $t) === 1 ? (string) preg_replace('/(\.\d{6})\d+$/', '$1', $t) : null;
    }

    private static function qty(Money $q): string
    {
        return rtrim(rtrim($q->toDecimalString(), '0'), '.') ?: '0';
    }

    /**
     * TDS ON ONE PAYEE'S AMOUNT THIS RUN, by the Government rule in config.
     *
     * The year threshold is the financial year's aggregate paid to the payee: once this run takes the
     * year past it, TDS is deducted on this run's amount PLUS what was paid earlier in the year without
     * TDS (the catch-up the rule requires). A contract sum above the single-sum threshold is taxed on its
     * own. No PAN: the higher of the section rate and the no-PAN rate. Rounded to the rupee.
     *
     * @return array{section: string, rate: string, base: string, tds: string, why: string}
     */
    public static function tds(Money $amount, Money $priorPaid, Money $priorUntaxed, string $section, string $entity, bool $hasPan, array $tdsCfg): array
    {
        $s = (array) ($tdsCfg['sections'][$section] ?? []);
        $rate = (string) ($s['rates'][$entity] ?? $s['rates']['individual'] ?? '0');
        $none = ['section' => $section, 'rate' => '0', 'base' => '0.00', 'tds' => '0.00'];

        if ($s === [] || Amount::quantity($rate)->isZero() || $amount->isZero()) {
            return $none + ['why' => $s === [] ? 'No TDS section.' : 'No TDS (' . (string) ($s['label'] ?? $section) . ').'];
        }

        $yearTh = trim((string) ($s['year_threshold'] ?? ''));
        $singleTh = trim((string) ($s['single_threshold'] ?? ''));
        $yearTotal = $priorPaid->plus($amount);
        $overYear = $yearTh !== '' && $yearTotal->compareTo(Amount::of($yearTh)) > 0;
        $overSingle = $singleTh !== '' && $amount->compareTo(Amount::of($singleTh)) > 0;
        $noThreshold = $yearTh === '' && $singleTh === '';

        if (!$overYear && !$overSingle && !$noThreshold) {
            return $none + ['why' => 'Below the threshold: ' . $yearTotal->format() . ' paid this financial year' . ($yearTh !== '' ? ' (TDS from above ' . Amount::of($yearTh)->format() . ')' : '') . '.'];
        }

        $base = $overYear ? $amount->plus($priorUntaxed) : $amount;

        if (!$hasPan && Amount::quantity((string) ($tdsCfg['no_pan_rate'] ?? '0'))->compareTo(Amount::quantity($rate)) > 0) {
            $rate = (string) $tdsCfg['no_pan_rate'];
        }

        $tds = $base->multipliedBy(Rational::fromDecimalString(Amount::quantity($rate)->toDecimalString())->dividedBy(Rational::of(100)), RoundingMode::HalfUp);
        ($tdsCfg['round_to_rupee'] ?? false) && $tds = $tds->roundTo(100, RoundingMode::HalfUp);

        return ['section' => $section, 'rate' => Amount::quantity($rate)->toDecimalString(), 'base' => $base->toDecimalString(), 'tds' => $tds->toDecimalString(),
            'why' => 'TDS ' . self::qty(Amount::quantity($rate)) . '% of ' . $base->format() . ($priorUntaxed->isZero() || !$overYear ? '' : ' (this run ' . $amount->format() . ' + ' . $priorUntaxed->format() . ' paid earlier this year without TDS)')
                . ($hasPan ? '' : ' — no PAN on file') . ' = ' . $tds->format() . ' · ' . (string) ($s['label'] ?? $section)];
    }

    // ------------------------------------------------------------------ payout runs

    /** @return list<array<string, mixed>> live runs, newest first */
    public function runs(): array
    {
        $rows = array_values(array_filter(self::rows($this->store, self::PAYOUTS), static fn (array $r): bool => !Modules::isRetired($r)));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['period'], (string) $a['period']) ?: (int) $b['id'] <=> (int) $a['id']);

        return $rows;
    }

    public function run(string $id): ?array
    {
        $r = $id === '' ? null : $this->store->find(self::PAYOUTS, $id);

        return $r === null || Modules::isRetired($r) ? null : $r;
    }

    /** @return list<array<string, mixed>> */
    public function lines(string $runId = ''): array
    {
        return array_values(array_filter(self::rows($this->store, self::LINES), static fn (array $l): bool
            => !Modules::isRetired($l) && ($runId === '' || (string) $l['payout_id'] === $runId)));
    }

    /** The financial year an ISO month falls in: [first month, last month]. @return array{0: string, 1: string} */
    public static function fyOf(string $month, int $fyStartMonth): array
    {
        $y = (int) substr($month, 0, 4);
        $m = (int) substr($month, 5, 2);
        $startYear = $m >= $fyStartMonth ? $y : $y - 1;
        $start = sprintf('%04d-%02d', $startYear, $fyStartMonth);
        $end = (new \DateTimeImmutable($start . '-01'))->modify('+11 months')->format('Y-m');

        return [$start, $end];
    }

    /**
     * GENERATE (or regenerate) a company's payout for a month.
     *
     * @param callable(string, string): string $workingDays (month first day, month last day) => working days, as a decimal string
     * @return array{ok: bool, why: string, run?: array<string, mixed>}
     */
    public function generate(string $companyId, string $month, string $by, callable $workingDays, int $fyStartMonth, int $windowDays): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return ['ok' => false, 'why' => 'Pick the month.'];
        }

        if ($companyId === '') {
            return ['ok' => false, 'why' => 'Pick the company — a payout run is per company.'];
        }

        $first = $month . '-01';
        $last = (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');

        if ($first > date('Y-m-d')) {
            return ['ok' => false, 'why' => 'That month has not started.'];
        }

        $existing = null;

        foreach ($this->runs() as $r) {
            if ((string) $r['period'] === $month && (string) ($r['employer_company_id'] ?? '') === $companyId) {
                $existing = $r;
            }
        }

        if ($existing !== null && (string) $existing['status'] === 'approved') {
            return ['ok' => false, 'why' => 'The payout for ' . date('F Y', (int) strtotime($first)) . ' is already approved; it cannot be regenerated.'];
        }

        $settings = $this->settings();
        $d = $this->declared();
        $agents = array_values(array_filter($this->agents(), static fn (array $a): bool
            => (string) ($a['employer_company_id'] ?? '') === $companyId
            && (trim((string) ($a['joining_date'] ?? '')) === '' || substr((string) $a['joining_date'], 0, 10) <= $last)
            && (trim((string) ($a['exit_date'] ?? '')) === '' || substr((string) $a['exit_date'], 0, 10) >= $first)));

        if ($agents === []) {
            return ['ok' => false, 'why' => 'No off-roll agent of this company was deployed in ' . date('F Y', (int) strtotime($first)) . '.'];
        }

        $daysBasis = match ($settings['fixed_basis']) {
            'prorata_working' => $workingDays($first, $last),
            'prorata_calendar' => (string) (int) substr($last, 8, 2),
            'prorata_fixed' => $settings['fixed_days'],
            default => (string) (int) substr($last, 8, 2),
        };

        $runId = $existing === null ? null : (string) $existing['id'];

        // A REGENERATED DRAFT starts clean: its lines are retired and what it counted is released.
        if ($runId !== null) {
            foreach ($this->lines($runId) as $l) {
                $this->store->put(self::LINES, ['id' => $l['id'], 'deleted_at' => date('Y-m-d H:i:s')]);
            }

            foreach ($this->activity() as $r) {
                (string) ($r['payout_id'] ?? '') === $runId && $this->putAct(['id' => $r['id'], 'payout_id' => null]);
            }

            foreach (self::rows($this->store, self::EARNINGS) as $r) {
                (string) ($r['payout_id'] ?? '') === $runId && $this->store->put(self::EARNINGS, ['id' => $r['id'], 'payout_id' => null]);
            }
        }

        $run = $this->store->put(self::PAYOUTS, array_filter(['id' => $runId, 'employer_company_id' => $companyId, 'period' => $month, 'status' => 'draft',
            'settings' => json_encode($settings + ['days_basis' => $daysBasis]), 'created_by' => $by], static fn ($v): bool => $v !== null));
        $runId = (string) $run['id'];
        $counts = $settings['count_basis'] === 'all' ? ['approved', 'pending'] : ['approved'];
        $plans = $this->plansByAgent();
        $dayRows = [];

        foreach ($this->activity($month) as $r) {
            $free = trim((string) ($r['payout_id'] ?? '')) === '' || (string) $r['payout_id'] === $runId;
            $free && in_array((string) ($r['approval'] ?? ''), $counts, true) && $dayRows[(string) $r['agent_id']][] = $r;
        }

        $ledger = [];

        foreach (self::rows($this->store, self::EARNINGS) as $l) {
            if (!str_starts_with((string) ($l['entry_date'] ?? ''), $month) || (string) ($l['status'] ?? '') !== 'approved' || Modules::isRetired($l)) {
                continue;
            }

            (trim((string) ($l['payout_id'] ?? '')) === '' || (string) $l['payout_id'] === $runId) && $ledger[(string) $l['agent_id']][] = $l;
        }

        // WHAT EACH PAYEE WAS ALREADY PAID THIS FINANCIAL YEAR (approved runs before this month).
        [$fyFrom] = self::fyOf($month, $fyStartMonth);
        $priorPaid = $priorUntaxed = [];

        foreach ($this->runs() as $r) {
            if ((string) $r['status'] !== 'approved' || (string) $r['period'] < $fyFrom || (string) $r['period'] >= $month) {
                continue;
            }

            foreach ($this->lines((string) $r['id']) as $l) {
                $payee = (string) ($l['paid_to'] ?? 'agent') === 'vendor' ? 'v' . (string) $l['vendor_id'] : 'a' . (string) $l['agent_id'];
                $g = Amount::of((string) ($l['gross'] ?? ''));
                $priorPaid[$payee] = ($priorPaid[$payee] ?? Money::zero())->plus($g);
                Amount::of((string) ($l['tds_amount'] ?? ''))->isZero() && $priorUntaxed[$payee] = ($priorUntaxed[$payee] ?? Money::zero())->plus($g);
            }
        }

        $vendors = $this->vendors();
        $priced = [];

        foreach ($agents as $a) {
            $p = self::priceMonth($a, $plans[(string) $a['id']] ?? [], $dayRows[(string) $a['id']] ?? [], $ledger[(string) $a['id']] ?? [], $settings, $d, $month, $daysBasis, $windowDays);

            if (Amount::of($p['gross'])->isZero() && Amount::of($p['recoveries'])->isZero() && ($dayRows[(string) $a['id']] ?? []) === []) {
                continue; // nothing worked, nothing owed: no line
            }

            $priced[(string) $a['id']] = ['agent' => $a, 'p' => $p, 'paid_to' => self::paidTo($a)];
        }

        // TDS PER PAYEE: an agent paid directly is their own payee; a vendor's agents are one payee.
        $groups = [];

        foreach ($priced as $id => $x) {
            $groups[$x['paid_to'] === 'vendor' ? 'v' . (string) $x['agent']['vendor_id'] : 'a' . $id][] = $id;
        }

        $tdsOn = $settings['tds_enabled'] === 'yes';
        $lineTds = [];
        $lineWhy = [];

        foreach ($groups as $payee => $ids) {
            $first0 = $priced[$ids[0]];
            $vendor = $first0['paid_to'] === 'vendor' ? ($vendors[(string) $first0['agent']['vendor_id']] ?? []) : null;
            $who = $vendor ?? $first0['agent'];
            $section = trim((string) ($who['tds_section'] ?? '')) ?: $settings['tds_section'];
            $entity = trim((string) ($who['entity_type'] ?? '')) ?: ($vendor === null ? 'individual' : 'other');
            $total = Money::sum(...array_map(static fn (string $i): Money => Amount::of($priced[$i]['p']['gross']), $ids));
            $t = $tdsOn
                ? self::tds($total, $priorPaid[$payee] ?? Money::zero(), $priorUntaxed[$payee] ?? Money::zero(), $section, $entity, trim((string) ($who['pan'] ?? '')) !== '', (array) $d['tds'])
                : ['section' => 'none', 'rate' => '0', 'base' => '0.00', 'tds' => '0.00', 'why' => 'TDS switched off in Organization Configuration.'];
            $tdsMoney = Amount::of($t['tds']);
            // A vendor's TDS is shared across its agents' lines by their gross (exact, residue on the last).
            $weights = array_map(static fn (string $i): int => max(0, (int) str_replace('.', '', $priced[$i]['p']['gross'])), $ids);
            $parts = $tdsMoney->isZero() || array_sum($weights) === 0 ? array_fill(0, count($ids), Money::zero()) : $tdsMoney->allocate($weights);

            foreach ($ids as $k => $i) {
                $lineTds[$i] = ['section' => $t['section'], 'rate' => $t['rate'], 'tds' => $parts[$k]];
                $lineWhy[$i] = $t['why'] . ($vendor !== null ? ' — vendor ' . (string) ($vendor['name'] ?? '') . ', shared by gross across ' . count($ids) . ' agent line(s)' : '');
            }
        }

        $gross = $tds = $net = Money::zero();
        $n = 0;

        foreach ($priced as $id => $x) {
            $p = $x['p'];
            $a = $x['agent'];
            $g = Amount::of($p['gross']);
            $t = $lineTds[$id]['tds'];
            $nt = $g->minus($t)->minus(Amount::of($p['recoveries']));
            $nt->isNegative() && $nt = Money::zero();
            $why = array_merge($p['explain'], ['Gross ' . $g->format(), $lineWhy[$id]], Amount::of($p['recoveries'])->isZero() ? [] : ['Recoveries after TDS − ' . Amount::of($p['recoveries'])->format()], ['Net payable ' . $nt->format()]);
            $p['has_plan'] || array_unshift($why, 'No pay structure in force — set one under Agent Pay Structure.');
            $hold = $p['flags'] !== [] && $settings['expired_certificate'] === 'hold';
            $this->store->put(self::LINES, [
                'payout_id' => $runId, 'agent_id' => $id, 'vendor_id' => $x['paid_to'] === 'vendor' ? (string) $a['vendor_id'] : null, 'paid_to' => $x['paid_to'],
                'days_present' => $p['days_present'], 'days_basis' => $p['days_basis'], 'visits' => $p['visits'], 'ptp_count' => $p['ptp_count'],
                'paid_accounts' => $p['paid_accounts'], 'amount_collected' => $p['amount_collected'], 'fixed_amount' => $p['fixed_amount'],
                'day_amount' => $p['day_amount'], 'visit_amount' => $p['visit_amount'], 'ptp_amount' => $p['ptp_amount'],
                'paid_account_amount' => $p['paid_account_amount'], 'collection_amount' => $p['collection_amount'],
                'adjustments' => $p['adjustments'], 'deductions' => Amount::of($p['deductions'])->plus(Amount::of($p['recoveries']))->toDecimalString(),
                'gross' => $g->toDecimalString(), 'tds_section' => $lineTds[$id]['section'], 'tds_rate' => $lineTds[$id]['rate'], 'tds_amount' => $t->toDecimalString(),
                'net' => $nt->toDecimalString(), 'explain' => json_encode($why, JSON_UNESCAPED_UNICODE),
                'flags' => $p['flags'] === [] ? null : mb_substr(implode('; ', $p['flags']), 0, 300),
                'payment_status' => $hold ? 'on_hold' : 'pending',
                'hold_reason' => $hold ? mb_substr('Certificate not valid while working: ' . implode('; ', $p['flags']), 0, 300) : null,
            ]);
            $gross = $gross->plus($g);
            $tds = $tds->plus($t);
            $net = $net->plus($nt);
            $n++;

            foreach ($dayRows[$id] ?? [] as $r) {
                $this->putAct(['id' => $r['id'], 'payout_id' => $runId]);
            }

            foreach ($ledger[$id] ?? [] as $l) {
                $this->store->put(self::EARNINGS, ['id' => $l['id'], 'payout_id' => $runId]);
            }
        }

        $run = $this->store->put(self::PAYOUTS, ['id' => $runId, 'lines' => $n, 'gross' => $gross->toDecimalString(), 'tds' => $tds->toDecimalString(), 'net' => $net->toDecimalString()]);
        $pendingDays = count(array_filter($this->activity($month), static fn (array $r): bool => (string) ($r['approval'] ?? '') === 'pending'));

        return ['ok' => true, 'run' => $run, 'why' => ($existing === null ? 'Generated' : 'Regenerated') . ' the ' . date('F Y', (int) strtotime($first)) . ' payout: '
            . $n . ' line(s), gross ' . $gross->format() . ', TDS ' . $tds->format() . ', net ' . $net->format() . '.'
            . ($pendingDays > 0 && $settings['count_basis'] === 'approved' ? ' ' . $pendingDays . ' day(s) of the month are still pending approval and were NOT counted.' : '')];
    }

    /** @return array{ok: bool, why: string} */
    public function approve(string $runId, string $by, bool $isAdmin): array
    {
        $run = $this->run($runId);

        if ($run === null) {
            return ['ok' => false, 'why' => 'No such payout run.'];
        }

        if ((string) $run['status'] === 'approved') {
            return ['ok' => false, 'why' => 'Already approved.'];
        }

        if (!$isAdmin && (string) ($run['created_by'] ?? '') === $by) {
            return ['ok' => false, 'why' => 'You generated this payout — a second person approves it.'];
        }

        $this->store->put(self::PAYOUTS, ['id' => $runId, 'status' => 'approved', 'approved_by' => $by, 'approved_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => 'Approved the ' . date('F Y', (int) strtotime((string) $run['period'] . '-01')) . ' payout. Its lines are now pending payment.'];
    }

    /** Discard a DRAFT run: lines retired, everything it counted released. @return array{ok: bool, why: string} */
    public function discard(string $runId): array
    {
        $run = $this->run($runId);

        if ($run === null || (string) $run['status'] !== 'draft') {
            return ['ok' => false, 'why' => 'Only a draft payout can be discarded.'];
        }

        foreach ($this->lines($runId) as $l) {
            $this->store->put(self::LINES, ['id' => $l['id'], 'deleted_at' => date('Y-m-d H:i:s')]);
        }

        foreach ($this->activity() as $r) {
            (string) ($r['payout_id'] ?? '') === $runId && $this->putAct(['id' => $r['id'], 'payout_id' => null]);
        }

        foreach (self::rows($this->store, self::EARNINGS) as $r) {
            (string) ($r['payout_id'] ?? '') === $runId && $this->store->put(self::EARNINGS, ['id' => $r['id'], 'payout_id' => null]);
        }

        $this->store->put(self::PAYOUTS, ['id' => $runId, 'deleted_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => 'Draft payout discarded; its activity and adjustments are free to count again.'];
    }

    /**
     * MARK PAID. A vendor's lines in a run are paid as one — picking any of them pays all of them.
     *
     * @param list<string> $lineIds
     * @return array{ok: bool, why: string}
     */
    public function markPaid(array $lineIds, string $paidOn, string $mode, string $ref, string $by): array
    {
        $on = Dates::toIso($paidOn) ?? '';

        if ($on === '' || $on > date('Y-m-d')) {
            return ['ok' => false, 'why' => 'Give the payment date (not in the future).'];
        }

        if (!isset($this->declared()['payment_modes'][$mode])) {
            return ['ok' => false, 'why' => 'Pick how it was paid.'];
        }

        $ref = mb_substr(trim($ref), 0, 64);

        if ($ref === '' && $mode !== 'cash') {
            return ['ok' => false, 'why' => 'Give the UTR / reference of the payment.'];
        }

        $todo = [];

        foreach ($lineIds as $id) {
            $l = $this->store->find(self::LINES, (string) $id);

            if ($l === null || Modules::isRetired($l)) {
                continue;
            }

            if ((string) $l['paid_to'] === 'vendor') {
                foreach ($this->lines((string) $l['payout_id']) as $o) {
                    (string) $o['paid_to'] === 'vendor' && (string) $o['vendor_id'] === (string) $l['vendor_id'] && $todo[(string) $o['id']] = $o;
                }
            } else {
                $todo[(string) $l['id']] = $l;
            }
        }

        $paid = 0;
        $why = [];

        foreach ($todo as $l) {
            $run = $this->run((string) $l['payout_id']);

            if ($run === null || (string) $run['status'] !== 'approved') {
                $why[] = 'a line of an unapproved payout';
                continue;
            }

            if ((string) $l['payment_status'] === 'paid') {
                continue;
            }

            if ((string) $l['payment_status'] === 'on_hold') {
                $why[] = 'a line on hold (release it first)';
                continue;
            }

            $this->store->put(self::LINES, ['id' => $l['id'], 'payment_status' => 'paid', 'paid_on' => $on, 'payment_mode' => $mode, 'payment_ref' => $ref ?: null, 'paid_by' => $by]);
            $paid++;
        }

        return ['ok' => $paid > 0, 'why' => $paid . ' line(s) marked paid on ' . Dates::dmy($on) . ($why === [] ? '.' : '. Skipped: ' . implode(', ', array_unique($why)) . '.')];
    }

    /** Hold a pending line, or release a held one. @return array{ok: bool, why: string} */
    public function hold(string $lineId, bool $hold, string $reason): array
    {
        $l = $this->store->find(self::LINES, $lineId);

        if ($l === null || Modules::isRetired($l) || (string) $l['payment_status'] === 'paid') {
            return ['ok' => false, 'why' => 'Only an unpaid line can be held or released.'];
        }

        $reason = mb_substr(trim($reason), 0, 300);

        if ($hold && $reason === '') {
            return ['ok' => false, 'why' => 'Say why the payment is held.'];
        }

        $this->store->put(self::LINES, ['id' => $lineId, 'payment_status' => $hold ? 'on_hold' : 'pending', 'hold_reason' => $hold ? $reason : ($reason === '' ? null : 'Released: ' . $reason)]);

        return ['ok' => true, 'why' => $hold ? 'Payment held.' : 'Hold released — the line is pending payment again.'];
    }

    // ------------------------------------------------------------------ helpers

    /** Write an activity row and keep the request's copy current. @return array<string, mixed> */
    private function putAct(array $row): array
    {
        $saved = $this->store->put(self::ACTIVITY, $row);
        $this->act === null || $this->act[(string) $saved['id']] = array_merge($this->act[(string) $saved['id']] ?? [], $saved);

        return $saved;
    }

    /** @return list<array<string, mixed>> every row, or none when the table is not there yet */
    public static function rows(RowStore $store, string $table): array
    {
        try {
            return $store->all($table);
        } catch (\Throwable) {
            return []; // a table this installation has not migrated yet reads as empty; the screen says so
        }
    }

    /** Is the schema in place? (the migration has run) */
    public function ready(): bool
    {
        try {
            $this->store->all(self::PAYOUTS);
            $cols = method_exists($this->store, 'columnsOf') ? $this->store->columnsOf(self::AGENTS) : ['dra_expiry'];

            return in_array('dra_expiry', $cols, true);
        } catch (\Throwable) {
            return false; // gate:allow-swallow not migrated yet — the screen shows the migration notice
        }
    }
}
