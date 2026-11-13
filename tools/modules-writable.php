<?php

declare(strict_types=1);

/**
 * CAN EVERY DECLARED MODULE ACTUALLY TAKE A ROW?
 *
 * `tools/smoke.php` proves all eighty-four module screens RENDER, list and form,
 * against the real schema. That is the smaller half of the question. A screen that
 * renders and cannot save is the shape this product's defects keep taking: the
 * declaration names a column, the table has a different one, and nothing notices
 * until somebody fills the form in and presses the button — at which point they
 * have typed a page of data that has nowhere to go.
 *
 * So this attempts a real INSERT for every module, through `Modules::save()` — the
 * same path the screen uses, including its validation, its readonly and locked
 * fields and its lookup checks. Anything else would be testing a different code
 * path from the one a person uses, which is how a green check accompanies a broken
 * screen.
 *
 * NOTHING IS KEPT. Every attempt runs inside a transaction that is ROLLED BACK,
 * so a tool for finding out whether a table accepts a row does not leave eighty-four
 * of them behind. That matters more than it sounds: this is exactly the kind of
 * tool somebody runs against a customer's database at the end of a bad day.
 *
 * A MODULE THAT REFUSES IS NOT AUTOMATICALLY BROKEN. Some refusals are the product
 * working — a required field this tool could not invent a plausible value for, a
 * lookup with no rows to point at yet. Those are reported as their own state and
 * counted separately from the ones where MySQL itself rejected the row, because
 * "the form asked for something I did not give it" and "the column the declaration
 * names does not exist" are different findings with different fixes.
 *
 * ponytail: one row per module, generated from the declared field types. It does
 * not exercise UPDATE, or the value ranges a customer's data really contains. Add
 * an update pass when a module turns out to accept an insert and refuse an edit;
 * the shape here does not change.
 *
 * Usage: php tools/modules-writable.php [--verbose]
 */

require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/Customisation.php';
require_once __DIR__ . '/../surface/Employment.php';
require_once __DIR__ . '/../surface/Amount.php';
require_once __DIR__ . '/../surface/Modules.php';

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/Platform/'
        . str_replace('\\', '/', substr($class, strlen('SmartPRS\\Platform\\'))) . '.php';

    if (str_starts_with($class, 'SmartPRS\\Platform\\') && is_file($path)) {
        require_once $path;
    }
});

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;

$root = dirname(__DIR__);
$verbose = in_array('--verbose', $argv, true);
$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    fwrite(STDERR, "Refused: no database.\n\n" . Database::why() . "\n\n"
        . "This check exists to find declarations the SCHEMA will not accept. On the file store\n"
        . "every column exists the moment something writes it, so a run there would report all\n"
        . "eighty-four modules writable having proved nothing — which is the condition that hid\n"
        . "every defect of this kind so far.\n");

    exit(2);
}

require_once $root . '/surface/Session.php';

$tenant = (string) $pdo->query('SELECT tenant_id FROM users WHERE email = '
    . $pdo->quote(Session::LOCAL_DEFAULT_EMAIL) . ' LIMIT 1')->fetchColumn();

if ($tenant === '') {
    fwrite(STDERR, "Refused: no tenant to write into.\n    php tools/seed-local-admin.php\n");

    exit(2);
}

$store = (new SqlStore($pdo))->forTenant($tenant);
$modules = new Modules($store, $root . '/config/modules.json', new Customisation($store));

/**
 * A plausible value for one declared field.
 *
 * Generated from the DECLARED type rather than from the column, because the
 * declaration is what the screen renders and therefore what a person would be
 * filling in. A value the declaration would not have produced tests a form nobody
 * can submit.
 */
