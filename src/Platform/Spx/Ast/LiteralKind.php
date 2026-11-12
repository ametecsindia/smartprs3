<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/** The literal forms of Part VII §5.2. `Duration` covers the `d h m s` suffixes. */
enum LiteralKind: string
{
    case Number = 'number';
    case Text = 'text';
    case Date = 'date';
    case Time = 'time';
    case Duration = 'duration';
    case Money = 'money';
    case Boolean = 'boolean';
    case Null = 'null';
}
