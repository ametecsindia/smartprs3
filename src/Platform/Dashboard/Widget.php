<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Dashboard;

/**
 * A NUMBER WITH SOMETHING TO DO ABOUT IT — Part XXXII Phase 8.
 *
 * *"Every dashboard widget links to the action it implies."* The constructor
 * refuses a widget without one, which is the difference between a dashboard and
 * a wall of numbers.
 *
 * The reasoning is not aesthetic. "14 expired certificates" with no way to reach
 * the fourteen is a number somebody reads, feels bad about, and cannot act on;
 * within a month it is a number nobody reads. The action link is what keeps the
 * figure honest, because a widget whose drill-through returns eleven rows for a
 * count of fourteen is a widget you can catch — and one with no drill-through
 * never gets caught at all.
 *
 * R-XXXI-090 states the same rule for the operations console: a read-only number
 * with no next step must not be shown. One rule, two surfaces, and this class is
 * where both get it.
 */
final readonly class Widget
{
    public function __construct(
        public string $code,
        public string $title,
        public string $metric,
        public string $actionRoute,
        public string $actionLabel,
        public ?string $runbookUrl = null,
        public string $freshnessSource = '',
    ) {
        if (trim($actionRoute) === '' || trim($actionLabel) === '') {
            throw new \InvalidArgumentException(
                "Widget '{$code}' shows a number with no action. \"14 expired certificates\" with no way to "
                . 'reach the fourteen is a figure somebody reads, feels bad about and cannot act on — and '
                . 'within a month it is a figure nobody reads. Every widget links to the action it implies.'
            );
        }

        if (trim($freshnessSource) === '') {
            throw new \InvalidArgumentException(
                "Widget '{$code}' does not say when its number was computed. A stale figure that looks live "
                . 'is worse than an obviously old one, because only one of them gets questioned.'
            );
        }
    }

    /** The drill-through: the widget's own count must equal the rows behind it. */
    public function agreesWith(int $count, int $drillThroughRows): bool
    {
        return $count === $drillThroughRows;
    }
}
