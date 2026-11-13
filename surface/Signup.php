<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * SELF-SERVE SIGNUP & CHECKOUT (owner, 12 Nov 2026: "same SmartPRS2 logic", smartprs.com/signup) — SmartPRS 2's
 * SignupController + BillingController::priceFor / withSetup / razorpayCreds, over SmartPRS 3's platform store.
 *
 *   price    per month = plan + extra employees x the plan's rate + extra companies x company fee; x the advance
 *            months; less the advance discount; less a coupon (after the discount, never on setup); + setup &
 *            onboarding; + GST for the buyer's state. All in paise (integers) — Law 7.
 *   order    a pending `signups` row + a Razorpay order for the total.
 *   complete the Razorpay signature checked, then the workspace is made: tenant, company, administrator,
 *            subscription, paid invoice, payment — and a set-your-password link.
 *
 * Prices: config/landing.json "pricing" (the same figures the website and its calculator show). The plan rows the
 * subscriptions point at are found by name and made if missing.
 */
final class Signup
{
    public const CHECKOUT = 'https://checkout.razorpay.com/v1/checkout.js';
    private const ORDERS = 'https://api.razorpay.com/v1/orders';

    /** @param array<string, mixed> $pricing landing "pricing" @param array<string, mixed> $gst config/gst.json */
    public function __construct(private readonly RowStore $platform, private readonly array $pricing, private readonly array $gst,
        private readonly string $envFile)
    {
    }

    /** @return list<array<string, mixed>> */
    public function plans(): array
    {
        $p = array_values((array) ($this->pricing['cloud']['packages'] ?? []));
        usort($p, static fn (array $a, array $b): int => (int) $a['employees'] <=> (int) $b['employees']);

        return $p;
    }

    /** @return array<string, mixed>|null */
    public function plan(string $name): ?array
    {
        foreach ($this->plans() as $p) {
            if (strcasecmp((string) $p['name'], $name) === 0) {
                return $p;
            }
        }

        return null;
    }

    /** @return list<array{months: int, discount: int, label: string}> */
    public function cycles(): array
    {
        return array_values((array) ($this->pricing['cloud']['cycles'] ?? []));
    }

    /** The smallest plan that covers this many employees (the largest when none does). */
    public function bestFit(int $employees): ?array
    {
        foreach ($this->plans() as $p) {
            if ($employees <= (int) $p['employees']) {
                return $p;
            }
        }

        $all = $this->plans();

        return $all === [] ? null : end($all);
    }

    public function maxEmployees(): int
    {
        $p = $this->plans();

        return $p === [] ? 0 : (int) end($p)['employees'];
    }

    public function setup(int $employees): int
    {
        $o = (array) ($this->pricing['onboarding'] ?? []);
        $base = (int) ($o['base'] ?? 0);
        $per = (int) ($o['per_employee'] ?? 0) * $employees;

        return (string) ($o['mode'] ?? '') === 'max' ? max($base, $per) : $base + $per;
    }

    /**
     * The price, in paise. $coupon is a `coupons` row already checked (or null).
     *
     * @param array<string, mixed>|null $coupon
     * @return array<string, mixed>
     */
    public function price(array $plan, int $employees, int $companies, int $months, ?array $coupon = null, string $state = '', bool $withSetup = true): array
    {
        $cycle = null;

        foreach ($this->cycles() as $c) {
            if ((int) $c['months'] === $months) {
                $cycle = $c;
            }
        }

        $cycle ??= $this->cycles()[0];
        $months = (int) $cycle['months'];
        $companies = max(1, $companies);
        $extra = max(0, $employees - (int) $plan['employees']);
        $perMonth = 100 * ((int) $plan['monthly'] + (int) ($plan['extra'] ?? 0) * $extra + (int) ($this->pricing['cloud']['company_fee'] ?? 0) * ($companies - 1));
        $gross = $perMonth * $months;
        $discount = intdiv($gross * (int) $cycle['discount'] + 50, 100);
        $subscription = $gross - $discount;
        $couponOff = 0;

        if ($coupon !== null) {
            $couponOff = (string) ($coupon['type'] ?? 'percent') === 'flat'
                ? (int) round(100 * (float) ($coupon['value'] ?? 0))
                : intdiv($subscription * (int) round(100 * (float) ($coupon['value'] ?? 0)) + 5000, 10000);
            $couponOff = min($couponOff, $subscription);
        }

        // A RENEWAL carries no setup & onboarding charge — that is paid once, at signup (SmartPRS 2 priceFor vs withSetup).
        $setup = $withSetup ? 100 * $this->setup($employees) : 0;
        $taxable = $subscription - $couponOff + $setup;
        $rate = (int) ($this->gst['rate_percent'] ?? 0);
        $intra = $state !== '' && $this->stateCode($state) === (string) ($this->gst['home_state_code'] ?? '');
        $tax = $intra ? 2 * intdiv($taxable * $rate + 100, 200) : intdiv($taxable * $rate + 50, 100);

        return ['plan' => (string) $plan['name'], 'plan_employees' => (int) $plan['employees'], 'employees' => $employees, 'companies' => $companies, 'months' => $months,
            'cycle_label' => (string) $cycle['label'], 'discount_percent' => (int) $cycle['discount'], 'extra' => $extra,
            'per_month' => $perMonth, 'gross' => $gross, 'discount' => $discount, 'subscription' => $subscription,
            'coupon' => $coupon === null ? null : (string) $coupon['code'], 'coupon_off' => $couponOff, 'setup' => $setup,
            'taxable' => $taxable, 'tax' => $tax, 'tax_mode' => $intra ? 'CGST+SGST' : 'IGST', 'total' => $taxable + $tax];
    }

