<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * One user, two delegations in force on the same day (Part IX §IX.5.5).
 *
 * The same stance as Support\AmbiguousConfiguration takes about config versions
 * — "ambiguity is a configuration error, never a silent coin-toss" — applied to
 * delegations, which are user data rather than configuration and so need their
 * own exception and their own message. Which of the two holds the approval is
 * not a question the engine may answer by picking whichever the array happened
 * to list first.
 */
final class AmbiguousDelegation extends \RuntimeException
{
}
