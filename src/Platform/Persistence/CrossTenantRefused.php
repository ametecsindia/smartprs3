<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

/** R-XXII-118: cross-tenant reach is refused unless positively granted. */
final class CrossTenantRefused extends \RuntimeException
{
}
