<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

/**
 * A programming error, not a user error: code asked the persistence layer to do
 * something tenancy forbids. R-XXII-083 — "rejected as a programming error, not
 * silently corrected".
 */
final class TenancyViolation extends \LogicException
{
}
