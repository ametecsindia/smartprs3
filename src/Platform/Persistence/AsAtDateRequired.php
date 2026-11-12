<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

/**
 * A read was attempted against an effective-dated table without an as-at date.
 *
 * A programming error, like TenancyViolation, and for the same reason: the only
 * alternatives are to invent a date or to return every version of every row, and
 * both are wrong quietly. Defaulting to "today" is how SmartPRS 2 lets a
 * recompute of a March payslip resolve October's policy (spine S4); returning
 * the whole history is how "the assignment in force on 15 May" becomes three
 * rows and the caller takes the last one by id.
 */
final class AsAtDateRequired extends \LogicException
{
}
