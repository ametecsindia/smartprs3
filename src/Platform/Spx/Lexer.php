<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

/**
 * SPX lexer — Part VII §5.2, verbatim.
 *
 * A hand-written scanner. No parser generator: the lexical grammar is fifteen
 * productions and a generator would be a build dependency for less code than
 * this file (and `composer install` is not available here — see README).
 *
 * Every token carries its line and column, because the only person who ever
 * reads a parse error is an author with the expression open in front of them.
 * Columns count characters, not bytes, so the '₹' in a money literal does not
 * push the reported column three places to the right.
 */
final class Lexer
{
    /** Reserved, case-insensitive (Part VII §5.2). Function names are not keywords. */
    public const KEYWORDS = [
        'AND', 'OR', 'NOT', 'IN', 'BETWEEN', 'CONTAINS', 'MATCHES',
        'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'TRUE', 'FALSE', 'NULL',
    ];

    /** Part VII §5.2 and §7.7: budgets that can be checked statically are checked at publish. */
    public const MAX_SOURCE_CHARS = 8000;
    public const MAX_STRING_CHARS = 512;
    public const MAX_NUMBER_SCALE = 9;

    /** Longest first, so '<=' is never read as '<' then '='. */
    private const OPERATORS = ['<>', '<=', '>=', '=', '<', '>', '+', '-', '*', '/', '%'];

    private const PUNCTUATION = ['(', ')', '[', ']', '{', '}', ',', '.'];

    private int $offset = 0;
    private int $line = 1;
    private int $column = 1;

    private function __construct(private readonly string $source)
    {
    }

    /**
     * @return list<Token>  always terminated by a TokenKind::End token
     *
     * @throws SpxSyntaxError
     */
    public static function tokenise(string $source): array
    {
        if (mb_strlen($source, 'UTF-8') > self::MAX_SOURCE_CHARS) {
            throw new SpxSyntaxError(
                'Expression exceeds the maximum source length of ' . self::MAX_SOURCE_CHARS . ' characters',
                1,
                1,
            );
        }

        return (new self($source))->run();
    }

    /** @return list<Token> */
    private function run(): array
    {
        $tokens = [];

        while (true) {
            $this->skipInsignificant();

            if ($this->offset >= strlen($this->source)) {
                $tokens[] = new Token(TokenKind::End, '', $this->line, $this->column);

                return $tokens;
            }

            $tokens[] = $this->next($tokens[count($tokens) - 1] ?? null);
        }
    }

    private function next(?Token $previous): Token
    {
        $line = $this->line;
        $column = $this->column;
        $char = $this->source[$this->offset];

        return match (true) {
            $char === "'" => $this->stringLiteral($line, $column),
            $char === '#' => $this->hashLiteral($line, $column),
            ctype_digit($char) => $this->numberLiteral($line, $column),
            $this->startsWith('₹') => $this->moneyLiteral($line, $column),
            ctype_alpha($char) => $this->word($line, $column, $previous?->is(TokenKind::Punctuation, '.') ?? false),
            default => $this->symbol($line, $column),
        };
    }

