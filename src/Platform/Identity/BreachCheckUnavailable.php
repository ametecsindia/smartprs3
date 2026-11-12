<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * The breach corpus could not be consulted — network, timeout, malformed range
 * response, missing local corpus file.
 *
 * It is emphatically NOT "the password is clean". PasswordPolicy catches it and
 * turns it into PolicyResult::BREACH_CHECK_UNAVAILABLE, which fails the change
 * closed by default (see BreachCheck's class comment for the reasoning). It is
 * never swallowed into a `false`.
 */
final class BreachCheckUnavailable extends \RuntimeException
{
}