$valueFor = static function (array $field): string {
    if (($field['options'] ?? []) !== []) {
        // A value => label map ({"1": "Active"}) stores the KEY; a plain list stores the value itself.
        return array_is_list($field['options']) ? (string) $field['options'][0] : (string) array_key_first($field['options']);
    }

    if (($field['lookup'] ?? []) !== []) {
        return (string) $field['lookup'][0]['id'];
    }

    // A LINK WITH NOTHING TO LINK TO. The dropdown is declared (an Organization
    // Setup table) but that table has no rows here; the form offers "none" and
    // that is what a person would leave it at. Probing "1" would be a made-up id
    // the form rightly refuses.
    if ((string) ($field['lookup_bounds']['table'] ?? '') !== '') {
        return '';
    }

    // VALID JSON FOR A JSON FIELD. The first version sent "PROBE" into these and
    // reported four modules as refusing — which was true and useless, because the
    // refusal was the product correctly declining a value no person would type.
    // A probe that cannot produce a value the form accepts cannot tell "this module
    // does not work" from "I made up the wrong thing", and that distinction is the
    // whole output of this file.
    if ((string) $field['type'] === 'json') {
        return '[]';
    }

    return match ((string) $field['type']) {
        'number' => '1',
        'date' => date('Y-m-d'),
        'datetime-local' => date('Y-m-d H:i:s'),
        'time' => '09:00:00',
        'checkbox' => '1',
        'email' => 'probe@smartprslocal',
        default => 'PROBE',
    };
};

// ------------------------------------------- DOES THE DECLARATION AGREE WITH THE SCHEMA?
//
// Checked before anything is written, because it is the cheaper question and it
// explains most of the expensive one. A field declaring `type: number` over a
// `datetime` column renders a number box, and the row is refused the moment
// somebody uses it — which is exactly what `webhooks.last_received_at` did, under
// a label that also carried a rupee sign it had no business having.
//
// A `checkbox` over a varchar or a `time` over a varchar are NOT reported: the
// column takes what the control posts, the screen is right, and flagging them
// would bury the one that matters under three that do not.
$contradictions = [];
$declaredRegistry = json_decode((string) file_get_contents($root . '/config/modules.json'), true);

foreach ((new Modules($store, $root . '/config/modules.json'))->all() as $module) {
    try {
        $types = $store->columnTypes((string) $module['table']);
    } catch (\Throwable) {
        continue;
    }

    foreach ((array) ($module['fields'] ?? []) as $column => $spec) {
        if (!isset($types[$column], $spec['type'])) {
            continue;
        }

        $sql = strtolower((string) $types[$column]);
        $agrees = match ((string) $spec['type']) {
            'number' => str_contains($sql, 'int') || str_contains($sql, 'decimal')
                || str_contains($sql, 'float') || str_contains($sql, 'double'),
            'date' => str_contains($sql, 'date'),
            'datetime-local' => str_contains($sql, 'datetime') || str_contains($sql, 'timestamp'),
            default => true,
        };

        if (!$agrees) {
            $contradictions[] = sprintf('%s.%s declares %s over a %s column',
                (string) $module['key'], (string) $column, (string) $spec['type'], $sql);
        }
    }
}

foreach ($contradictions as $line) {
    echo "  CONTRADICTS THE SCHEMA  {$line}\n";
}

$writable = [];
$notEditable = [];
$refusedByForm = [];
$rejectedByDatabase = [];

