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
    public static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable(substr($from, 0, 10));
        $b = new \DateTimeImmutable(substr($to, 0, 10));

        return (int) $a->diff($b)->format('%r%a');
    }
}