    public function stateCode(string $state): string
    {
        if (preg_match('/\((\d{2})\)/', $state, $m) === 1) {
            return $m[1];
        }

        return (string) (((array) ($this->gst['state_codes'] ?? []))[trim($state)] ?? '');
    }

    public static function rupees(int $paise): string
    {
        $r = intdiv($paise, 100);
        $s = (string) $r;
        $s = strlen($s) > 3 ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3) : $s;

        return '₹' . $s . ($paise % 100 !== 0 ? '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT) : '');
    }

    // ---------------------------------------------------------------- the gateway

    /** Razorpay key + secret from Super Admin → Payment Gateways (a live or configured razorpay row). @return array{key: string, secret: string, mode: string}|null */
    public function gateway(): ?array
    {
        foreach ($this->platform->all('payment_gateways') as $g) {
            if (strcasecmp(trim((string) ($g['gateway'] ?? '')), 'razorpay') === 0
                && in_array(strtolower((string) ($g['status'] ?? '')), ['configured', 'live', 'active'], true)
                && trim((string) ($g['key_id'] ?? '')) !== '' && trim((string) ($g['secret'] ?? '')) !== '') {
                $raw = trim((string) $g['secret']);

                return ['key' => trim((string) $g['key_id']), 'secret' => Secret::decrypt($raw, $this->envFile) ?? $raw,
                    'mode' => strtolower((string) ($g['mode'] ?? ''))];
            }
        }

        return null;
    }

    /** @return array{ok: bool, id?: string, why?: string} */
    public function razorpayOrder(array $gw, int $paise, string $receipt, array $notes): array
    {
        $ch = curl_init(self::ORDERS);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_USERPWD => $gw['key'] . ':' . $gw['secret'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => (string) json_encode(['amount' => $paise, 'currency' => 'INR', 'receipt' => $receipt, 'notes' => $notes])]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $j = is_string($body) ? json_decode($body, true) : null;

        if ($code >= 200 && $code < 300 && is_array($j) && (string) ($j['id'] ?? '') !== '') {
            return ['ok' => true, 'id' => (string) $j['id']];
        }

        return ['ok' => false, 'why' => (string) ($j['error']['description'] ?? ($err !== '' ? $err : 'HTTP ' . $code))];
    }

    public static function signatureOk(string $secret, string $orderId, string $paymentId, string $signature): bool
    {
        return $signature !== '' && hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
    }

    // ------------------------------------------------------------- the workspace

    /** The `plans` row a subscription points at, found by name (made from the website price if missing). */
    public function planRow(array $plan): string
    {
        foreach ($this->platform->all('plans') as $r) {
            if (strcasecmp((string) ($r['name'] ?? ''), (string) $plan['name']) === 0 && strtolower((string) ($r['status'] ?? '')) !== 'withdrawn') {
                return (string) $r['id'];
            }
        }

        $row = $this->platform->put('plans', ['name' => (string) $plan['name'], 'base_price' => (string) (int) $plan['monthly'],
            'per_user_price' => (string) (int) ($plan['extra'] ?? 0), 'billing_cycle' => 'monthly', 'seat_max' => (int) $plan['employees'],
            'status' => 'offered', 'features' => (string) json_encode(['modules' => []])]);

        return (string) $row['id'];
    }

    public function emailTaken(string $email): bool
    {
        foreach (array_merge($this->platform->all('users'), $this->platform->all('platform_users')) as $u) {
            if (strcasecmp((string) ($u['email'] ?? ''), $email) === 0) {
                return true;
            }
        }

        return false;
    }

