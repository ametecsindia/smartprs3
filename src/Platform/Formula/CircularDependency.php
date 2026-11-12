<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

/**
 * Gate G5 (Part VIII §9.5, R-VIII-034): publication refused, cycle named.
 *
 * The message is the point. "Circular dependency detected" tells an
 * administrator nothing they can act on; the cycle in traversal order, with the
 * reference that created each edge, tells them which line to change. §7.2 shows
 * the intended shape and this reproduces it.
 */
final class CircularDependency extends \RuntimeException
{
    /**
     * @param list<string> $cycle  nodes in traversal order, first repeated last
     */
    private function __construct(
        public readonly array $cycle,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $cycle
     * @param array<string, string> $labels
     * @param array<string, array<string, string>> $origins  from => (to => why)
     */
    public static function of(array $cycle, array $labels = [], array $origins = []): self
    {
        $lines = ['Circular dependency:', ''];
        $last = count($cycle) - 1;

        foreach ($cycle as $index => $node) {
            $label = $labels[$node] ?? $node;

            if ($index === $last) {
                $lines[] = '    ' . $label . '   ← cycle closes here';

                break;
            }

            $next = $cycle[$index + 1];
            $why = $origins[$node][$next] ?? 'reference';
            $lines[] = '    ' . $label . '   --(' . $why . ')-->';
        }

        return new self($cycle, implode("\n", $lines));
    }

    /** The cycle as one line, for a log or an assertion. */
    public function path(): string
    {
        return implode(' → ', $this->cycle);
    }
}
