<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Types;

/**
 * The fourteen base types of Part VII §5.4.1 plus the three Part VIII §5.1
 * extensions, which are refinements and a parametric — no new base.
 *
 * `Days`, `Hours` and `Minutes` are separate cases on purpose (R-VII-013).
 * SmartPRS 2 measures absence in working days and values it in calendar days
 * with nothing objecting (PayrollGenController.php:1268-1284); here the two
 * cannot meet without an explicit, named, traced conversion.
 */
enum TypeKind: string
{
    case Number = 'Number';
    case Money = 'Money';
    case Days = 'Days';
    case Hours = 'Hours';
    case Minutes = 'Minutes';
    case Duration = 'Duration';
    case Date = 'Date';
    case DateTime = 'DateTime';
    case Time = 'Time';
    case Boolean = 'Boolean';
    case Text = 'Text';
    case Code = 'Code';
    case ListOf = 'List';
    case Null = 'Null';

    // Part VIII §5.1 — checked at publish, erased at runtime.
    case DayFraction = 'DayFraction';   // refinement of Days, constrained to [0,1]
    case Percentage = 'Percentage';     // refinement of Number, tagged percent
    case Rate = 'Rate';                 // Rate<Money/U>, U in {Days, Hours, Minutes}
}
