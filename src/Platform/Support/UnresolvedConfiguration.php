<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * V.5.3: "as_of(date) returns exactly zero or one row. Zero is a domain error
 * with a named cause, never a silent default."
 */
final class UnresolvedConfiguration extends \RuntimeException
{
}
