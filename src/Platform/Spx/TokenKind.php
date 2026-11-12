<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

/**
 * The token kinds of Part VII §5.2. This is the whole lexical vocabulary: there
 * is no kind for a statement terminator, an assignment, a block or a string
 * interpolation, because the grammar has no production that could use one.
 */
enum TokenKind
{
    case Number;        // 12, 0.25
    case StringLit;     // 'Operations'  ('' is an escaped quote)
    case DateLit;       // #2026-08-12#
    case TimeLit;       // #09:30#, #09:30:15#
    case DurationLit;   // 0.5d, 8.5h, 90m, 45s
    case MoneyLit;      // ₹1,50,000.00
    case Identifier;    // employee, MIN, basic_pct  (case-sensitive)
    case Keyword;       // AND OR NOT IN … (case-insensitive, stored upper-cased)
    case Operator;      // = <> < <= > >= + - * / %
    case Punctuation;   // ( ) [ ] { } , .
    case End;           // end of source
}