    public function slugTaken(string $slug): bool
    {
        foreach ($this->platform->all('tenants') as $t) {
            if (strcasecmp((string) ($t['subdomain'] ?? ''), $slug) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make the workspace for a paid signup (idempotent: a provisioned row is returned as it is).
     *
     * @param array<string, mixed> $s the signups row
     * @return array{tenant_id: string, user: array<string, mixed>, invoice: array<string, mixed>, slug: string, token: string}
     */
    public function provision(array $s, string $paymentId, SaasPlatform $saas): array
    {
        $today = date('Y-m-d');
        $paise = static fn (mixed $v): int => (int) round(100 * (float) $v);
        $planRow = $this->platform->find('plans', (string) $s['plan_id']) ?? [];
        $plan = $this->plan((string) ($planRow['name'] ?? '')) ?? ['name' => (string) ($planRow['name'] ?? 'Plan')];
        $cycleKey = (string) $s['cycle'];
        $months = max(1, (int) (((array) $saas->declared()['cycles'])[$cycleKey] ?? 1));
        $setupP = $paise($s['setup_charge'] ?? 0);
        $subscriptionP = $paise($s['amount']) - $setupP + $paise($s['coupon_discount'] ?? 0);
        $end = (new \DateTimeImmutable($today))->modify('+' . $months . ' months')->format('Y-m-d');
        $slug = (string) ($s['subdomain'] ?? '');

        if ($slug === '' || $this->slugTaken($slug)) {
            $base = substr((string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $s['company'])), 0, 8) ?: 'company';
            $slug = $base;

            while ($this->slugTaken($slug)) {
                $slug = substr($base, 0, 7) . random_int(100, 999);
            }
        }

        $code = strtoupper($slug);

        foreach ($this->platform->all('tenants') as $t) {
            if ((string) ($t['code'] ?? '') === $code) {
                $code .= '-' . (string) $s['id'];
            }
        }

        $tenant = $this->platform->put('tenants', ['code' => $code, 'name' => (string) $s['company'], 'state' => (string) ($s['state'] ?? ''),
            'subdomain' => $slug, 'status_code' => 'active', 'onboarded_on' => $today, 'plan_id' => (string) $s['plan_id'],
            'seats_licensed' => (int) $s['seats'], 'owner_email' => (string) $s['admin_email'],
            'mrr' => sprintf('%.2f', $subscriptionP / 100 / $months)]);
        $tid = (string) $tenant['id'];
        $tStore = $this->platform->forTenant($tid);
        $tStore->all('companies') === [] && $tStore->put('companies', ['name' => (string) $s['company']]);
        $users = new Users($tStore, new Authorisation(), \SmartPRS\Platform\Identity\PasswordHasher::platformDefault());
        $made = $users->create((string) $s['admin_email'], bin2hex(random_bytes(16)), 'administrator');

        if (!$made['ok']) {
            throw new \RuntimeException('The administrator login was not created: ' . $made['why']);
        }

        $invite = $users->invite((string) $made['user']['id'], 72);
        $sub = $this->platform->put('subscriptions', ['tenant_id' => $tid, 'plan_id' => (string) $s['plan_id'], 'seats' => (int) $s['seats'],
            'companies' => (int) $s['companies'], 'cycle' => $cycleKey,
            'amount' => sprintf('%.2f', $subscriptionP / 100), 'status' => 'active', 'current_period_end' => $end, 'next_renewal' => $end]);
        $issued = count(array_filter($this->platform->all('invoices'), static fn (array $i): bool => trim((string) ($i['number'] ?? '')) !== ''))
            + count(array_filter($this->platform->all('onprem_clients'), static fn (array $c): bool => trim((string) ($c['invoice_no'] ?? '')) !== ''));
        $invoice = $this->platform->put('invoices', ['tenant_id' => $tid, 'subscription_id' => (string) $sub['id'],
            'number' => $saas->invoiceNumber($issued + 1, $today), 'amount' => sprintf('%.2f', (float) $s['amount']), 'tax' => sprintf('%.2f', (float) $s['tax']),
            'status' => 'paid', 'issued_on' => $today, 'paid_on' => $today, 'period_from' => $today, 'period_to' => $end, 'gateway' => 'razorpay',
            'notes' => 'Online signup — ' . (string) $plan['name'] . ', ' . (int) $s['seats'] . ' employees, ' . (int) $s['companies'] . ' compan'
                . ((int) $s['companies'] === 1 ? 'y' : 'ies') . ', ' . $months . ' months; setup & onboarding ' . self::rupees($setupP)
                . ((string) ($s['coupon_code'] ?? '') !== '' ? '; coupon ' . (string) $s['coupon_code'] : '')]);
        $this->platform->put('payments', ['tenant_id' => $tid, 'invoice_id' => (string) $invoice['id'],
            'amount' => sprintf('%.2f', ((float) $s['amount']) + ((float) $s['tax'])), 'gateway' => 'razorpay', 'method' => 'gateway',
            'gateway_txn_id' => $paymentId, 'status' => 'succeeded', 'paid_at' => date('Y-m-d H:i:s')]);
        $this->platform->put('signups', ['id' => (string) $s['id'], 'status' => 'provisioned', 'tenant_ref' => $tid, 'updated_at' => date('Y-m-d H:i:s')]);

        return ['tenant_id' => $tid, 'user' => $made['user'], 'invoice' => $invoice, 'slug' => $slug, 'token' => (string) $invite['token']];
    }
}
