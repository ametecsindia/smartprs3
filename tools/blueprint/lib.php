<?php

declare(strict_types=1);

/**
 * Reading the blueprint's worked rules out of the markdown.
 *
 * Separate from the CLI so tests/blueprint_rules_test.php runs the same
 * extraction in the ordinary suite: a check that lives only in a script someone
 * has to remember to run is a check that stops running.
 */

/**
 * Every worked rule in the documents.
 *
 * A rule is a `### <CODE> · <title>` heading followed by fenced blocks; the
 * expressions are the lines under `-- WHEN` and `-- EXCEPT`. `-- THEN` is the
 * action list, which is not SPX and is not checked here.
 *
 * @return list<array{code:string,title:string,doc:string,line:int,clause:string,source:string}>
 */
function worked_rules(string $docs): array
{
    $out = [];

    foreach (glob($docs . '/*.md') ?: [] as $path) {
        $lines = explode("\n", (string) file_get_contents($path));
        $doc = basename($path);
        $code = null;
        $title = '';
        $headingLine = 0;
        $fenced = false;
        $clause = null;
        $buffer = [];
        $bufferLine = 0;

        $flush = static function () use (&$out, &$buffer, &$clause, &$code, &$title, &$doc, &$bufferLine): void {
            // PHPStan infers $clause/$code as always-null here because it does
            // not track the by-ref mutations the enclosing loop makes between
            // calls to this closure. They are really string|null.
            /** @var string|null $clause */
            /** @var string|null $code */
            $source = trim(implode("\n", $buffer));
            $buffer = [];
            if ($source === '' || $clause === null || $code === null) {
                return;
            }
            $out[] = [
                'code' => $code,
                'title' => $title,
                'doc' => $doc,
                'line' => $bufferLine,
                'clause' => $clause,
                'source' => $source,
            ];
        };

        foreach ($lines as $i => $raw) {
            $line = rtrim($raw, "\r");

            if (preg_match('/^### ((?:RL|OT)-[A-Z]{2,4}-\d{3}[A-Za-z]?)\s*(?:·\s*(.*))?$/', $line, $m) === 1) {
                $flush();
                $clause = null;
                $code = $m[1];
                $title = trim($m[2] ?? '');
                $headingLine = $i + 1;
                continue;
            }

            // A new section of any other kind ends the current rule.
            if ($code !== null && preg_match('/^#{1,3} /', $line) === 1) {
                $flush();
                $clause = null;
                $code = null;
                continue;
            }

            if ($code === null) {
                continue;
            }

            if (str_starts_with($line, '```')) {
                $flush();
                $clause = null;
                $fenced = !$fenced;
                continue;
            }

            if (!$fenced) {
                continue;
            }

            if (preg_match('/^--\s*(WHEN|EXCEPT|THEN)\b/i', trim($line), $m) === 1) {
                $flush();
                $clause = strtoupper($m[1]);
                $bufferLine = $i + 2;
                continue;
            }

            if ($clause === 'WHEN' || $clause === 'EXCEPT') {
                // A comment line inside a clause is prose, not expression.
                if (str_starts_with(ltrim($line), '--')) {
                    continue;
                }
                $buffer[] = $line;
            }
        }

        $flush();
    }

    return $out;
}

/**
 * Every worked FORMULA in the documents (Part VIII §11, and any other part that
 * uses the same shape).
 *
 * A formula is a `#### FL-nn · `F-CODE` — title` heading followed by a ```spx
 * fenced block holding the expression. Everything else in the section — the
 * metadata block, the worked example in Indian notation — is prose about it.
 *
 * @return list<array{code:string,title:string,doc:string,line:int,clause:string,source:string}>
 */
