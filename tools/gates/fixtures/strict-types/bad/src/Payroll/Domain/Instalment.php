<?php

namespace SmartPRS\Payroll\Domain;

/**
 * No declare(strict_types=1). The docblock mentions declare(strict_types=1) so
 * that a text search finds one here and the gate still does not: the gate reads
 * tokens, and a declaration inside a comment declares nothing.
 */
final class Instalment
{
    public function __construct(public int $paise)
    {
    }
}
