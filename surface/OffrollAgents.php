<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * OFF-ROLL AGENTS: KYC DOCUMENTS AND A LIVE-EARNINGS LEDGER, OUTSIDE PAYROLL.
 *
 * SmartPRS 2 (rev 80) keeps vendor agents off the payroll but gives each a KYC
 * card (photo, ID, PAN, DRA, PCC, agreement, bank, UPI, verified email) and an
 * earnings ledger: HR records an entry, the amount is worked out from the
 * agent's payout type and rate, an administrator approves it, and the agent
 * watches approved amounts live. The document slots, the entry kinds and how
 * each is priced are config/offroll-agents.json; the rows are the ported
 * `offroll_agents` and `agent_earnings` tables. Money is exact paise.
 */
final class OffrollAgents
{
    public const AGENTS = 'offroll_agents';
    public const EARNINGS = 'agent_earnings';

    public function __construct(private readonly RowStore $store, private readonly string $configFile)
    {
    }

    /** @return array<string, mixed> */
    public function declared(): array
    {
        $d = json_decode((string) file_get_contents($this->configFile), true);

        if (!is_array($d) || !is_array($d['kinds'] ?? null) || !is_array($d['documents'] ?? null)) {
            throw new \RuntimeException("config/offroll-agents.json is missing or unreadable at {$this->configFile}.");
        }

        return $d;
    }

    /** Live agents, by name. @return list<array<string, mixed>> */
    public function agents(): array
    {
        $rows = array_values(array_filter($this->store->all(self::AGENTS), static fn (array $a): bool => !Modules::isRetired($a)));
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $rows;
    }

    public function find(string $id): ?array
    {
        $a = $this->store->find(self::AGENTS, $id);

        return $a === null || Modules::isRetired($a) ? null : $a;
    }

