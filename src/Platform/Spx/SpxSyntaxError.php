<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

/**
 * A lexical or grammatical error in SPX source. Publish-time only: an author is
 * looking at the editor when this is raised, so it names the position.
 *
 * Part VII §5.2/§5.3. There is no partial parse and no error recovery — a
 * source that does not parse is not a rule, and half a rule is worse than none.
 *
 * The position fields are `sourceLine`/`sourceColumn`, not `line`/`column`:
 * \Exception already owns `$line`, and that one is a line in this PHP file.
 */
final class SpxSyntaxError extends \DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
    ) {
        parent::__construct(sprintf('%s at line %d, column %d', $reason, $sourceLine, $sourceColumn));
    }
}
