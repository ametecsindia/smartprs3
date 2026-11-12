<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * SPREADSHEETS, MOSTLY WITHOUT A SPREADSHEET LIBRARY.
 *
 * An .xlsx is a zip of XML, and ext-zip is already here, so the upload sample
 * can be a real workbook with real dropdowns (Excel data validation pointing at
 * a Lists sheet), and an uploaded .xlsx can be read back to the CSV text the
 * importer already understands. Nothing new is installed for any of that.
 *
 * GENUINE BINARY .xls (BIFF, the pre-2007 format) is the one shape here that
 * really does need a library's worth of code to parse correctly across 25
 * years of Excel versions, so it is the one format that gets one —
 * phpoffice/phpspreadsheet, loaded LAZILY inside xlsToCsv() and only when that
 * method actually runs. An installation that never receives an .xls upload
 * never loads it, and app.php's own promise ("runs with nothing installed")
 * stays true for everyone who does not need this one format.
 */
final class Sheet
{
    private const DATA_ROWS = 1000;

    /**
     * Build a workbook: sheet 1 holds $rows (first rows are headers), and every
     * column named in $lists gets a dropdown restricted to those values on the
     * data rows below the headers. Returns the file bytes.
     *
     * $cascades adds DEPENDENT dropdowns — a narrower list per row, picked by
     * what an earlier cell in that same row holds (Branch narrowed to the
     * chosen Company, Department to the chosen Branch, and so on), so a person
     * cannot pick a combination Organization Configuration never set up. Each
     * entry: ['parentCol' => int, 'childCol' => int, 'groups' => array<string
     * parent-cell-text, list<string> allowed child values>]. The technique
     * (INDIRECT of a VLOOKUP'd defined name, wrapped in IFERROR so a blank
     * parent just shows an empty list instead of an Excel error) needs no
     * parent-text sanitising: the map table below holds the exact parent text
     * next to an arbitrary, always-valid range name this method makes up, so
     * VLOOKUP does the matching and INDIRECT never has to reconstruct a name
     * from the cell's own text.
     *
     * $customValidations adds a FORMAT check instead of a fixed list — for a
     * field a dropdown cannot cover (a PAN, a mobile number): 0-based column
     * index => ['formula' => an Excel boolean expression with '{c}' standing
     * for that column's first data cell, 'title' => ..., 'message' => ...].
     *
     * $columnWidths sets a fixed Excel column width (characters) on a column that
     * would otherwise be left at Excel's own default — an id column that reads
     * "########"-narrow otherwise.
     *
     * $conditionalFormat paints a data cell by its own value — a shift code, a
     * status word — so a filled sheet reads at a glance instead of as a grid of
     * text. One fill colour per distinct value, applied over one rectangular
     * column range shared by all of them (a roster's day columns, say); a value
     * with no colour given is left unpainted rather than refused.
     *
     * @param list<list<string>> $rows
     * @param array<int, list<string>> $lists 0-based column index => allowed values
     * @param list<array{parentCol: int, childCol: int, groups: array<string, list<string>>}> $cascades
     * @param array<int, array{formula: string, title: string, message: string}> $customValidations
     * @param list<int> $dateColumns columns that must hold a real date
     * @param array<int, string> $formulaColumns column => formula on every data row, '{r}' = that row
     * @param array<string, array<string, string>> $tables defined name => key => value, for those formulas
     * @param array<int, float> $columnWidths 0-based column index => width, in Excel's own character units
     * @param array{cols: array{0: int, 1: int}, colors: array<string, string>} $conditionalFormat
     *        cols: [first, last] 0-based column range; colors: cell text (case-sensitive,
     *        exact) => 6-digit hex RGB, e.g. 'AN' => 'BFD7FF'
     * @param array<int, string> $rowStyles 0-based header row => 'head' | 'meta' | 'example' | 'note' (a legend:
     *        its cells joined into one line across the whole sheet). Unset rows: the
     *        first header row with more than one cell is the navy heading band, the ones after it are the
     *        light "meta" rows (field codes, data types, hints). Example rows are shaded amber.
     * @param string $sheetName the tab's name; '' = the title line, else "Data".
     * @param array{title?: string, message?: string} $lockExamples non-empty = the 'example' rows are LOCKED (sheet
     *        protected, every other cell left editable) and selecting one shows this popup (owner, 5 Nov 2026: the
     *        sample rows cannot be edited and say plainly they are not employees). Title <= 32, message <= 255 (Excel caps).
     */
    public static function xlsx(array $rows, array $lists, int $headerRows, array $cascades = [], array $customValidations = [],
        array $dateColumns = [], array $formulaColumns = [], array $tables = [], array $columnWidths = [],
        array $conditionalFormat = [], array $rowStyles = [], string $sheetName = '', array $lockExamples = []): string
    {
        $sheetCells = []; // row number => column => cell XML
        $wrapped = [];    // row number => column => [text, bold] — sized into row heights below
        $lineHeight = []; // row number => points per text line
        $isDateCol = array_fill_keys($dateColumns, true);
        // EVERY DOWNLOAD READABLE AS OPENED (owner, 23 Oct): columns sized to their content, header bold and
        // wrapped, long text wrapped, amounts as real numbers — nobody widens or wraps a column by hand.
        // A PROPER LAYOUT (owner, 27 Oct): one navy heading row; field codes / types / hints as light grey rows;
        // example rows shaded amber; every cell of those rows filled and bordered (no white holes); data rows
        // bordered; header frozen; a title line above an export in large navy type.
        $autoWidths = [];
        $span = max(1, ...array_map(static fn (array $cells): int => count($cells), $rows ?: [[]]));
        $styleOf = ['head' => 2, 'meta' => 6, 'example' => 7];
        $role = [];
        $seenHead = false;
        $titleRows = 0;
        $notes = [];      // row number => legend text, merged across the sheet
        $esc = static fn (string $v): string => htmlspecialchars(Dates::inText($v), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        foreach ($rows as $r => $cells) {
            if ($r >= $headerRows) {
                $role[$r] = 'data';
            } elseif (isset($rowStyles[$r])) {
                $role[$r] = $rowStyles[$r];
                $seenHead = $seenHead || $rowStyles[$r] === 'head';
            } elseif (count(array_filter(array_map('strval', $cells), static fn (string $x): bool => $x !== '')) <= 1) {
                // A title / note line (one filled cell above the header) neither sizes a column nor gets a band.
                $role[$r] = implode('', array_map('strval', $cells)) === '' ? 'blank' : ($titleRows++ === 0 ? 'title' : 'subtitle');
            } else {
                $role[$r] = $seenHead ? 'meta' : 'head';
                $seenHead = true;
            }
        }

        foreach ($rows as $r => $cells) {
            $sheetCells[$r + 1] = [];
            $kind = $role[$r];

            if ($kind === 'blank') {
                continue;
            }

            if ($kind === 'note') {
                $note = implode('  ', array_filter(array_map('strval', $cells), static fn (string $x): bool => $x !== ''));
                $sheetCells[$r + 1][0] = '<c r="' . self::ref(0, $r + 1) . '" t="inlineStr" s="10"><is><t xml:space="preserve">' . $esc($note) . '</t></is></c>';
                $notes[$r + 1] = $note;

                continue;
            }

            if ($kind === 'title' || $kind === 'subtitle') {
                foreach ($cells as $c => $v) {
                    (string) $v === '' || $sheetCells[$r + 1][$c] = '<c r="' . self::ref($c, $r + 1) . '" t="inlineStr" s="' . ($kind === 'title' ? 8 : 9)
                        . '"><is><t xml:space="preserve">' . $esc((string) $v) . '</t></is></c>';
                }

                continue;
            }

            $lineHeight[$r + 1] = $kind === 'meta' ? 12.5 : 15.0;

            // Every column of a heading / meta / example / data row gets its cell, filled or not — a band
            // with holes in it reads as broken.
            for ($c = 0; $c < $span; $c++) {
                $v = (string) ($cells[$c] ?? '');
                $kind === 'meta' || $autoWidths[$c] = max($autoWidths[$c] ?? 10, min(self::WRAP_AT, mb_strlen($v) + 3));
                $ref = self::ref($c, $r + 1);

                if ($kind !== 'data') {
                    $sheetCells[$r + 1][$c] = $v === '' ? '<c r="' . $ref . '" s="' . $styleOf[$kind] . '"/>'
                        : '<c r="' . $ref . '" t="inlineStr" s="' . $styleOf[$kind] . '"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
                    $v === '' || $wrapped[$r + 1][$c] = [Dates::inText($v), $kind === 'head'];

                    continue;
                }

                if ($v === '') {
                    // A COMPUTED COLUMN ON A PRE-FILLED ROW gets its formula too (3 Nov: a scheme's bulk sample lists
                    // every employee and works each one's commission out as the target achieved is typed).
                    $sheetCells[$r + 1][$c] = isset($formulaColumns[$c])
                        ? '<c r="' . $ref . '" s="4"><f>' . htmlspecialchars(str_replace('{r}', (string) ($r + 1), $formulaColumns[$c]), ENT_XML1, 'UTF-8') . '</f></c>'
                        : '<c r="' . $ref . '" s="3"/>';

                    continue;
                }

                if (self::isAmount($v)) {
                    $sheetCells[$r + 1][$c] = '<c r="' . $ref . '" s="4"><v>' . $v . '</v></c>';

                    continue;
                }

                // AN ISO DATE IN AN EXPORT IS A REAL EXCEL DATE shown dd-mm-yyyy (style 1), whatever the reader's
                // Excel locale; in a sample's date column it is the dd-mm-yyyy text the person types.
                if (!isset($isDateCol[$c]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && Dates::toIso($v) !== null) {
                    $serial = intdiv((new \DateTimeImmutable($v . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 86400) + 25569;
                    $sheetCells[$r + 1][$c] = '<c r="' . $ref . '" s="1"><v>' . $serial . '</v></c>';

                    continue;
                }

                $sheetCells[$r + 1][$c] = '<c r="' . $ref . '" t="inlineStr" s="3"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
                $wrapped[$r + 1][$c] = [Dates::inText($v), false];
            }
        }

        $byRow = [];
        $validations = '';
        $validationCount = 0;
        $listCol = 0;
        // WHERE ON THE LISTS SHEET A COLUMN OF VALUES ENDED UP — written once,
        // read by both the flat lists above and the cascade groups below, so a
        // defined name always points at exactly what is really there.
        $putColumn = function (array $values) use (&$byRow, &$listCol): int {
            $col = $listCol;

            foreach ($values as $i => $v) {
                $byRow[$i + 1][] = '<c r="' . self::ref($col, $i + 1) . '" t="inlineStr"><is><t xml:space="preserve">'
                    . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }

            $listCol++;

            return $col;
        };

        // ONE RULE PER COLUMN. Excel rejects two dataValidation ranges covering
        // the same cells and strips EVERY rule in the sheet on open ("Removed
        // Feature: Data validation") — a lookup column that also cascades
        // (Branch, Department...) would otherwise carry its flat list AND its
        // dependent one. The narrower rule wins: cascade, then format check.
        $claimed = array_fill_keys(array_map(static fn (array $c): int => (int) $c['childCol'], $cascades), true)
            + array_fill_keys(array_keys($customValidations), true)
            + array_fill_keys(array_keys($formulaColumns), true);

        foreach ($lists as $col => $values) {
            $values = array_values(array_filter(array_map('strval', $values), static fn (string $v): bool => $v !== ''));

            if ($values === [] || isset($claimed[$col])) {
                continue;
            }

            $letter = self::letter($putColumn($values));
            $from = self::ref($col, $headerRows + 1);
            $to = self::ref($col, $headerRows + self::DATA_ROWS);
            $validations .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Not in the list"'
                . ' error="Pick one of the values set up under Organization Setup." sqref="' . $from . ':' . $to . '">'
                . '<formula1>Lists!$' . $letter . '$1:$' . $letter . '$' . count($values) . '</formula1></dataValidation>';
            $validationCount++;
        }

        // DEPENDENT DROPDOWNS — see the doc-comment above for the technique.
        $definedNames = '';

        // A two-column table on the Lists sheet under one defined name: key text
        // in the first column, value in the second — what VLOOKUP reads.
        $putTable = function (string $name, array $pairs) use ($putColumn, &$definedNames): void {
            $keyCol = self::letter($putColumn(array_map('strval', array_keys($pairs))));
            $valCol = self::letter($putColumn(array_map('strval', array_values($pairs))));
            $definedNames .= '<definedName name="' . $name . '">Lists!$' . $keyCol . '$1:$' . $valCol . '$' . count($pairs) . '</definedName>';
        };

        // Parent text => a defined name holding that parent's children, as a
        // table named $mapName; null when no parent has any children.
        $makeMap = function (array $groups, string $prefix) use ($putColumn, $putTable, &$definedNames): ?string {
            $map = [];

            foreach ($groups as $parentValue => $children) {
                $children = array_values(array_filter(array_map('strval', (array) $children), static fn (string $v): bool => $v !== ''));

                if ($children === [] || (string) $parentValue === '') {
                    continue;
                }

                $childLetter = self::letter($putColumn($children));
                // Names that can never read as a cell address (MAP0, CASC1...):
                // Excel refuses a defined name shaped like a reference.
                $rangeName = 'Spr_Casc_' . $prefix . '_' . count($map);
                $definedNames .= '<definedName name="' . $rangeName . '">Lists!$' . $childLetter . '$1:$'
                    . $childLetter . '$' . count($children) . '</definedName>';
                $map[(string) $parentValue] = $rangeName;
            }

            if ($map === []) {
                return null;
            }

            // THE MAP ITSELF: parent text next to its range name, so INDIRECT
            // never has to derive a name from the cell's own text (which could
            // hold characters a defined name cannot).
            $putTable('Spr_Map_' . $prefix, $map);

            return 'Spr_Map_' . $prefix;
        };

        foreach ($cascades as $n => $cascade) {
            $mapName = $makeMap((array) ($cascade['groups'] ?? []), (string) $n);

            if ($mapName === null) {
                continue;
            }

            $parentRef = self::ref((int) $cascade['parentCol'], $headerRows + 1);
            $source = 'VLOOKUP(' . $parentRef . ',' . $mapName . ',2,FALSE)';

            // FALLBACK PARENT: a blank narrower parent (no Branch picked) lists
            // by the broader one (the Company) instead of offering nothing.
            $fallback = $cascade['fallback'] ?? null;
            $fallbackMap = $fallback === null ? null : $makeMap((array) $fallback['groups'], $n . 'f');

            if ($fallbackMap !== null) {
                $source = 'IF(' . $parentRef . '="",VLOOKUP(' . self::ref((int) $fallback['parentCol'], $headerRows + 1)
                    . ',' . $fallbackMap . ',2,FALSE),' . $source . ')';
            }

            $from = self::ref((int) $cascade['childCol'], $headerRows + 1);
            $to = self::ref((int) $cascade['childCol'], $headerRows + self::DATA_ROWS);
            $validations .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Not in the list"'
                . ' error="Pick one of the values set up under Organization Setup for what is chosen earlier in this row."'
                . ' sqref="' . $from . ':' . $to . '"><formula1>' . htmlspecialchars('INDIRECT(' . $source . ')', ENT_XML1, 'UTF-8')
                . '</formula1></dataValidation>';
            $validationCount++;
        }

        // DATES ARE TYPED dd-mm-yyyy AS TEXT (owner, 27 Oct 2026: no date-format errors at upload). An Excel
        // date cell follows the PC's regional setting — an en-US Excel turns 07-10-2026 into 10 July and refuses
        // 13-10-2026 — so the column is Text, checked here for the dd-mm-yyyy shape, and read day-first on upload.
        // A real date pasted in from another sheet still reads correctly (Sheet::toCsv()).
        foreach ($dateColumns as $col) {
            if (isset($claimed[$col])) {
                continue;
            }

            $a = self::ref($col, $headerRows + 1);
            $formula = 'IFERROR(AND(LEN(' . $a . ')=10,MID(' . $a . ',3,1)="-",MID(' . $a . ',6,1)="-",ISNUMBER(--SUBSTITUTE(' . $a . ',"-","")),'
                . '--LEFT(' . $a . ',2)>=1,--LEFT(' . $a . ',2)<=31,--MID(' . $a . ',4,2)>=1,--MID(' . $a . ',4,2)<=12),FALSE)';
            $validations .= '<dataValidation type="custom" allowBlank="1" showErrorMessage="1"'
                . ' errorTitle="Date as dd-mm-yyyy" error="Type the date as dd-mm-yyyy, e.g. 07-10-2026." sqref="'
                . $a . ':' . self::ref($col, $headerRows + self::DATA_ROWS)
                . '"><formula1>' . htmlspecialchars($formula, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</formula1></dataValidation>';
            $validationCount++;
        }

        // COMPUTED COLUMNS — e.g. Role from the Designation in the same row:
        // $tables become named lookup tables, {r} in each formula is the row.
        foreach ($tables as $name => $pairs) {
            $pairs === [] || $putTable($name, $pairs);
        }

        foreach ($formulaColumns as $col => $template) {
            for ($r = $headerRows + 1; $r <= $headerRows + self::DATA_ROWS; $r++) {
                $sheetCells[$r][$col] ??= '<c r="' . self::ref($col, $r) . '" t="str"><f>'
                    . htmlspecialchars(str_replace('{r}', (string) $r, $template), ENT_XML1, 'UTF-8') . '</f></c>';
            }
        }

        ksort($sheetCells);
        $data = '';

        foreach ($sheetCells as $r => $cells) {
            ksort($cells);
            $ht = ($role[$r - 1] ?? '') === 'title' ? 22.0 : self::rowHeight($wrapped[$r] ?? [], $columnWidths + $autoWidths, $lineHeight[$r] ?? 15.0);
            isset($notes[$r]) && $ht = self::rowHeight([[$notes[$r], false]], [array_sum(array_map('floatval', $columnWidths + $autoWidths)) * 1.1], 14.0);
            // A heading band is never squeezed: at least 24pt, so it reads as a heading.
            ($role[$r - 1] ?? '') === 'head' && $ht = max(24.0, (float) $ht);
            $data .= '<row r="' . $r . '"' . ($ht === null ? '' : ' ht="' . $ht . '" customHeight="1"') . '>' . implode('', $cells) . '</row>';
        }

        // FORMAT CHECKS — a field no dropdown can cover (PAN, a mobile number):
        // a custom boolean formula instead of a fixed list.
        foreach ($customValidations as $col => $spec) {
            if (in_array((int) $col, array_map(static fn (array $c): int => (int) $c['childCol'], $cascades), true)) {
                continue;
            }

            $anchor = self::ref((int) $col, $headerRows + 1);
            $formula = htmlspecialchars(str_replace('{c}', $anchor, (string) $spec['formula']), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $from = self::ref((int) $col, $headerRows + 1);
            $to = self::ref((int) $col, $headerRows + self::DATA_ROWS);
            $validations .= '<dataValidation type="custom" allowBlank="1" showErrorMessage="1" errorTitle="'
                . htmlspecialchars((string) ($spec['title'] ?? 'Not the right format'), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"'
                . ' error="' . htmlspecialchars((string) ($spec['message'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '" sqref="' . $from . ':' . $to . '"><formula1>' . $formula . '</formula1></dataValidation>';
            $validationCount++;
        }

        ksort($byRow);
        $listXml = '';

        foreach ($byRow as $r => $cells) {
            $listXml .= '<row r="' . $r . '">' . implode('', $cells) . '</row>';
        }

        // COLUMN WIDTHS — <cols> must sit before <sheetData> (schema order).
        $colsXml = '';
        $colAttrs = [];

        foreach ($autoWidths + $columnWidths as $col => $_) {
            $colAttrs[$col] = ' width="' . ($columnWidths[$col] ?? $autoWidths[$col]) . '" customWidth="1"';
        }

        foreach ($columnWidths as $col => $width) {
            $colAttrs[$col] = ' width="' . $width . '" customWidth="1"';
        }

        // EVERY COLUMN IS TEXT for what a person types into it (style 5): a code keeps its leading zeros, an
        // 18-digit account every digit, 09:30 stays 09:30 and a date stays the dd-mm-yyyy that was typed.
        foreach ($dateColumns as $col) {
            $colAttrs[$col] ??= ' width="12" customWidth="1"';
        }

        foreach ($colAttrs as $col => $attrs) {
            $colAttrs[$col] = $attrs . ' style="5"';
        }

        ksort($colAttrs);

        foreach ($colAttrs as $col => $attrs) {
            $colsXml .= '<col min="' . ($col + 1) . '" max="' . ($col + 1) . '"' . $attrs . '/>';
        }

        $colsXml = $colsXml === '' ? '' : '<cols>' . $colsXml . '</cols>';

        // CONDITIONAL FORMATTING — one dxf (differential format) per distinct
        // value, referenced by index from a cellIs/equal rule over the shared
        // column range. <conditionalFormatting> sits after <sheetData>, before
        // <dataValidations> (schema order). Needs a styles.xml part to hold the
        // dxfs, so that part (and its Content_Types/rels entries) is added only
        // when there is at least one colour — every other caller's output is
        // unchanged.
        $dxfs = '';
        $condFormatXml = '';
        $colors = $conditionalFormat['colors'] ?? [];

        if ($colors !== []) {
            $from = self::ref((int) $conditionalFormat['cols'][0], $headerRows + 1);
            $to = self::ref((int) $conditionalFormat['cols'][1], $headerRows + self::DATA_ROWS);
            $rules = '';
            $i = 0;

            foreach ($colors as $value => $hex) {
                $dxfs .= '<dxf><fill><patternFill><bgColor rgb="FF' . strtoupper((string) $hex) . '"/></patternFill></fill></dxf>';
                $formula = htmlspecialchars('"' . str_replace('"', '""', (string) $value) . '"', ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $rules .= '<cfRule type="cellIs" dxfId="' . $i . '" priority="' . ($i + 1) . '" operator="equal"><formula>' . $formula . '</formula></cfRule>';
                $i++;
            }

            $condFormatXml = '<conditionalFormatting sqref="' . $from . ':' . $to . '">' . $rules . '</conditionalFormatting>';
        }

        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        // LOCKED EXAMPLE ROWS: style 7 is the only locked style (styles.xml below), so protecting the sheet locks
        // just those rows; an input message (no rule, no error) pops up when one is selected. The example rows sit
        // above the data rows every other validation covers, so no two ranges overlap.
        $protect = '';
        $exampleRows = array_keys(array_filter($role, static fn (string $k): bool => $k === 'example'));

        if ($lockExamples !== [] && $exampleRows !== []) {
            $validations .= '<dataValidation allowBlank="1" showInputMessage="1" promptTitle="'
                . htmlspecialchars(mb_substr((string) ($lockExamples['title'] ?? ''), 0, 32), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" prompt="'
                . htmlspecialchars(mb_substr((string) ($lockExamples['message'] ?? ''), 0, 255), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sqref="'
                . self::ref(0, min($exampleRows) + 1) . ':' . self::ref($span - 1, max($exampleRows) + 1) . '"/>';
            $validationCount++;
            // ponytail: no password - Review > Unprotect still works; the importer skips these rows either way.
            $protect = '<sheetProtection sheet="1" objects="1" scenarios="1" formatCells="0" formatColumns="0" formatRows="0"'
                . ' insertRows="0" deleteRows="0" sort="0" autoFilter="0"/>';
        }

        // THE TAB'S NAME: what the caller says, else the export's title line, else "Data" (never "Employees" on an audit log).
        $titleText = '';
        foreach ($role as $r => $k) { $k === 'title' && $titleText === '' && $titleText = implode('', array_map('strval', $rows[$r])); }
        $tab = substr(trim((string) preg_replace('/[\\\\\/?*\[\]:]+/', ' ', $sheetName !== '' ? $sheetName : ($titleText !== '' ? $titleText : 'Data'))), 0, 31) ?: 'Data';
        // HEADER FROZEN (and the first column too on a wide sheet), so the headings stay in view while scrolling.
        $freezeCol = count($autoWidths) > 8 ? 1 : 0;
        $views = $headerRows < 1 ? '' : '<sheetViews><sheetView workbookViewId="0"><pane' . ($freezeCol ? ' xSplit="1"' : '') . ' ySplit="' . $headerRows
            . '" topLeftCell="' . self::ref($freezeCol, $headerRows + 1) . '" activePane="' . ($freezeCol ? 'bottomRight' : 'bottomLeft') . '" state="frozen"/></sheetView></sheetViews>';
        // AN EXPORT (a title line above it) IS FILTERABLE from its heading row, as report() is.
        $filter = '';
        $lastRow = count($rows);
        if ($titleRows > 0 && $lastRow > $headerRows) {
            $filter = self::ref(0, $headerRows) . ':' . self::ref($span - 1, $lastRow);
            $definedNames .= '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . htmlspecialchars(str_replace("'", "''", $tab), ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '\'!$A$' . $headerRows . ':$' . self::letter($span - 1) . '$' . $lastRow . '</definedName>';
        }
        $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet ' . $ns . '>' . $views . $colsXml . '<sheetData>' . $data . '</sheetData>' . $protect
            . ($filter === '' ? '' : '<autoFilter ref="' . $filter . '"/>')
            . ($notes === [] ? '' : '<mergeCells count="' . count($notes) . '">' . implode('', array_map(static fn (int $r): string
                => '<mergeCell ref="' . self::ref(0, $r) . ':' . self::ref($span - 1, $r) . '"/>', array_keys($notes))) . '</mergeCells>')
            . $condFormatXml
            . ($validations === '' ? '' : '<dataValidations count="' . $validationCount . '">' . $validations . '</dataValidations>') . '</worksheet>';
        $sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet ' . $ns . '><sheetData>' . $listXml . '</sheetData></worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook ' . $ns . ' xmlns:r="' . $rel . '"><sheets>'
                . '<sheet name="' . htmlspecialchars($tab, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/><sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/></sheets>'
                . ($definedNames === '' ? '' : '<definedNames>' . $definedNames . '</definedNames>')
                // Formula cells carry no cached value, so work them out on open.
                . ($formulaColumns === [] ? '' : '<calcPr fullCalcOnLoad="1"/>') . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="' . $rel . '/worksheet" Target="worksheets/sheet2.xml"/>'
                . '<Relationship Id="rId3" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet1,
            'xl/worksheets/sheet2.xml' => $sheet2,
        ];

        // Style 1 = dd-mm-yyyy dates. Always present, so every workbook has it.
        {
            $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet ' . $ns . '>'
                . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd\\-mm\\-yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00"/></numFmts>'
                . '<fonts count="6"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
                . '<font><i/><sz val="9"/><color rgb="FF64748B"/><name val="Calibri"/></font><font><i/><sz val="11"/><color rgb="FF334155"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="14"/><color rgb="FF1F3A5F"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font></fonts>'
                . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F7"/><bgColor indexed="64"/></patternFill></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF6DD"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
                . '<border><left style="thin"><color rgb="FFC9D2DE"/></left><right style="thin"><color rgb="FFC9D2DE"/></right>'
                . '<top style="thin"><color rgb="FFC9D2DE"/></top><bottom style="thin"><color rgb="FFC9D2DE"/></bottom><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                // 0 plain · 1 date · 2 heading (bold white on navy) · 3 text (wrapped) · 4 amount #,##0.00 · 5 Text column
                // · 6 meta row (codes / types / hints: small grey italic on light grey) · 7 example row (italic on light amber)
                // · 8 title (14pt bold navy) · 9 subtitle (italic grey)
                . '<cellXfs count="11"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyProtection="1"><protection locked="0"/></xf>'
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyProtection="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/><protection locked="0"/></xf>'
                . '<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyProtection="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/><protection locked="0"/></xf>'
                . '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyProtection="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/><protection locked="0"/></xf>'
                . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyProtection="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/><protection locked="0"/></xf>'
                . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyProtection="1" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/><protection locked="0"/></xf>'
                . '<xf numFmtId="49" fontId="2" fillId="3" borderId="1" xfId="0" applyProtection="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/><protection locked="0"/></xf>'
                . '<xf numFmtId="49" fontId="3" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
                . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyProtection="1" applyFont="1"><protection locked="0"/></xf>'
                . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyProtection="1" applyFont="1"><protection locked="0"/></xf>'
                // 10 note / legend line: grey italic on light grey, left, wrapped (merged across the sheet)
                . '<xf numFmtId="49" fontId="5" fillId="3" borderId="1" xfId="0" applyProtection="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/><protection locked="0"/></xf></cellXfs>'
                . ($dxfs === '' ? '' : '<dxfs count="' . substr_count($dxfs, '<dxf>') . '">' . $dxfs . '</dxfs>') . '</styleSheet>';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();

        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The workbook could not be written to a temporary file.');
        }

        foreach ($files as $name => $xml) {
            $zip->addFromString($name, $xml);
        }

        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /**
     * Whatever was uploaded — .csv, .xlsx, or a genuine binary .xls — as CSV
     * text.
     */
    public static function toCsv(string $bytes, string $filename): string
    {
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return Dates::csvToIso(self::xlsxToCsv($bytes));
        }

        if (str_starts_with($bytes, "\xD0\xCF\x11\xE0")) {
            return Dates::csvToIso(self::xlsToCsv($bytes, $filename));
        }

        // A JOB PORTAL'S ".xls" IS OFTEN AN HTML TABLE saved with an Excel name
        // (owner, 12 Oct: imports straight from the portal's download).
        if (stripos(substr($bytes, 0, 4096), '<table') !== false) {
            return Dates::csvToIso(self::htmlTableToCsv($bytes));
        }

        // dd-mm-yyyy typed by a person becomes the ISO every importer reads.
        return Dates::csvToIso($bytes);
    }

    private static function xlsxToCsv(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();

        if ($zip->open($tmp) !== true) {
            unlink($tmp);

            throw new \RuntimeException('The workbook could not be opened.');
        }

        $shared = [];
        $sst = $zip->getFromName('xl/sharedStrings.xml');

        if ($sst !== false) {
            foreach ((new \SimpleXMLElement($sst))->si as $si) {
                $shared[] = self::text($si);
            }
        }

        $dateStyles = self::dateStyles((string) $zip->getFromName('xl/styles.xml'));
        $first = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);

        if ($first === false) {
            throw new \RuntimeException('The workbook has no first sheet.');
        }

        $out = fopen('php://memory', 'w+');
        $sheet = new \SimpleXMLElement($first);

        // A FORMATTED REPORT BROUGHT BACK (title and subtitle above the filtered header, as report() writes
        // it): the rows above the header are not data, so the importer still finds its header on line 1.
        $from = 1;
        if (preg_match('/^[A-Z]+(\d+):/', (string) ($sheet->autoFilter['ref'] ?? ''), $af) === 1 && (int) $af[1] > 1 && (int) $af[1] <= 4) {
            $from = (int) $af[1];
            foreach ($sheet->sheetData->row as $row) {
                if ((int) $row['r'] < $from && count($row->c) > 1) {
                    $from = 1;   // real data above the filter: not a title band, keep every row
                }
            }
        }

        foreach ($sheet->sheetData->row as $row) {
            if ((int) $row['r'] < $from) {
                continue;
            }

            $cells = [];

            foreach ($row->c as $c) {
                $col = self::index(preg_replace('/\d+/', '', (string) $c['r']) ?? '');
                $t = (string) $c['t'];
                $v = (string) $c->v;

                if ($t === 's') {
                    $v = $shared[(int) $v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $v = self::text($c->is);
                } elseif ($v !== '' && is_numeric($v) && isset($dateStyles[(int) $c['s']])) {
                    $v = self::serial($v, $dateStyles[(int) $c['s']]);
                }

                // An empty value (a computed Role on a row nobody filled in) is
                // no cell at all, so an untouched formula row reads as blank.
                $v === '' || $cells[$col] = $v;
            }

            if ($cells === []) {
                fputcsv($out, []);

                continue;
            }

            $line = array_fill(0, max(array_keys($cells)) + 1, '');

            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }

            fputcsv($out, $line);
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** The first HTML table's rows as CSV — th and td alike, entities decoded. */
    private static function htmlTableToCsv(string $html): string
    {
        $doc = new \DOMDocument();
        $was = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($was);
        $table = $doc->getElementsByTagName('table')->item(0);
        $out = fopen('php://memory', 'w+');

        foreach ($table === null ? [] : $table->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                in_array(strtolower($cell->nodeName), ['td', 'th'], true) && $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');
            }

            fputcsv($out, $cells);
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * A genuine binary .xls (BIFF8), read via phpoffice/phpspreadsheet — see
     * the class doc-comment for why this one format gets a library and
     * xlsxToCsv() above does not.
     */
    private static function xlsToCsv(string $bytes, string $filename): string
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';

        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (!class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xls::class)) {
            throw new \RuntimeException("'{$filename}' is an old binary Excel file (.xls), which needs the "
                . "phpoffice/phpspreadsheet package to read. Run 'composer update' once from the project root and "
                . 'upload again — or open the file in Excel and Save As .xlsx or .csv instead.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xls');
        file_put_contents($tmp, $bytes);

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($tmp)->getActiveSheet();
            $out = fopen('php://memory', 'w+');

            foreach ($sheet->getRowIterator() as $row) {
                $iterator = $row->getCellIterator();
                // WITHOUT THIS, THE ITERATOR SKIPS BLANK CELLS and every row shifts
                // left by however many were empty — the same rectangular-row shape
                // xlsxToCsv() builds by padding to the row's own highest column.
                $iterator->setIterateOnlyExistingCells(false);
                $cells = [];

                foreach ($iterator as $cell) {
                    $value = $cell->getValue();
                    // SAME RULE xlsxToCsv() USES: an Excel date is a day count, not
                    // text — written out as Y-m-d, the one format the importer reads.
                    $cells[] = $value !== null && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)
                        ? \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d')
                        : (string) ($value ?? '');
                }

                fputcsv($out, $cells);
            }

            rewind($out);

            return (string) stream_get_contents($out);
        } finally {
            unlink($tmp);
        }
    }

    /**
     * EXCEL DATES ARE DAYS SINCE 1899-12-30, the time of day the fraction. A date cell reads Y-m-d, a time
     * cell HH:MM (09:30 — not 1899-12-30, which a time cell used to become), a date-and-time cell
     * "Y-m-d HH:MM". Integer arithmetic on the digits: a day count and seconds, never a float.
     */
    public static function serial(string $v, string $kind): string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $v, $m) !== 1) {
            return $v;
        }

        $secs = intdiv((int) str_pad(substr($m[2] ?? '', 0, 9), 9, '0') * 86400 + 500000000, 1000000000);
        $days = (int) $m[1] + intdiv($secs, 86400);
        $secs %= 86400;
        $time = sprintf('%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60)) . ($secs % 60 !== 0 ? sprintf(':%02d', $secs % 60) : '');
        $date = date('Y-m-d', strtotime('1899-12-30 +' . $days . ' days'));

        return match ($kind) { 'time' => $time, 'datetime' => $date . ' ' . $time, default => $date };
    }

    /** Style indexes whose number format is a date, a time or both. @return array<int, string> */
    private static function dateStyles(string $stylesXml): array
    {
        if ($stylesXml === '') {
            return [];
        }

        $x = new \SimpleXMLElement($stylesXml);
        $custom = [];

        foreach ($x->numFmts->numFmt ?? [] as $f) {
            $code = (string) preg_replace('/\[[^\]]*\]|"[^"]*"|\\./', '', strtolower((string) $f['formatCode']));
            $hasDate = preg_match('/[dy]/', $code) === 1;
            $hasTime = preg_match('/[hs]/', $code) === 1;
            $custom[(int) $f['numFmtId']] = $hasDate && $hasTime ? 'datetime' : ($hasDate ? 'date' : ($hasTime ? 'time' : ''));
        }

        $out = [];

        $i = 0;

        foreach ($x->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $kind = match (true) {
                $id >= 14 && $id <= 17 => 'date',
                ($id >= 18 && $id <= 21) || ($id >= 45 && $id <= 47) => 'time',
                $id === 22 => 'datetime',
                default => $custom[$id] ?? '',
            };
            $kind === '' || $out[$i] = $kind;
            $i++;
        }

        return $out;
    }

    /**
     * HOW TALL A ROW MUST BE so no wrapped cell is cut off (owner, 23 and 27 Oct): each cell's text laid
     * into its column word by word, the tallest cell wins. Null when every cell fits one line — the row
     * keeps the normal height. Characters per line are counted against the column width minus a margin,
     * so the estimate is never shorter than what Excel draws.
     *
     * @param array<int, array{0: string, 1: bool}> $cells column => [text, bold]
     * @param array<int, float|int> $widths
     */
    private static function rowHeight(array $cells, array $widths, float $lineHeight): ?float
    {
        $lines = 1;

        foreach ($cells as $col => [$text, $bold]) {
            $cap = max(1, (int) floor((float) ($widths[$col] ?? 10) * ($bold ? 0.85 : 1.0)) - 2);
            $n = 0;

            foreach (preg_split('/\R/u', (string) $text) ?: [''] as $para) {
                $used = 0;
                $n++;

                foreach (preg_split('/\s+/u', trim($para)) ?: [] as $word) {
                    $len = mb_strlen($word);

                    if ($len === 0) {
                        continue;
                    }

                    if ($used > 0 && $used + 1 + $len <= $cap) {
                        $used += 1 + $len;

                        continue;
                    }

                    $used > 0 && $n++;
                    $n += intdiv($len - 1, $cap);
                    $used = ($len - 1) % $cap + 1;
                }
            }

            $lines = max($lines, $n);
        }

        return $lines <= 1 ? null : round($lines * $lineHeight + 3, 1);
    }

    private static function text(\SimpleXMLElement $node): string
    {
        $s = (string) $node->t;

        foreach ($node->r as $run) {
            $s .= (string) $run->t;
        }

        return $s;
    }

    /**
     * A FORMATTED REPORT WORKBOOK (8 Oct, owner: exports "properly formatted").
     * Title line, a bold navy header row with borders, money columns as real
     * numbers shown #,##0.00 and right-aligned, ISO dates as real dates shown
     * dd-mm-yyyy, the header frozen and filterable, columns sized to their
     * content, and any row named in $boldRows (totals) emphasised.
     *
     * @param list<string> $head
     * @param list<list<string>> $rows
     * @param list<int> $moneyCols 0-based columns holding amounts / numbers
     * @param list<int> $boldRows 0-based indexes into $rows
     */
    public static function report(string $title, string $subtitle, array $head, array $rows, array $moneyCols = [], array $boldRows = [],
        string $sheetName = 'Report'): string
    {
        $esc = static fn (string $v): string => htmlspecialchars(Dates::inText($v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $money = array_fill_keys($moneyCols, true);
        $bold = array_fill_keys($boldRows, true);
        // A SAMPLE (no title, no subtitle) is filled in and uploaded again: header on row 1, Text columns,
        // dates as the dd-mm-yyyy a person types — see fromCsv().
        $plain = $title === '' && $subtitle === '';
        $widths = [];
        $xml = $plain ? '' : '<row r="1"><c r="A1" t="inlineStr" s="5"><is><t xml:space="preserve">' . $esc($title) . '</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr" s="6"><is><t xml:space="preserve">' . $esc($subtitle) . '</t></is></c></row>';
        $hr = $plain ? 1 : 4;
        $cells = '';

        // WIDTHS FIRST, so every row's height can be worked out against the final column widths.
        foreach ($head as $c => $h) {
            $widths[$c] = max(10, mb_strlen((string) $h) + 2);
        }

        foreach ($rows as $row) {
            foreach (array_values($row) as $c => $v) {
                $widths[$c] = min(self::WRAP_AT, max($widths[$c] ?? 10, mb_strlen((string) $v) + (isset($money[$c]) ? 5 : 2)));   // room for the thousands commas
            }
        }

        $headWrap = [];

        foreach ($head as $c => $h) {
            $cells .= '<c r="' . self::ref($c, $hr) . '" t="inlineStr" s="2"><is><t xml:space="preserve">' . $esc((string) $h) . '</t></is></c>';
            $headWrap[$c] = [(string) $h, true];
        }

        $xml .= '<row r="' . $hr . '" ht="' . max(30, (float) self::rowHeight($headWrap, $widths, 13.0)) . '" customHeight="1">' . $cells . '</row>';

        foreach (array_values($rows) as $i => $row) {
            $r = $hr + 1 + $i;
            $b = isset($bold[$i]);
            $cells = '';
            $rowWrap = [];

            foreach (array_values($row) as $c => $v) {
                $v = (string) $v;

                if ($v === '') {
                    $cells .= $b ? '<c r="' . self::ref($c, $r) . '" s="7"/>' : '';
                    continue;
                }

                if (isset($money[$c]) && is_numeric($v)) {
                    $whole = preg_match('/^-?\d+$/', $v) === 1; // a count, not an amount: no decimals
                    $cells .= '<c r="' . self::ref($c, $r) . '" s="' . ($whole ? ($b ? 11 : 10) : ($b ? 8 : 3)) . '"><v>' . $v . '</v></c>';
                } elseif (!$plain && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && Dates::toIso($v) !== null) {
                    $serial = intdiv((new \DateTimeImmutable($v . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 86400) + 25569;
                    $cells .= '<c r="' . self::ref($c, $r) . '" s="9"><v>' . $serial . '</v></c>';
                } else {
                    $cells .= '<c r="' . self::ref($c, $r) . '" t="inlineStr" s="' . ($b ? 7 : 4) . '"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
                    $rowWrap[$c] = [Dates::inText($v), $b];
                }
            }

            $ht = self::rowHeight($rowWrap, $widths, 13.0);
            $xml .= '<row r="' . $r . '"' . ($ht === null ? '' : ' ht="' . $ht . '" customHeight="1"') . '>' . $cells . '</row>';
        }

        $cols = '';

        foreach ($head as $c => $_) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . ($widths[$c] ?? 12) . '" customWidth="1"' . ($plain ? ' style="12"' : '') . '/>';
        }

        $lastCol = self::letter(max(0, count($head) - 1));
        $lastRow = $hr + max(1, count($rows));
        $last = $lastCol . $lastRow;
        $name = substr(trim((string) preg_replace('/[\\\\\/?*\[\]:]+/', ' ', $sheetName)), 0, 31) ?: 'Report';
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $border = '<border><left style="thin"><color rgb="FFD0D7E2"/></left><right style="thin"><color rgb="FFD0D7E2"/></right>'
            . '<top style="thin"><color rgb="FFD0D7E2"/></top><bottom style="thin"><color rgb="FFD0D7E2"/></bottom><diagonal/></border>';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook ' . $ns . ' xmlns:r="' . $rel . '"><sheets><sheet name="'
                . $esc($name) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''
                . $esc(str_replace("'", "''", $name)) . '\'!$A$' . $hr . ':$' . $lastCol . '$' . $lastRow . '</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet ' . $ns . '>'
                . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $hr . '" topLeftCell="A' . ($hr + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                . '<cols>' . $cols . '</cols><sheetData>' . $xml . '</sheetData>'
                . '<autoFilter ref="A' . $hr . ':' . $last . '"/></worksheet>',
            // 0 plain · 1 date · 2 header · 3 money · 4 text cell · 5 title · 6 subtitle · 7 bold text · 8 bold money · 9 bordered date · 10 count · 11 bold count · 12 Text column
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet ' . $ns . '>'
                . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd\-mm\-yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00"/></numFmts>'
                . '<fonts count="5"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="14"/><color rgb="FF1F3A5F"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="10"/><name val="Calibri"/></font></fonts>'
                . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F7"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="13"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
                . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
                . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
                . '<xf numFmtId="165" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
                . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
                . '<xf numFmtId="3" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
                . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
                . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();

        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The workbook could not be written to a temporary file.');
        }

        foreach ($files as $name => $x) {
            $zip->addFromString($name, $x);
        }

        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /** Widest a column is sized to; longer text wraps inside it. */
    public const WRAP_AT = 50;

    /** A plain decimal amount (8.50, 160725.97) — never a long digit string such as an account number. */
    public static function isAmount(string $v): bool
    {
        return preg_match('/^-?(0|[1-9]\d{0,11})\.\d{1,4}$/', $v) === 1;
    }

    /**
     * ANY CSV DOWNLOAD AS A FORMATTED WORKBOOK (owner, 23 Oct 2026: "all the downloaded files should have
     * proper formatting"). A CSV opened in Excel shows a 12+ digit account number as 1.81206E+13 and
     * zeroes every digit after the 15th. Here every column is TEXT unless every value in it is an
     * amount or a short count, so account / phone / Aadhaar / IFSC / codes keep every character; the
     * header is bold, columns are sized, long text wraps, dates read dd-mm-yyyy.
     */
    public static function fromCsv(string $csv, string $title, bool $sample = false): string
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $all = [];

        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $all[] = array_map(static fn ($v): string => trim((string) $v), $row);
        }

        fclose($fh);
        $all = array_values(array_filter($all, static fn (array $r): bool => implode('', $r) !== ''));

        if ($all === []) {
            return self::report($title, '', [''], []);
        }

        // Lines above the header (a title or a note) become the subtitle; the header is the first widest row.
        $width = max(array_map('count', $all));
        $at = 0;
        while (count($all[$at]) < $width && $at < count($all) - 1) { $at++; }
        $sub = implode(' · ', array_map(static fn (array $r): string => implode(' ', array_filter($r)), array_slice($all, 0, $at)));
        $head = array_pad($all[$at], $width, '');
        $rows = array_map(static fn (array $r): array => array_pad($r, $width, ''), array_slice($all, $at + 1));

        // A SAMPLE / TEMPLATE goes out as typed text on a plain sheet: header on row 1, no title, no number
        // formats — what the person fills in comes back to the importer exactly as typed.
        if ($sample) {
            return self::report('', '', $head, $rows, [], [], $title);
        }

        $money = [];
        foreach ($head as $c => $h) {
            if (preg_match('/account|a\/c|ifsc|phone|mobile|aadha|\\bpan\\b|\\buan\\b|esic? ?no|\\bpin\\b|code|\bid\b|number|year|\bfy\b/i', $h) === 1) {
                continue;
            }
            $seen = false;
            foreach ($rows as $r) {
                $v = $r[$c];
                if ($v === '') { continue; }
                if (!self::isAmount($v) && preg_match('/^-?(0|[1-9]\d{0,7})$/', $v) !== 1) { continue 2; }
                $seen = true;
            }
            $seen && $money[] = $c;
        }

        // One column, one look: where any amount has paise, whole amounts show .00 too.
        foreach ($money as $c) {
            if (array_filter($rows, static fn (array $r): bool => self::isAmount($r[$c])) === []) { continue; }
            foreach ($rows as $i => $r) {
                $r[$c] !== '' && !self::isAmount($r[$c]) && $rows[$i][$c] = $r[$c] . '.00';
            }
        }

        return self::report($title, trim($sub . ($sub !== '' ? ' · ' : '') . 'Generated ' . date('d-m-Y H:i')), $head, $rows, $money, [], $title);
    }

    private static function ref(int $col, int $row): string
    {
        return self::letter($col) . $row;
    }

    private static function letter(int $col): string
    {
        $s = '';

        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }

        return $s;
    }

    private static function index(string $letters): int
    {
        $n = 0;

        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
