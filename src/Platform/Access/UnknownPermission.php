<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * A permission code that is neither an Ametecs-authored catalogue entry nor
 * mintable from a published artefact in a reserved namespace (R-XXII-010b).
 *
 * At request time this is a deployment defect, not a user error: an operation
 * binding names a code the release does not carry. R-XXII-094 requires 403 plus
 * an immediate alert. It is never a reason to allow.
 */
final class UnknownPermission extends \DomainException
{
}
