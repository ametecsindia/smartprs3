<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Organisation;

/**
 * One node of the spine S3 typed tree, as a value.
 *
 * `org_units` carries both an adjacency (`parent_id`) and a materialised `path`
 * of ancestor ids, self included, in the shape `/1/17/230/`. Adjacency is the
 * source of truth; the path is a derived index. Everything here reads the path,
 * because containment then costs one prefix comparison instead of a recursion,
 * and every unit-based data scope is a containment question.
 *
 * WHAT THE PATH COSTS WHEN A UNIT MOVES. Re-parenting one unit rewrites `path`
 * (and `depth`) for that unit and every descendant — one indexed
 * `UPDATE … WHERE path LIKE '<old prefix>%'` touching as many rows as the
 * subtree holds, in the same transaction as the `parent_id` change, with the
 * subtree locked. Reads pay nothing; the move pays everything. That is the right
 * way round: a department is re-parented a handful of times a year and its
 * members' scopes are resolved on every request. The failure mode is a rewrite
 * that half-succeeds, which is why it must be one transaction and why adjacency
 * stays authoritative — a path can always be rebuilt from `parent_id`, never the
 * other way round.
 *
 * ponytail: no name history on the unit yet. V.6.3 wants "the branch name as it
 * stood on 15 May", which is a dated child table of this one; the dated
 * ASSIGNMENT is what this brick is for, and a renamed unit does not move
 * anybody. Add it with the People module, and never by making `name` mutable.
 */
final readonly class OrgUnit
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $path,
        public int $depth,
        public string $code,
        public string $name,
    ) {
        if ($path === '' || $path[0] !== '/' || !str_ends_with($path, '/')) {
            throw new \InvalidArgumentException(
                "Org unit {$id} has path '{$path}'; the materialised path is slash-delimited "
                . 'on both sides so that a prefix comparison cannot match half an id.'
            );
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            $row['parent_id'] === null ? null : (string) $row['parent_id'],
            (string) $row['path'],
            (int) $row['depth'],
            (string) $row['code'],
            (string) $row['name'],
        );
    }

    /**
     * Ancestor ids, root first, excluding this unit. Read from the path, so it
     * costs no query at all.
     *
     * @return list<string>
     */
    public function ancestorIds(): array
    {
        $ids = array_values(array_filter(explode('/', $this->path), static fn (string $s): bool => $s !== ''));

        return array_slice($ids, 0, -1);
    }

    /** At or below $unitId. A unit is within itself; every scope type assumes it. */
    public function isWithin(self|string $unit): bool
    {
        $id = $unit instanceof self ? $unit->id : $unit;

        return str_contains($this->path, '/' . $id . '/');
    }

    /** The prefix every descendant's path starts with, this unit included. */
    public function subtreePrefix(): string
    {
        return $this->path;
    }
}
