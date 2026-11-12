<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * A breach check that checks nothing, and says so.
 *
 * It exists so that "we are not doing breach checking here" is a **named,
 * greppable, injectable decision** rather than the absence of a call. SmartPRS 2
 * has no breach check anywhere; the difference between that and this is that
 * this one appears in the dependency wiring, in the test that asserts which
 * check a given deployment got, and in the answer to "is this installation
 * breach-checking?".
 *
 * It reports every password as clean. Wiring it into a deployment whose auth
 * policy has `breach_check_enabled = true` is a configuration error the
 * installer health check (R-XXII-232) should catch, not something this class
 * can detect from here.
 *
 * ponytail: the real production implementation is an HTTP range client against
 * haveibeenpwned, which is ~15 lines on top of BreachCheck and needs egress
 * this build environment does not have. LocalListBreachCheck is what makes the
 * mechanism testable meanwhile.
 */
final class NullBreachCheck extends BreachCheck
{
    /** @return iterable<string> always empty — nothing is known to be breached */
    protected function suffixesFor(string $sha1Prefix5): iterable
    {
        return [];
    }
}