    /** Contact and bank details — the KYC card's text fields. @return array{ok: bool, why: string} */
    public function saveContact(array $agent, array $input): array
    {
        $email = trim((string) ($input['email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'why' => "'{$email}' is not an email address."];
        }

        $put = ['id' => $agent['id'], 'email' => $email ?: null, 'mobile' => trim((string) ($input['mobile'] ?? '')) ?: null,
            'bank_details' => trim((string) ($input['bank_details'] ?? '')) ?: null, 'upi' => trim((string) ($input['upi'] ?? '')) ?: null,
            'mobile_verified' => ((string) ($input['mobile_verified'] ?? '') === '1') ? 1 : 0];

        // A changed address is no longer verified.
        if ($email !== (string) ($agent['email'] ?? '')) {
            $put['email_verified_at'] = null;
        }

        $this->store->put(self::AGENTS, $put);

        return ['ok' => true, 'why' => 'Details saved for ' . (string) $agent['name'] . '.'];
    }

    /** A document landed in a slot. @return array{ok: bool, why: string} */
    public function attach(array $agent, string $slot, string $path): array
    {
        if (!isset($this->declared()['documents'][$slot])) {
            return ['ok' => false, 'why' => "'{$slot}' is not a document slot."];
        }

        $this->store->put(self::AGENTS, ['id' => $agent['id'], $slot => $path]);

        return ['ok' => true, 'why' => (string) $this->declared()['documents'][$slot] . ' uploaded for ' . (string) $agent['name'] . '.'];
    }

    /**
     * The amount of an entry, from the agent's payout type and rate. Exact:
     * qty × rate in paise; percent as rate/100 of the base, rounded half-up to
     * the paise; a typed amount overrides when the kind allows or when given.
     */
    public function priced(array $agent, string $kind, string $qty, string $base, string $amount): Money
    {
        $k = (array) ($this->declared()['kinds'][$kind] ?? []);

        if (trim($amount) !== '') {
            return Amount::of($amount);
        }

        $rate = trim((string) ($agent['rate'] ?? ''));
        $rateMoney = $rate === '' ? Money::zero() : Amount::of($rate);

        return match ((string) ($k['basis'] ?? 'amount')) {
            'qty_x_rate' => $rateMoney->multipliedBy(Rational::fromDecimalString(trim($qty) === '' ? '0' : trim($qty)), RoundingMode::HalfUp),
            'percent_of_base' => Amount::of($base)->multipliedBy(Rational::fromDecimalString($rate === '' ? '0' : $rate)->dividedBy(Rational::of(100)), RoundingMode::HalfUp),
            'rate' => $rateMoney,
            default => Money::zero(),
        };
    }

    /** Record an entry (pending). @return array{ok: bool, why: string, row?: array<string, mixed>} */
    public function record(array $agent, array $input, string $by): array
    {
        $d = $this->declared();
        $kind = (string) ($input['kind'] ?? '');

        if (!isset($d['kinds'][$kind])) {
            return ['ok' => false, 'why' => "'{$kind}' is not an entry kind. Declared: " . implode(', ', array_keys($d['kinds'])) . '.'];
        }

        $date = trim((string) ($input['date'] ?? ''));

        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
            return ['ok' => false, 'why' => "'{$date}' is not a date."];
        }

        foreach (['qty', 'base', 'amount'] as $n) {
            $v = trim((string) ($input[$n] ?? ''));

            if ($v !== '' && preg_match('/^\d+(\.\d{1,4})?$/', $v) !== 1) {
                return ['ok' => false, 'why' => "'{$v}' is not a number ({$n})."];
            }
        }

        $money = $this->priced($agent, $kind, (string) ($input['qty'] ?? ''), (string) ($input['base'] ?? ''), (string) ($input['amount'] ?? ''));

        if ($money->isZero()) {
            return ['ok' => false, 'why' => 'The entry comes to zero: set the agent\'s rate on the register, or type an amount.'];
        }

        $row = $this->store->put(self::EARNINGS, array_filter([
            'agent_id' => (string) $agent['id'], 'entry_date' => $date, 'kind' => $kind,
            'qty' => trim((string) ($input['qty'] ?? '')) !== '' ? trim((string) $input['qty']) : null,
            'base_amount' => trim((string) ($input['base'] ?? '')) !== '' ? Amount::of((string) $input['base'])->toDecimalString() : null,
            'amount' => $money->toDecimalString(), 'note' => mb_substr(trim((string) ($input['note'] ?? '')), 0, 300) ?: null,
            'status' => 'pending', 'created_by' => $by,
        ], static fn ($v): bool => $v !== null));

        return ['ok' => true, 'row' => $row, 'why' => (string) $d['kinds'][$kind]['label'] . ' of ' . $money->format() . ' recorded for ' . (string) $agent['name'] . ', awaiting approval.'];
    }

    /** Approve or reject a pending entry. @return array{ok: bool, why: string} */
    public function decide(string $entryId, bool $approve, string $by, string $remarks, bool $isAdmin = false): array
    {
        $e = $this->store->find(self::EARNINGS, $entryId);

        if ($e === null) {
            return ['ok' => false, 'why' => 'No such entry.'];
        }

        if ((string) $e['status'] !== 'pending') {
            return ['ok' => false, 'why' => "This entry is already {$e['status']}."];
        }

        // ADMINISTRATOR HAS NO RESTRICTIONS (owner, 7 Oct 2026, D-OWN-01).
        if (!$isAdmin && (string) ($e['created_by'] ?? '') === $by) {
            return ['ok' => false, 'why' => 'The person who recorded an entry does not approve it. Maker and checker are two people.'];
        }

        $this->store->put(self::EARNINGS, ['id' => $e['id'], 'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by,
            'decided_at' => date('Y-m-d H:i:s'), 'remarks' => mb_substr(trim($remarks), 0, 300) ?: null]);

        return ['ok' => true, 'why' => ($approve ? 'Approved ' : 'Rejected ') . Amount::of((string) $e['amount'])->format() . '.'];
    }

    /**
     * One agent's ledger for a month: entries newest first, approved net,
     * pending total. Deductions carry the '-' sign from their kind.
     *
     * @return array{entries: list<array<string, mixed>>, net: Money, pending: Money}
     */
    public function ledger(string $agentId, string $month): array
    {
        $d = $this->declared();
        $entries = array_values(array_filter($this->store->all(self::EARNINGS), static fn (array $e): bool => (string) $e['agent_id'] === $agentId && str_starts_with((string) ($e['entry_date'] ?? ''), $month)));
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['entry_date'], (string) $a['entry_date']) ?: (int) $b['id'] <=> (int) $a['id']);
        $net = Money::zero();
        $pending = Money::zero();

        foreach ($entries as $i => $e) {
            $sign = (string) ($d['kinds'][(string) $e['kind']]['sign'] ?? '+');
            $m = Amount::of((string) $e['amount']);
            $entries[$i]['sign'] = $sign;
            $entries[$i]['money'] = $m;

            if ((string) $e['status'] === (string) ($d['counted_status'] ?? 'approved')) {
                $net = $sign === '-' ? $net->minus($m) : $net->plus($m);
            } elseif ((string) $e['status'] === 'pending') {
                $pending = $sign === '-' ? $pending->minus($m) : $pending->plus($m);
            }
        }

        return ['entries' => $entries, 'net' => $net, 'pending' => $pending];
    }

    /** How complete an agent's KYC is: [uploaded, total]. @return array{0: int, 1: int} */
    public function kycProgress(array $agent): array
    {
        $slots = array_keys((array) $this->declared()['documents']);
        $have = count(array_filter($slots, static fn (string $s): bool => trim((string) ($agent[$s] ?? '')) !== ''));

        return [$have, count($slots)];
    }
}
