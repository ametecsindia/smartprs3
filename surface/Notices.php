<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * NOTICE BOARD: A NOTICE IS CURRENT BETWEEN ITS DATES AND PUBLISHED.
 *
 * SmartPRS 2 showed every published notice on every dashboard until somebody
 * unpublished it, so the Diwali holiday list was still up in February. Here a
 * notice carries valid_from / valid_to; `current()` is what a dashboard shows,
 * `expired()` is what the board keeps for the record. Rows come from the
 * `notices` register (module key `notices`), so add/edit/delete are the module
 * engine's.
 */
final class Notices
{
    public const COLLECTION = 'notices';
    public const PUBLISHED = 'published';

    public function __construct(private readonly RowStore $store)
    {
    }

    /** @return list<array<string, mixed>> published and inside their dates, newest posted first */
    public function current(?string $today = null): array
    {
        return $this->split($today ?? date('Y-m-d'))['current'];
    }

    /** @return list<array<string, mixed>> published but past valid_to */
    public function expired(?string $today = null): array
    {
        return $this->split($today ?? date('Y-m-d'))['expired'];
    }

    /**
     * Every live notice sorted into current / upcoming / expired / draft.
     *
     * @return array{current: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, expired: list<array<string, mixed>>, draft: list<array<string, mixed>>}
     */
    public function split(string $today): array
    {
        try {
            $rows = $this->store->all(self::COLLECTION);
        } catch (\Throwable) {
            $rows = []; // a table this installation does not have yet is an empty board
        }

        $rows = array_values(array_filter($rows, static fn (array $r): bool => !Modules::isRetired($r)));
        usort($rows, static fn (array $a, array $b): int
            => strcmp((string) ($b['posted_on'] ?? $b['created_at'] ?? ''), (string) ($a['posted_on'] ?? $a['created_at'] ?? '')));
        $out = ['current' => [], 'upcoming' => [], 'expired' => [], 'draft' => []];

        foreach ($rows as $r) {
            $out[self::stateOf($r, $today)][] = $r;
        }

        return $out;
    }

    /** current | upcoming | expired | draft */
    public static function stateOf(array $notice, string $today): string
    {
        if (strcasecmp(trim((string) ($notice['status'] ?? '')), self::PUBLISHED) !== 0) {
            return 'draft';
        }

        $from = trim((string) ($notice['valid_from'] ?? $notice['posted_on'] ?? ''));
        $to = trim((string) ($notice['valid_to'] ?? ''));

        if ($to !== '' && $to !== '0000-00-00' && $to < $today) {
            return 'expired';
        }

        if ($from !== '' && $from !== '0000-00-00' && $from > $today) {
            return 'upcoming';
        }

        return 'current';
    }
}
