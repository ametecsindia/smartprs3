<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * MY SUBSCRIPTION and UPDATES & LICENCE, read-only.
 *
 * SmartPRS 2 had two screens: the SaaS plan (seats, cycle, renewal) and the
 * on-premise licence (edition, AMC, activation). The ported `subscriptions`,
 * `plans`, `licences` and `licence_events` tables hold the rows; this class
 * only picks the row that applies today and says how long it has left.
 * Issuing, activating or renewing is a commercial act done outside the
 * product, so nothing here writes.
 */
final class Licensing
{
    /**
     * The subscription in force: the latest row that is not cancelled, with
     * the plan's name and how many days remain on the period.
     *
     * @param list<array<string, mixed>> $subscriptions
     * @param list<array<string, mixed>> $plans
     * @return array<string, mixed>|null
     */
    public static function subscription(array $subscriptions, array $plans, string $today, int $seatsUsed): ?array
    {
        usort($subscriptions, static fn (array $a, array $b): int
            => strcmp((string) ($b['current_period_end'] ?? ''), (string) ($a['current_period_end'] ?? '')));
        $row = $subscriptions[0] ?? null;

        if ($row === null) {
            return null;
        }

        $plan = null;

        foreach ($plans as $p) {
            if ((string) ($p['id'] ?? '') === (string) ($row['plan_id'] ?? '')) {
                $plan = $p;
            }
        }

        $end = (string) ($row['current_period_end'] ?? '');
        $seats = (int) ($row['seats'] ?? 0);

        return [
            'plan' => (string) ($plan['name'] ?? ('Plan #' . (string) ($row['plan_id'] ?? '?'))),
            'status' => (string) ($row['status'] ?? ''),
            'cycle' => (string) ($row['cycle'] ?? ''),
            'seats' => $seats,
            'seats_used' => $seatsUsed,
            'seats_left' => $seats > 0 ? $seats - $seatsUsed : null,
            'companies' => (int) ($row['companies'] ?? 0),
            'period_end' => $end,
            'next_renewal' => (string) ($row['next_renewal'] ?? ''),
            'days_left' => $end === '' ? null : self::daysBetween($today, $end),
            'amount' => (string) ($row['amount'] ?? ''),
        ];
    }

    /**
     * The licence that applies: the most recently activated row, with the AMC
     * days left. `key_last4` is all a screen ever shows of a key.
     *
     * @param list<array<string, mixed>> $licences
     * @return array<string, mixed>|null
     */
    public static function licence(array $licences, string $today): ?array
    {
        usort($licences, static fn (array $a, array $b): int
            => strcmp((string) ($b['activated_at'] ?? ''), (string) ($a['activated_at'] ?? '')));
        $row = $licences[0] ?? null;

        if ($row === null) {
            return null;
        }

        $amc = (string) ($row['amc_expires_on'] ?? '');

        return [
            'id' => (string) ($row['id'] ?? ''),
            'edition' => (string) ($row['edition'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'key_last4' => (string) ($row['key_last4'] ?? ''),
            'activated_at' => (string) ($row['activated_at'] ?? ''),
            'server_name' => (string) ($row['server_name'] ?? ''),
            'amc_expires_on' => $amc,
            'amc_days_left' => $amc === '' ? null : self::daysBetween($today, $amc),
            'expiry_mode' => (string) ($row['expiry_mode'] ?? ''),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
        ];
    }

    /** Whole days from one date to another; negative once it has passed. */
    /**
     * CLOUD CAP (SmartPRS 2 SubscriptionService::seatUsage / companyUsage): the largest `seats` or
     * `companies` on a live subscription; null = no limit on record (no subscription, or 0).
     *
     * @param list<array<string, mixed>> $subscriptions
     * @param list<string> $live statuses that count (config/plans.json subscription_live)
     */
    public static function cloudLimit(array $subscriptions, array $live, string $field): ?int
    {
        $cap = null;

        foreach ($subscriptions as $s) {
            if (in_array((string) ($s['status'] ?? ''), $live, true) && (int) ($s[$field] ?? 0) > 0) {
                $cap = max($cap ?? 0, (int) $s[$field]);
            }
        }

        return $cap;
    }

    /**
     * SmartPRS 2's lifecycle: active up to the period end, grace for $graceDays after it,
     * then locked; suspended or cancelled is locked at once. none = no subscription on record.
     *
     * @param list<array<string, mixed>> $subscriptions
     */
    public static function lifecycle(array $subscriptions, string $today, int $graceDays): string
    {
        $sub = self::latest($subscriptions);
        $end = (string) ($sub['current_period_end'] ?? '');

        if ($sub === null || $end === '') {
            return 'none';
        }

        if (in_array((string) ($sub['status'] ?? ''), ['cancelled', 'suspended'], true)) {
            return 'locked';
        }

        if ($today <= $end) {
            return 'active';
        }

        return $today <= date('Y-m-d', (int) strtotime($end . ' +' . $graceDays . ' days')) ? 'grace' : 'locked';
    }

    /**
     * Which expiry alert is due today, if any: expiry-N on each of $days before the end
     * (SmartPRS 2: 15, 7, 3, 1, 0), and grace every day of the grace period.
     *
     * @param list<array<string, mixed>> $subscriptions
     * @param list<int> $days
     */
    public static function expiryAlert(array $subscriptions, string $today, int $graceDays, array $days): ?string
    {
        $state = self::lifecycle($subscriptions, $today, $graceDays);

        if ($state === 'grace') {
            return 'grace';
        }

        if ($state !== 'active') {
            return null;
        }

        $left = self::daysBetween($today, (string) self::latest($subscriptions)['current_period_end']);

        return in_array($left, array_map('intval', $days), true) ? 'expiry-' . $left : null;
    }

    /** @param list<array<string, mixed>> $subscriptions */
    private static function latest(array $subscriptions): ?array
    {
        usort($subscriptions, static fn (array $a, array $b): int
            => strcmp((string) ($b['current_period_end'] ?? ''), (string) ($a['current_period_end'] ?? '')));

        return $subscriptions[0] ?? null;
    }

    public static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable(substr($from, 0, 10));
        $b = new \DateTimeImmutable(substr($to, 0, 10));

        return (int) $a->diff($b)->format('%r%a');
    }
}
