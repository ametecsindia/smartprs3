<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * THE SAAS PLATFORM'S ARITHMETIC — what SmartPRS 2's admin panel computed in
 * PHP controllers and JS, in one place over the UNSCOPED store.
 *
 * Every figure here is Money (Law 7) and every rate is read from config —
 * plans.json for cycles and vocabularies, gst.json for the tax split,
 * feature-flags.json for the flag keys, leads.json for the pipeline. The
 * screens under surface/screens/ render; nothing there adds up.
 *
 * Constructed over `$platform`, never `$store`: tenants, subscriptions and
 * invoices of EVERY tenant are the platform's book, and a scoped store would
 * show the operator only its own row.
 */
final class SaasPlatform
{
    /** @var array<string, mixed> */
    private readonly array $plans;
    /** @var array<string, mixed> */
    private readonly array $gst;
    /** @var array<string, mixed> */
    private readonly array $flags;
    /** @var array<string, mixed> */
    private readonly array $leads;

    public function __construct(private readonly RowStore $platform, string $configDir)
    {
        $read = static fn (string $name): array => (array) json_decode(
            (string) file_get_contents($configDir . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->plans = $read('plans');
        $this->gst = $read('gst');
        $this->flags = $read('feature-flags');
        $this->leads = $read('leads');
    }

    /** @return array<string, mixed> config/plans.json */
    public function declared(): array
    {
        return $this->plans;
    }

    /** @return array<string, mixed> config/gst.json */
    public function gst(): array
    {
        return $this->gst;
    }

    /** @return array<string, array<string, string>> config/feature-flags.json flags */
    public function flagKeys(): array
    {
        return (array) ($this->flags['flags'] ?? []);
    }

    /** @return array<string, mixed> config/leads.json */
    public function leadPipeline(): array
    {
        return $this->leads;
    }

    // ---- plans ---------------------------------------------------------------

    /**
     * What a plan costs PER MONTH for this many seats: the base price covers
     * `seat_max` employees, every one beyond that is `per_user_price`. A plan
     * with no seat cap is unlimited and never charges extras (SmartPRS 2).
     *
     * @param array<string, mixed> $plan
     */
    public static function monthly(array $plan, int $seats): Money
    {
        $base = Amount::of($plan['base_price'] ?? '0');
        $cap = (int) ($plan['seat_max'] ?? 0);
        $extra = $cap > 0 ? max(0, $seats - $cap) : 0;

        return $base->plus(Amount::of($plan['per_user_price'] ?? '0')->multipliedBy($extra));
    }

    /**
     * One `plans` row per price-list package (config/landing.json "pricing" → cloud.packages, as edited), matched by
     * name: price, extra-employee rate and employees included follow the price list; modules and status stay.
     *
     * @param list<array<string, mixed>> $packages
     */
    public function syncPackages(array $packages): void
    {
        $byName = [];

        foreach ($this->platform->all('plans') as $r) {
            if (strtolower((string) ($r['status'] ?? '')) !== 'withdrawn') {
                $byName[strtolower((string) ($r['name'] ?? ''))] ??= $r;
            }
        }

        foreach ($packages as $p) {
            $name = trim((string) ($p['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $row = ['name' => $name, 'base_price' => Amount::of((string) ($p['monthly'] ?? '0'))->toDecimalString(),
                'per_user_price' => Amount::of((string) (($p['extra'] ?? '') === '' ? '0' : $p['extra']))->toDecimalString(), 'seat_max' => (int) ($p['employees'] ?? 0)];
            $have = $byName[strtolower($name)] ?? null;

            if ($have === null) {
                $this->platform->put('plans', $row + ['billing_cycle' => 'monthly', 'status' => 'offered', 'features' => (string) json_encode(['modules' => []])]);
            } elseif (!Amount::same($have['base_price'] ?? '0', $row['base_price']) || !Amount::same($have['per_user_price'] ?? '0', $row['per_user_price'])
                || (int) ($have['seat_max'] ?? 0) !== $row['seat_max']) {
                $this->platform->put('plans', ['id' => $have['id']] + $row);
            }
        }
    }

    /** Months a billing cycle bills for, from config; an unknown cycle is one month. */
    public function months(string $cycle): int
    {
        return (int) (((array) ($this->plans['cycles'] ?? []))[$cycle] ?? 1);
    }

    /**
     * The module keys a plan unlocks, from its `features` JSON column.
     *
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    public static function planModules(array $plan): array
    {
        $features = json_decode((string) ($plan['features'] ?? ''), true);

        return array_values(array_map('strval', (array) ($features['modules'] ?? [])));
    }

    // ---- subscriptions -------------------------------------------------------

    /**
     * MONTHLY RECURRING REVENUE: every live subscription's monthly price at its
     * seat count, added up. Subscriptions whose plan is gone contribute nothing
     * and are counted in `unpriced` so the tile can say so (Law 4).
     *
     * @param list<array<string, mixed>> $subscriptions
     * @param list<array<string, mixed>> $plans
     * @return array{mrr: Money, live: int, unpriced: int}
     */
    public function mrr(array $subscriptions, array $plans): array
    {
        $byId = [];

        foreach ($plans as $p) {
            $byId[(string) ($p['id'] ?? '')] = $p;
        }

        $live = (array) ($this->plans['subscription_live'] ?? []);
        $mrr = Money::zero();
        $count = 0;
        $unpriced = 0;

        foreach ($subscriptions as $s) {
            if (!in_array((string) ($s['status'] ?? ''), $live, true)) {
                continue;
            }

            $count++;
            $plan = $byId[(string) ($s['plan_id'] ?? '')] ?? null;

            if ($plan === null) {
                $unpriced++;
                continue;
            }

            $mrr = $mrr->plus(self::monthly($plan, (int) ($s['seats'] ?? 0)));
        }

        return ['mrr' => $mrr, 'live' => $count, 'unpriced' => $unpriced];
    }

    // ---- GST -----------------------------------------------------------------

    /**
     * The state code for a state name or a code. "Telangana" and "36" both
     * resolve to 36; anything unknown resolves to '' and is taxed as IGST —
     * the safe default, because an intra-state split on a wrong guess
     * understates the tax owed.
     */
    public function stateCode(string $state): string
    {
        $state = trim($state);
        $codes = (array) ($this->gst['state_codes'] ?? []);

        if (preg_match('/^\d{2}$/', $state) === 1) {
            return $state;
        }

        foreach ($codes as $name => $code) {
            if (strcasecmp((string) $name, $state) === 0) {
                return (string) $code;
            }
        }

        // "Telangana (36)" as SmartPRS 2 stored it
        return preg_match('/\((\d{2})\)/', $state, $m) === 1 ? $m[1] : '';
    }

    /**
     * GST on a taxable amount: CGST+SGST when the buyer's state is ours, IGST
     * otherwise. Each half of an intra-state split is rounded on its own so
     * the two printed lines add up to what is charged.
     *
     * @return array{mode: string, rate: int, cgst: Money, sgst: Money, igst: Money, tax: Money, total: Money}
     */
    public function tax(Money $taxable, string $buyerState): array
    {
        $rate = (int) ($this->gst['rate_percent'] ?? 0);
        $intra = $this->stateCode($buyerState) !== ''
            && $this->stateCode($buyerState) === (string) ($this->gst['home_state_code'] ?? '');
        $zero = Money::zero();

        if ($intra) {
            $half = $taxable->multipliedBy(Rational::of($rate, 200), RoundingMode::HalfUp);

            return ['mode' => 'CGST+SGST', 'rate' => $rate, 'cgst' => $half, 'sgst' => $half, 'igst' => $zero,
                'tax' => $half->plus($half), 'total' => $taxable->plus($half)->plus($half)];
        }

        $igst = $taxable->multipliedBy(Rational::percent($rate), RoundingMode::HalfUp);

        return ['mode' => 'IGST', 'rate' => $rate, 'cgst' => $zero, 'sgst' => $zero, 'igst' => $igst,
            'tax' => $igst, 'total' => $taxable->plus($igst)];
    }

    // ---- coupons -------------------------------------------------------------

    /**
     * Why this coupon cannot be used today, or null when it can. Every rule
     * the row carries is checked: status, window, uses, context, plan list.
     *
     * @param array<string, mixed> $coupon
     */
    public static function couponProblem(array $coupon, string $today, string $context = 'signup', string $planId = ''): ?string
    {
        $code = (string) ($coupon['code'] ?? '');

        if ((string) ($coupon['status'] ?? 'active') !== 'active') {
            return "Coupon {$code} is " . (string) $coupon['status'] . '.';
        }

        $from = (string) ($coupon['valid_from'] ?? '');
        $till = (string) ($coupon['valid_till'] ?? '');

        if ($from !== '' && substr($today, 0, 10) < substr($from, 0, 10)) {
            return "Coupon {$code} starts on {$from}.";
        }

        if ($till !== '' && substr($today, 0, 10) > substr($till, 0, 10)) {
            return "Coupon {$code} expired on {$till}.";
        }

        $max = (int) ($coupon['max_uses'] ?? 0);

        if ($max > 0 && (int) ($coupon['used_count'] ?? 0) >= $max) {
            return "Coupon {$code} has been used {$max} times, its limit.";
        }

        $applies = (string) ($coupon['applies_to'] ?? 'both');

        if ($applies !== '' && $applies !== 'both' && $applies !== $context) {
            return "Coupon {$code} applies to {$applies} only.";
        }

        $plans = array_filter(array_map('trim', explode(',', (string) ($coupon['plan_ids'] ?? ''))));

        if ($plans !== [] && $planId !== '' && !in_array($planId, $plans, true)) {
            return "Coupon {$code} is not valid for this plan.";
        }

        return null;
    }

    /**
     * The discount a coupon takes off a subtotal: a percent of it, or a flat
     * amount, never more than the subtotal itself.
     *
     * @param array<string, mixed> $coupon
     */
    public static function discount(array $coupon, Money $subtotal): Money
    {
        $value = (string) ($coupon['value'] ?? '0');
        $off = (string) ($coupon['type'] ?? 'percent') === 'flat'
            ? Amount::of($value)
            : $subtotal->multipliedBy(Rational::fromDecimalString($value)->dividedBy(Rational::of(100)), RoundingMode::HalfUp);

        return $off->compareTo($subtotal) > 0 ? $subtotal : $off;
    }

    // ---- quotations ----------------------------------------------------------

    /**
     * A quotation's figures: plan × seats × cycle months, less the coupon,
     * plus GST for the buyer's state. `discount_override` is a flat amount
     * typed by staff instead of a coupon.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed>|null $coupon
     * @return array{subtotal: Money, discount: Money, taxable: Money, tax: Money, total: Money, mode: string, months: int}
     */
    public function quote(array $plan, int $seats, string $cycle, ?array $coupon, string $buyerState, string $discountOverride = ''): array
    {
        $months = $this->months($cycle);
        $subtotal = self::monthly($plan, $seats)->multipliedBy($months);
        $discount = $coupon !== null ? self::discount($coupon, $subtotal) : Amount::of($discountOverride);

        if ($discount->compareTo($subtotal) > 0) {
            $discount = $subtotal;
        }

        $taxable = $subtotal->minus($discount);
        $gst = $this->tax($taxable, $buyerState);

        return ['subtotal' => $subtotal, 'discount' => $discount, 'taxable' => $taxable,
            'tax' => $gst['tax'], 'total' => $gst['total'], 'mode' => $gst['mode'], 'months' => $months];
    }

    // ---- invoices ------------------------------------------------------------

    /** SP-2026-000042: prefix from config, the year issued, a running number. */
    public function invoiceNumber(int $sequence, string $issuedOn): string
    {
        return (string) ($this->plans['invoice_number_prefix'] ?? '') . substr($issuedOn, 0, 4) . '-'
            . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * What an invoice still owes after its succeeded payments.
     *
     * @param array<string, mixed> $invoice
     * @param list<array<string, mixed>> $payments
     */
    /**
     * A CLOUD INVOICE AS A .pdf (owner, 13 Nov 2026: PDF and email "for subscriber of cloud as well") — the same
     * GST split the invoice was raised with; written by TablePdf, no library.
     *
     * @param list<array<string, mixed>> $payments
     */
    public function invoicePdf(array $invoice, array $tenant, array $payments, string $planName = ''): string
    {
        $taxable = Amount::of($invoice['amount'] ?? '0');
        $tax = $this->tax($taxable, (string) ($tenant['state'] ?? ''));
        $total = $taxable->plus(Amount::of($invoice['tax'] ?? '0'));
        $balance = self::balance($invoice, $payments);
        $dmy = static fn (string $d): string => $d === '' ? '—' : date('d-m-Y', (int) strtotime($d));
        $seller = (array) ($this->gst['seller'] ?? []);
        $rows = [['SmartPRS cloud subscription' . ($planName !== '' ? ' — ' . $planName : '') . ', ' . $dmy((string) ($invoice['period_from'] ?? ''))
            . ' to ' . $dmy((string) ($invoice['period_to'] ?? '')) . ' (SAC 998315)', $taxable->format()]];

        if ($tax['mode'] === 'CGST+SGST') {
            $half = Amount::of($invoice['tax'] ?? '0')->dividedBy(2, RoundingMode::HalfUp);
            $rows[] = ['CGST ' . ($tax['rate'] / 2) . '%', $half->format()];
            $rows[] = ['SGST ' . ($tax['rate'] / 2) . '%', Amount::of($invoice['tax'] ?? '0')->minus($half)->format()];
        } else {
            $rows[] = ['IGST ' . $tax['rate'] . '%', Amount::of($invoice['tax'] ?? '0')->format()];
        }

        array_push($rows, ['TOTAL', $total->format()], ['Paid', $total->minus($balance)->format()],
            ['BALANCE DUE' . (trim((string) ($invoice['due_on'] ?? '')) !== '' ? ' (by ' . $dmy((string) $invoice['due_on']) . ')' : ''), $balance->format()]);
        trim((string) ($invoice['notes'] ?? '')) === '' || $rows[] = [(string) $invoice['notes'], ''];
        $rows[] = ['Computer-generated invoice.', ''];

        return TablePdf::render('TAX INVOICE ' . (string) ($invoice['number'] ?? '') . '  ·  ' . $dmy((string) ($invoice['issued_on'] ?? '')),
            trim((string) ($seller['name'] ?? '')) . (trim((string) ($seller['gstin'] ?? '')) !== '' ? ' (GSTIN ' . $seller['gstin'] . ')' : '')
            . '   |   Bill to: ' . (string) ($tenant['name'] ?? '') . (trim((string) ($tenant['state'] ?? '')) !== '' ? ', ' . $tenant['state'] : '')
            . (trim((string) ($tenant['gstin'] ?? '')) !== '' ? ' (GSTIN ' . $tenant['gstin'] . ')' : ''),
            ['Description', 'Amount'], $rows, [1]);
    }

    public static function balance(array $invoice, array $payments): Money
    {
        $due = Amount::of($invoice['amount'] ?? '0')->plus(Amount::of($invoice['tax'] ?? '0'));

        foreach ($payments as $p) {
            if ((string) ($p['invoice_id'] ?? '') === (string) ($invoice['id'] ?? '')
                && (string) ($p['status'] ?? '') === 'succeeded') {
                $due = $due->minus(Amount::of($p['amount'] ?? '0'));
            }
        }

        return $due;
    }

    // ---- feature flags -------------------------------------------------------

    /**
     * on/off for a flag and a tenant: the tenant's own row, else the global
     * row, else the config default. A row with an empty value is a cleared
     * override (the store cannot delete) and counts as absent. An undeclared key is off — a switch nobody
     * declared is not one anybody turned on.
     *
     * @param list<array<string, mixed>> $rows feature_flags rows
     */
    public function flagState(string $key, ?string $tenantId, array $rows): string
    {
        $declared = $this->flagKeys()[$key] ?? null;

        if ($declared === null) {
            return 'off';
        }

        $global = null;

        foreach ($rows as $r) {
            // a row whose value is neither on nor off is a cleared override
            if ((string) ($r['flag_key'] ?? '') !== $key || !in_array((string) ($r['value'] ?? ''), ['on', 'off'], true)) {
                continue;
            }

            $owner = (string) ($r['tenant_ref'] ?? '');

            if ($tenantId !== null && $owner === $tenantId) {
                return (string) ($r['value'] ?? 'off') === 'on' ? 'on' : 'off';
            }

            if ($owner === '') {
                $global = $r;
            }
        }

        return $global !== null
            ? ((string) ($global['value'] ?? 'off') === 'on' ? 'on' : 'off')
            : ((string) ($declared['default'] ?? 'off') === 'on' ? 'on' : 'off');
    }

    // ---- licences ------------------------------------------------------------

    /**
     * Licences whose AMC ends within `days` of today, or already has.
     *
     * @param list<array<string, mixed>> $licences
     * @return list<array<string, mixed>>
     */
    public static function expiring(array $licences, string $today, int $days): array
    {
        $limit = (new \DateTimeImmutable(substr($today, 0, 10)))->modify("+{$days} days")->format('Y-m-d');

        return array_values(array_filter($licences, static fn (array $l): bool
            => (string) ($l['amc_expires_on'] ?? '') !== '' && (string) $l['amc_expires_on'] <= $limit
                && (string) ($l['status'] ?? '') !== 'revoked'));
    }

    // ---- the dashboard -------------------------------------------------------

    /**
     * The founder's scoreboard, every count from the platform-wide store.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $today): array
    {
        $subscriptions = $this->platform->all('subscriptions');
        $plans = $this->platform->all('plans');
        $invoices = $this->platform->all('invoices');
        $open = (array) ($this->plans['invoice_open'] ?? []);
        $due = Money::zero();
        $dueCount = 0;
        $overdue = 0;

        foreach ($invoices as $i) {
            if (!in_array((string) ($i['status'] ?? ''), $open, true)) {
                continue;
            }

            $dueCount++;
            $due = $due->plus(Amount::of($i['amount'] ?? '0'))->plus(Amount::of($i['tax'] ?? '0'));

            if ((string) ($i['due_on'] ?? '') !== '' && (string) $i['due_on'] < substr($today, 0, 10)) {
                $overdue++;
            }
        }

        $month = substr($today, 0, 7);
        $leads = array_filter($this->platform->all('leads'), static fn (array $l): bool
            => str_starts_with((string) ($l['created_at'] ?? ''), $month));
        $tenants = $this->platform->all('tenants');
        $byStatus = [];

        foreach ($tenants as $t) {
            $byStatus[(string) ($t['status_code'] ?? '')] = ($byStatus[(string) ($t['status_code'] ?? '')] ?? 0) + 1;
        }

        return [
            'tenants' => count($tenants),
            'tenants_by_status' => $byStatus,
            ...$this->mrr($subscriptions, $plans),
            'invoices_due' => $dueCount,
            'invoices_due_amount' => $due,
            'invoices_overdue' => $overdue,
            'leads_this_month' => count($leads),
            'licences_expiring' => count(self::expiring($this->platform->all('licences'), $today,
                (int) ($this->plans['licence_expiring_days'] ?? 30))),
        ];
    }

    /**
     * Every tenant with its live subscription's plan and its employee count,
     * each count read through a store scoped to THAT tenant.
     *
     * @return list<array<string, mixed>>
     */
    public function tenants(): array
    {
        $plans = [];

        foreach ($this->platform->all('plans') as $p) {
            $plans[(string) $p['id']] = (string) ($p['name'] ?? '');
        }

        $live = (array) ($this->plans['subscription_live'] ?? []);
        $subs = [];

        foreach ($this->platform->all('subscriptions') as $s) {
            if (in_array((string) ($s['status'] ?? ''), $live, true)) {
                $subs[(string) ($s['tenant_id'] ?? '')] = $s;
            }
        }

        $out = [];

        foreach ($this->platform->all('tenants') as $t) {
            $id = (string) $t['id'];
            $sub = $subs[$id] ?? null;
            $out[] = [...$t,
                'plan' => $sub === null ? '' : ($plans[(string) ($sub['plan_id'] ?? '')] ?? ('Plan #' . (string) $sub['plan_id'])),
                'subscription' => $sub,
                'employees' => $this->platform->forTenant($id)->count('employees'),
            ];
        }

        return $out;
    }
}