    /** Whitespace is insignificant; `--` runs to the end of the line. */
    private function skipInsignificant(): void
    {
        $length = strlen($this->source);

        while ($this->offset < $length) {
            $char = $this->source[$this->offset];

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $this->advance();

                continue;
            }

            if ($char === '-' && ($this->source[$this->offset + 1] ?? '') === '-') {
                while ($this->offset < $length && $this->source[$this->offset] !== "\n") {
                    $this->advance();
                }

                continue;
            }

            return;
        }
    }

    private function stringLiteral(int $line, int $column): Token
    {
        $this->advance(); // opening quote
        $value = '';
        $length = strlen($this->source);

        while (true) {
            if ($this->offset >= $length) {
                throw new SpxSyntaxError('Unterminated string literal', $line, $column);
            }

            $char = $this->source[$this->offset];

            if ($char === "'") {
                $this->advance();

                if (($this->source[$this->offset] ?? '') !== "'") {
                    break;
                }

                // '' inside a string is one literal quote.
                $value .= "'";
                $this->advance();

                continue;
            }

            if ($char === "\n") {
                throw new SpxSyntaxError('Unterminated string literal', $line, $column);
            }

            $value .= $char;
            $this->advance();
        }

        if (mb_strlen($value, 'UTF-8') > self::MAX_STRING_CHARS) {
            throw new SpxSyntaxError(
                'String literal exceeds ' . self::MAX_STRING_CHARS . ' characters',
                $line,
                $column,
            );
        }

        return new Token(TokenKind::StringLit, $value, $line, $column);
    }

    /** `#…#` is a date or a time; which one is decided by the shape inside. */
    private function hashLiteral(int $line, int $column): Token
    {
        $this->advance();
        $body = '';
        $length = strlen($this->source);

        while ($this->offset < $length && $this->source[$this->offset] !== '#') {
            if ($this->source[$this->offset] === "\n") {
                break;
            }

            $body .= $this->source[$this->offset];
            $this->advance();
        }

        if ($this->offset >= $length || $this->source[$this->offset] !== '#') {
            throw new SpxSyntaxError('Unterminated date or time literal', $line, $column);
        }

        $this->advance();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $body) === 1) {
            if (!self::isRealDate($body)) {
                throw new SpxSyntaxError("Not a calendar date: #{$body}#", $line, $column);
            }

            return new Token(TokenKind::DateLit, $body, $line, $column);
        }

        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $body, $m) === 1) {
            if ((int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? '0') > 59) {
                throw new SpxSyntaxError("Not a wall-clock time: #{$body}#", $line, $column);
            }

            return new Token(TokenKind::TimeLit, $m[1] . ':' . $m[2] . ':' . ($m[3] ?? '00'), $line, $column);
        }

        throw new SpxSyntaxError(
            "Malformed date or time literal '#{$body}#'; expected #YYYY-MM-DD#, #HH:MM# or #HH:MM:SS#",
            $line,
            $column,
        );
    }

    /**
     * `12`, `0.25`, and the duration literals `0.5d 8.5h 90m 45s`.
     * Decimal only: no exponent, no hex, no octal, no digit separators.
     */
    private function numberLiteral(int $line, int $column): Token
    {
        $digits = '';
        $length = strlen($this->source);

        while ($this->offset < $length && ctype_digit($this->source[$this->offset])) {
            $digits .= $this->source[$this->offset];
            $this->advance();
        }

        if ($this->offset < $length
            && $this->source[$this->offset] === '.'
            && ctype_digit($this->source[$this->offset + 1] ?? '')
        ) {
            $digits .= '.';
            $this->advance();

            while ($this->offset < $length && ctype_digit($this->source[$this->offset])) {
                $digits .= $this->source[$this->offset];
                $this->advance();
            }
        }

        $scale = strlen(substr(strrchr($digits, '.') ?: '.', 1));

        if ($scale > self::MAX_NUMBER_SCALE) {
            throw new SpxSyntaxError(
                "Number literal '{$digits}' exceeds scale " . self::MAX_NUMBER_SCALE,
                $line,
                $column,
            );
        }

        $unit = $this->source[$this->offset] ?? '';

        if (in_array($unit, ['d', 'h', 'm', 's'], true)) {
            $after = $this->source[$this->offset + 1] ?? '';

            if ($after === '' || !(ctype_alnum($after) || $after === '_')) {
                $this->advance();

                return new Token(TokenKind::DurationLit, $digits . $unit, $line, $column);
            }
        }

        if ($this->offset < $length && (ctype_alpha($this->source[$this->offset]) || $this->source[$this->offset] === '_')) {
            throw new SpxSyntaxError(
                "Unexpected '{$this->source[$this->offset]}' immediately after the number '{$digits}'",
                $this->line,
                $this->column,
            );
        }

        return new Token(TokenKind::Number, $digits, $line, $column);
    }

    /** `₹1,50,000.00` — grouping commas are stripped; the paise are exact. */
    private function moneyLiteral(int $line, int $column): Token
    {
        $this->advanceBy(strlen('₹')); // one character, three bytes, one column
        $raw = '';
        $length = strlen($this->source);

        while ($this->offset < $length
            && (ctype_digit($this->source[$this->offset]) || $this->source[$this->offset] === ',')
        ) {
            $raw .= $this->source[$this->offset];
            $this->advance();
        }

        if ($this->offset < $length
            && $this->source[$this->offset] === '.'
            && ctype_digit($this->source[$this->offset + 1] ?? '')
        ) {
            $raw .= '.';
            $this->advance();

            while ($this->offset < $length && ctype_digit($this->source[$this->offset])) {
                $raw .= $this->source[$this->offset];
                $this->advance();
            }
        }

        $value = str_replace(',', '', $raw);

        if (preg_match('/^\d+(\.\d{2})?$/', $value) !== 1) {
            throw new SpxSyntaxError(
                "Malformed money literal '₹{$raw}'; expected ₹1,50,000.00 with exactly two paise digits",
                $line,
                $column,
            );
        }

        return new Token(TokenKind::MoneyLit, $value, $line, $column);
    }

    /**
     * An identifier, or a keyword if it spells one (case-insensitively).
     *
     * Immediately after a `.` a keyword spelling is a member name, not a
     * keyword: §5.7 declares `period.end` and `shift.end` while §5.2 reserves
     * END, and both have to be true at once. This is the only context-sensitive
     * decision in the lexer, and it is one token wide.
     */
    private function word(int $line, int $column, bool $afterDot = false): Token
    {
        $text = '';
        $length = strlen($this->source);

        while ($this->offset < $length
            && (ctype_alnum($this->source[$this->offset]) || $this->source[$this->offset] === '_')
        ) {
            $text .= $this->source[$this->offset];
            $this->advance();
        }

        $upper = strtoupper($text);

        return !$afterDot && in_array($upper, self::KEYWORDS, true)
            ? new Token(TokenKind::Keyword, $upper, $line, $column)
            : new Token(TokenKind::Identifier, $text, $line, $column);
    }

    private function symbol(int $line, int $column): Token
    {
        foreach (self::OPERATORS as $operator) {
            if ($this->startsWith($operator)) {
                $this->advanceBy(strlen($operator));

                return new Token(TokenKind::Operator, $operator, $line, $column);
            }
        }

        $char = $this->source[$this->offset];

        if (in_array($char, self::PUNCTUATION, true)) {
            $this->advance();

            return new Token(TokenKind::Punctuation, $char, $line, $column);
        }

        throw new SpxSyntaxError("Unexpected character '" . $this->char() . "'", $line, $column);
    }

    private function startsWith(string $needle): bool
    {
        return substr($this->source, $this->offset, strlen($needle)) === $needle;
    }

    /** The whole character at the cursor, multi-byte included, for error text. */
    private function char(): string
    {
        $bytes = 1;

        while (($this->source[$this->offset + $bytes] ?? '') !== ''
            && (ord($this->source[$this->offset + $bytes]) & 0xC0) === 0x80
        ) {
            $bytes++;
        }

        return substr($this->source, $this->offset, $bytes);
    }

    /** One byte. A UTF-8 continuation byte does not advance the column. */
    private function advance(): void
    {
        $char = $this->source[$this->offset] ?? '';
        $this->offset++;

        if ($char === "\n") {
            $this->line++;
            $this->column = 1;

            return;
        }

        if ((ord($char) & 0xC0) !== 0x80) {
            $this->column++;
        }
    }

    private function advanceBy(int $bytes): void
    {
        for ($i = 0; $i < $bytes; $i++) {
            $this->advance();
        }
    }

    private static function isRealDate(string $ymd): bool
    {
        [$y, $m, $d] = array_map(intval(...), explode('-', $ymd));

        return checkdate($m, $d, $y);
    }
}
