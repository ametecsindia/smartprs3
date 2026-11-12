<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * A data scope could not be evaluated: a scope type with no resolver in this
 * build, or a reporting cycle terminating the walk (R-XXII-044).
 *
 * R-XXII-094 maps every one of these to a denial with an alert. There is no
 * catch of this anywhere that returns an allow (R-XXII-095) — AccessDecider
 * catches it, records it in the trace, and denies.
 */
final class ScopeResolutionFailed extends \DomainException
{
}
