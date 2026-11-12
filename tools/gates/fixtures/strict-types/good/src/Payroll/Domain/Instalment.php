<?php

declare(strict_types=1);

namespace SmartPRS\Payroll\Domain;

final class Instalment
{
    public function __construct(public int $paise)
    {
    }
}