function worked_formulas(string $docs): array
{
    $out = [];

    foreach (glob($docs . '/*.md') ?: [] as $path) {
        $lines = explode("\n", (string) file_get_contents($path));
        $doc = basename($path);
        $code = null;
        $title = '';
        $inSpx = false;
        $buffer = [];
        $bufferLine = 0;
        $seen = 0;

        foreach ($lines as $i => $raw) {
            $line = rtrim($raw, "\r");

            if (preg_match('/^#{3,5} (FL-\d+[a-z]?)\s*·\s*`([^`]+)`\s*(?:—\s*(.*))?$/u', $line, $m) === 1) {
                $code = $m[2];
                $title = trim($m[3] ?? '');
                $seen = 0;
                continue;
            }

            if ($code !== null && preg_match('/^#{1,4} /', $line) === 1) {
                $code = null;
                continue;
            }

            if ($code === null) {
                continue;
            }

            if (str_starts_with($line, '```')) {
                if ($inSpx) {
                    $source = trim(implode("\n", $buffer));
                    $buffer = [];
                    $inSpx = false;

                    if ($source !== '') {
                        $seen++;
                        $out[] = [
                            'code' => $code,
                            'title' => $title,
                            'doc' => $doc,
                            'line' => $bufferLine,
                            'clause' => $seen === 1 ? 'SPX' : 'SPX #' . $seen,
                            'source' => $source,
                        ];
                    }
                } elseif (trim($line) === '```spx') {
                    $inSpx = true;
                    $bufferLine = $i + 2;
                }

                continue;
            }

            if ($inSpx) {
                $buffer[] = $line;
            }
        }
    }

    return $out;
}

/**
 * The `result_type` each worked formula declares in its metadata block.
 *
 * Part VIII §11 prints it beside every formula — "result_type  Money (INR)",
 * "result_type  Rate<Money/Hours> (INR/h)" — so `formula.value('CODE')` can be
 * typed from the document itself rather than from a second copy of the same
 * facts kept somewhere else.
 *
 * @return array<string, string> formula code => the declared type, as written
 */
function worked_formula_result_types(string $docs): array
{
    $types = [];

    foreach (glob($docs . '/*.md') ?: [] as $path) {
        $lines = explode("\n", (string) file_get_contents($path));
        $code = null;

        foreach ($lines as $line) {
            if (preg_match('/^#{3,5} FL-\d+[a-z]?\s*·\s*`([^`]+)`/u', $line, $m) === 1) {
                $code = $m[1];

                continue;
            }

            if ($code === null) {
                continue;
            }

            if (preg_match('/\bresult_type\s+(.+?)(?:\s{2,}|$)/', $line, $m) === 1) {
                // "Money (INR)" → "Money"; "Rate<Money/Hours> (INR/h)" → the rate.
                $types[$code] = trim(preg_replace('/\s*\(.*$/', '', $m[1]) ?? $m[1]);
                $code = null;
            }
        }
    }

    return $types;
}

/**
 * Turn a declared type as the document writes it into an SPX Type.
 *
 * Deliberately narrow: it understands exactly the forms §4.1 permits for
 * `result_type` and refuses anything else, rather than guessing. A type the
 * document writes that this cannot read is a finding about the document.
 */
function spx_type_from_declaration(string $declared): ?\SmartPRS\Platform\Spx\Types\Type
{
    $t = \SmartPRS\Platform\Spx\Types\Type::class;

    if (preg_match('/^Rate<Money\/(Days|Hours|Minutes)>$/', $declared, $m) === 1) {
        return $t::rate(constant(\SmartPRS\Platform\Spx\Types\TypeKind::class . '::' . $m[1]));
    }

    return match ($declared) {
        'Money' => $t::money(),
        'Number' => $t::number(),
        'Percentage' => $t::percentage(),
        'Days' => $t::days(),
        'Hours' => $t::hours(),
        'Minutes' => $t::minutes(),
        'DayFraction' => $t::dayFraction(),
        'List<Money>' => $t::listOf($t::money()),
        default => null,
    };
}

/**
 * The symbol table the worked artefacts compile against.
 *
 * One path, used by both the CLI and the suite, so the two cannot disagree
 * about what "compiles" means. Types `formula.value('CODE')` from the
 * result_type each formula declares beside itself in the document.
 *
 * @param array<string, string> $unreadable  filled with codes whose declared
 *                                           type this cannot read
 */
function worked_symbol_table(string $docs, ?array &$unreadable = null): \SmartPRS\Platform\Spx\SymbolTable
{
    $unreadable = [];
    $registry = new \SmartPRS\Platform\Formula\FormulaRegistry();

    foreach (worked_formula_result_types($docs) as $code => $declared) {
        $type = spx_type_from_declaration($declared);

        if ($type === null) {
            $unreadable[$code] = $declared;

            continue;
        }

        $registry->declare($code, $type);
    }

    return $registry->applyTo(\SmartPRS\Platform\Spx\SymbolTable::catalogue());
}
