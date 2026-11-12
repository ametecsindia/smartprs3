<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * A STATUTE THAT APPLIES AND CANNOT BE COMPUTED — Part XVII R-XVII-118.
 *
 * This exception exists so that the alternative cannot happen. Every path in
 * SmartPRS 2 that cannot resolve a statutory value returns zero, and a zero
 * deduction that should have been ₹1,800 is indistinguishable on the payslip
 * from a correct ₹0. The employer discovers it at assessment, with interest.
 *
 * A refusal stops one payroll run. A silent zero ships, and keeps shipping.
 */
final class StatutoryUnresolved extends \RuntimeException
{
}