foreach ($modules->all() as $module) {
    $key = (string) $module['key'];
    $spec = $modules->fields($module);
    $input = ['k' => $key];

    foreach ($spec['fields'] as $field) {
        if ($field['readonly'] || $field['hidden']) {
            continue;
        }

        // EVERY EDITABLE FIELD, not only the required ones. Filling only the
        // required ones sounded careful and was useless: fifty-two of the
        // eighty-four modules declare nothing as required, so the probe submitted an
        // empty form and every one of them refused with "nothing was filled in" —
        // fifty-two false findings, and the two real ones would have been invisible
        // among them. A person opening a form fills it in, so that is what this
        // does, and a column that then rejects the value is the finding this file
        // is for.
        $input[(string) $field['name']] = $valueFor($field);
    }

    // ROLLED BACK, ALWAYS — including when save() throws. A tool that discovers
    // whether a table accepts a row must not be the reason a table has one.
    $pdo->beginTransaction();
    $result = ['ok' => false, 'why' => ''];
    $edited = null;
    $threw = null;

    try {
        $result = $modules->save($module, $input);

        // AND CAN IT BE EDITED? INSIDE THE SAME TRANSACTION — which is the whole
        // reason this sits here rather than after the block. The first version put
        // the edit after the `finally`, so it ran with no transaction open and
        // COMMITTED: the tool that printed "nothing was kept" had quietly written a
        // row into seventy-seven tables on every run, and the residue then made
        // three modules look writable that had refused the run before. A promise a
        // tool makes about itself is exactly the promise nobody checks, so it is
        // checked now — tests/modules_rollback_test.php counts every module table
        // before and after and fails if a single one moved.
        //
        // A module that accepts an insert and refuses an update is a screen where a
        // typo is permanent, and nothing else here would notice: the two paths
        // differ precisely where things go wrong — readonly and locked fields, and
        // an id that has to survive the round trip.
        if ($result['ok']) {
            $again = $input;
            $again['id'] = (string) ($result['row']['id'] ?? '');
            $edited = $again['id'] === ''
                ? ['ok' => false, 'why' => 'the insert returned no id, so there was nothing to edit']
                : $modules->save($module, $again);
        }
    } catch (\Throwable $e) {
        $threw = $e;
    } finally {
        $pdo->rollBack();
    }

    if ($threw !== null) {
        $rejectedByDatabase[$key] = trim(explode("\n", $threw->getMessage())[0]);
        printf("  %-28s REJECTED BY THE DATABASE\n", $key);

        continue;
    }

    if ($result['ok']) {
        $writable[] = $key;

        if ($edited !== null && !$edited['ok']) {
            $notEditable[$key] = (string) $edited['why'];
            printf("  %-28s writable, NOT EDITABLE\n", $key);
        } elseif ($verbose) {
            printf("  %-28s writable and editable\n", $key);
        }

        continue;
    }

    $refusedByForm[$key] = $result['why'];
    printf("  %-28s refused by the form\n", $key);
}

echo "\n";

foreach ($notEditable as $key => $why) {
    echo "NOT EDITABLE  {$key}\n    " . substr($why, 0, 200) . "\n";
}

foreach ($rejectedByDatabase as $key => $why) {
    echo "REJECTED  {$key}\n    " . substr($why, 0, 200) . "\n";
}

if ($refusedByForm !== [] && $verbose) {
    echo "\nREFUSED BY THE FORM — the module's own validation declined the value this tool\n"
        . "invented. Usually correct behaviour, and listed so a genuine one is not hidden among them:\n";

    foreach ($refusedByForm as $key => $why) {
        echo "    {$key} — " . substr($why, 0, 140) . "\n";
    }
}

if ($refusedByForm !== [] && !$verbose) {
    echo "Re-run with --verbose to see what each form asked for.\n";
}

// THE VERDICT IS THE LAST LINE, deliberately: tools/check.php reports a passing
// run by its final line, and this file used to end with "Re-run with --verbose",
// so the suite's summary row for it read as a hint rather than a result.
echo "\nNothing was kept: every attempt ran in a transaction and was rolled back.\n";

// THE VERDICT IS THE LAST LINE, deliberately: tools/check.php reports a passing run
// by its FINAL line, and this file ended on the rollback note — so the suite's row
// for it read as a reassuring aside rather than a result, which is a small version
// of exactly the thing this project keeps removing.
printf("%s  %d module(s): %d writable (%d not editable), %d refused by their own form,"
    . " %d REJECTED BY THE DATABASE, %d declaration(s) contradicting the schema.\n",
    $rejectedByDatabase === [] && $contradictions === [] && $notEditable === [] ? 'PASS' : 'FAIL',
    count($modules->all()), count($writable), count($notEditable), count($refusedByForm),
    count($rejectedByDatabase), count($contradictions));

// A contradiction fails the run too: it is a form somebody cannot submit, found
// without writing anything.
// Only a database rejection fails the run. A form refusing a value this tool made
// up is the product being careful, and failing on it would train somebody to
// ignore a red line.
exit($rejectedByDatabase === [] && $contradictions === [] && $notEditable === [] ? 0 : 1);
