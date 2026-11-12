<?php
// php tests/dates_test.php — dd-mm-yyyy for people, ISO underneath.
declare(strict_types=1);
require_once __DIR__ . '/../surface/Dates.php';
require_once __DIR__ . '/../surface/Sheet.php';
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Sheet;

$ok = function_exists('ok') ? static function (bool $c, string $what): void { group('dates'); ok($c, $what); }
    : static function (bool $c, string $what): void { echo ($c ? 'ok   ' : 'FAIL ') . $what . "\n"; $c || exit(1); };
$ok(Dates::inText('From 2026-09-01 to 2026-09-30 10:15') === 'From 01-09-2026 to 30-09-2026 10:15', 'text dates');
$ok(Dates::inText('AIPL-1005_2026-09-22 report-2026-09-30.csv 2026-09 2026-13-45') === 'AIPL-1005_2026-09-22 report-2026-09-30.csv 2026-09 2026-13-45', 'ids, months, non-dates untouched');
$ok(Dates::toIso('7-10-2026') === '2026-10-07' && Dates::toIso('07/10/2026') === '2026-10-07' && Dates::toIso('2026-10-07') === '2026-10-07', 'toIso');
$ok(Dates::toIso('31-02-2026') === null && Dates::toIso('10/7') === null, 'toIso refuses');
$ok(Dates::csvToIso("a,doj\r\nX,05-10-2026\r\nY,\"5/1/2026\",9-9\n") === "a,doj\r\nX,2026-10-05\r\nY,\"2026-01-05\",9-9\n", 'csv upload normalised');
$x = Sheet::xlsx([['Name', 'DOJ'], ['Asha', '2026-10-05'], ['At', '2026-10-05 09:00']], [], 1, [], [], [1]);
// 27 Oct: a sample's date column is dd-mm-yyyy TEXT (no Excel locale can swap day and month); a typed date-time reads ISO too.
$ok(Sheet::toCsv($x, 'a.xlsx') === "Name,DOJ\nAsha,2026-10-05\nAt,\"2026-10-05 09:00\"\n", 'xlsx date column round-trips day-first');
function_exists('ok') || print("all passed\n");
