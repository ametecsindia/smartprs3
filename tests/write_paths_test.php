<?php

declare(strict_types=1);

/**
 * EVERY COLUMN THIS APPLICATION WRITES, AGAINST THE SCHEMA THE MIGRATIONS BUILD.
 *
 * `employee_code` was found six times before anybody looked at the write paths:
 * `gross`/`ctc`, `joined_on`/`doj`, `bank_account`/`bank_acc`, `bank_ifsc`/`ifsc`,
 * and six more on `leaves`. The reads are covered — `tools/smoke.php` opens all
 * 24 routes and fails on any warning. The WRITES were covered by nothing at all,
 * and `Leave::request()` was writing four columns that have never existed.
 *
 * A read that asks for a column that is not there gets a warning and an empty
 * cell. A WRITE that names one is refused outright, so the feature simply does
 * not work — and only against a real schema, because the file store accepts
 * whatever key it is handed. That is why this test exists and why it asserts
 * against the MIGRATIONS rather than against a fixture: a fixture can agree with
 * the same mistake, a blueprint cannot.
 *
 * HOW IT WORKS. The write paths are DRIVEN, not read. Every surface class that
 * stores a row is called through a store that records what it was asked to
 * write, and every recorded key is then checked against the blueprint the
 * migrations produce. Listing the expected keys by hand would be a second
 * description of the code that goes stale the first time somebody adds a field.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\Letters;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\LeaveRules;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\Register;
use SmartPRS\Surface\Requests;
use SmartPRS\Surface\Regularisation;
use SmartPRS\Surface\RowStore;
use SmartPRS\Surface\Settlement;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;
use SmartPRS\Surface\Trail;

group('write paths');

require_once __DIR__ . '/lib/laravel_schema_stub.php';

Illuminate\Support\Facades\Schema::reset();

foreach (glob(__DIR__ . '/../database/migrations/*.php') as $file) {
    (require $file)->up();
}

/** Every table the migrations create, as `table => [column, ...]`. */
$schema = [];

foreach (Illuminate\Support\Facades\Schema::$blueprints as $table => $blueprint) {
    $schema[$table] = $blueprint;
}

/**
 * A store that RECORDS what each write path asks it to store, and otherwise
 * behaves exactly like the one underneath.
 *
 * It does not itself refuse an unknown column: `SqlStore` does that, and this
 * suite has no database. Its job is to capture the keys so they can be held
 * against the blueprint below — the same check MySQL performs, run in a place
 * where a mistake costs a failing test rather than a broken screen.
 */
final class RecordingStore implements RowStore
{
    /** @var array<string, list<string>> table => every column name written to it */
    public static array $written = [];

    public function __construct(private readonly RowStore $inner)
    {
    }

    public function forTenant(string $tenantId): RowStore
    {
        return new self($this->inner->forTenant($tenantId));
    }

    public function tenantId(): ?string
    {
        return $this->inner->tenantId();
    }

    public function all(string $collection): array
    {
        return $this->inner->all($collection);
    }

    public function page(
        string $collection,
        string $orderBy,
        int $limit,
        int $offset = 0,
        string $direction = 'asc',
        array $searchColumns = [],
        string $needle = '',
    ): array {
        return $this->inner->page($collection, $orderBy, $limit, $offset, $direction,
            $searchColumns, $needle);
    }

    public function find(string $collection, string $id): ?array
    {
        return $this->inner->find($collection, $id);
    }

    public function put(string $collection, array $row): array
    {
        foreach (array_keys($row) as $column) {
            self::$written[$collection][] = (string) $column;
        }

        return $this->inner->put($collection, $row);
    }

    public function count(string $collection, array $searchColumns = [], string $needle = ''): int
    {
        return $this->inner->count($collection, $searchColumns, $needle);
    }

    public function aggregate(
        string $collection,
        string $column = '',
        array $where = [],
        string $groupBy = '',
    ): array {
        return $this->inner->aggregate($collection, $column, $where, $groupBy);
    }

    public function columnsOf(string $collection): array
    {
        return $this->inner->columnsOf($collection);
    }
}

$root = __DIR__ . '/..';
$files = new Store(sys_get_temp_dir() . '/prs3-writepaths-' . bin2hex(random_bytes(4)));
$store = (new RecordingStore($files))->forTenant('1');

// One employment, one of every leave type the policy names. `leaves.type_id`
// points at `leave_types`, so a request cannot be written without one.
$store->put('employees', ['id' => '17', 'emp_code' => 'SP-001', 'name' => 'Kavita Sharma',
    'ctc' => '600000.00', 'doj' => '2026-01-01', 'status' => 'active', 'address' => '12 MG Road',
    'designation' => 'Analyst', 'branch' => 'Hyderabad']);
$store->put('leave_types', ['id' => '1', 'name' => 'Earned leave']);
$store->put('leave_types', ['id' => '2', 'name' => 'Casual leave']);

$policy = new LeavePolicy($root . '/config/leave-policy.json');
$calendar = new Calendar($root . '/config/calendar.json');
// WITH THE PUBLISHED RULES, because that is how the application builds it and
// this file exists to drive the real write paths. Without them Leave::request()
// now refuses and says nothing checked the request — which is the safe reading
// of "no policy loaded" and exactly what this file should be exercising.
$leave = new Leave($store, $policy, new LeaveRules($root . '/config/leave-rules.json'));
$attendance = new Attendance($store);
$period = new PayrollPeriod($attendance, $leave, $policy, $calendar);
$regularisation = new Regularisation($store, $attendance);
$structure = new Structure($store, $root . '/config/salary-structure.json');
$register = new Register($store, $period, $structure);
$settlement = new Settlement($store, $leave, $policy, $calendar, $period,
    new SmartPRS\Surface\ComponentRules($store, dirname(__DIR__) . '/config/salary-components.json'));

// ---- drive every write path -------------------------------------------------

// Leave: the one that was known broken. `employment_id`, `from`, `to` and
// `state` are none of them columns on `leaves`.
$leave->openBalances('SP-001', '2026-01-01');
$leave->accrueThrough('SP-001', '2026-01-01', '2026-03-31');
$request = $leave->request('SP-001', 'EL', '2026-03-02', '2026-03-03', 'Family function');
$decided = $leave->decide((string) $request['id'], true, 'admin@smartprslocal', 'Cover arranged');

same(Leave::APPROVED, $decided['state'], 'a decided request reads back as approved');
same('2026-03-02', $decided['from'], 'and still carries its dates through the ported names');

// Attendance and regularisation, which write the two ported attendance tables.
$attendance->record('SP-001', '2026-03-04 09:31:00', 'in');
$attendance->record('SP-001', '2026-03-04 18:40:00', 'out');
same(2, count($attendance->punchesFor('SP-001')), 'a punch written in the schema\'s names reads back');

$raised = $regularisation->request('SP-001', '2026-03-05', '2026-03-05 18:35:00', 'out',
    'The reader missed the exit');
ok($raised['ok'], 'a regularisation is raised: ' . $raised['why']);
$settled = $regularisation->decide((string) $raised['request']['id'], true, 'admin@smartprslocal',
    'Confirmed with the team lead');
ok($settled['ok'], 'and decided: ' . $settled['why']);
same(3, count($attendance->punchesFor('SP-001')),
    'an approved regularisation ADDS a punch rather than editing one');

// The payroll register: close, correct, approve. `payroll_runs` is SmartPRS 2's
// schedule lot, and every name this class uses for a run differs from it.
$closed = $register->close('2026-03', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($closed['ok'], 'a month closes: ' . $closed['why']);
same('2026-03', (string) $closed['run']['period'], 'and reads back under the name the screens use');

$corrected = $register->correct('2026-03', 'A punch arrived late', 'maker@x',
    $calendar->shiftStart(), $calendar->shiftEnd());
ok($corrected['ok'], 'a correction is recorded: ' . $corrected['why']);

$approved = $register->approve('2026-03', 'checker@x', 'Checked against the attendance register');
ok($approved['ok'], 'and a second person approves it: ' . $approved['why']);
same('checker@x', (string) $approved['run']['approved_by'],
    'the approver reads back from `approved_by_name` — `approved_by` is a users.id, and a name in '
    . 'it stores as 0');

// Full and final, which writes the settlement and debits the encashed days.
$final = $settlement->settle('SP-001', '2026-03-31', 'admin@smartprslocal', 'Resigned');
ok($final['ok'], 'a settlement is recorded: ' . $final['why']);

// The surface's own tables, driven for the same reason: they are the ones a new
// field gets added to, and a field that appears to save and does not is found
// months later by whoever needed it.
$structurePublished = (new Structure($store, $root . '/config/salary-structure.json'))->publish(
    '2026-04-01', json_encode([['code' => 'C-BASIC', 'kind' => 'earning',
        'basis' => 'percent_of_gross', 'rate_percent' => 60, 'sequence' => 10]], JSON_THROW_ON_ERROR),
    'Basic raised to half of gross');
ok($structurePublished['ok'], 'a structure version is published: ' . $structurePublished['why']);

(new Trail($store))->record('leave.decided', 'leave_request', (string) $request['id'],
    ['state' => Leave::PENDING], ['state' => Leave::APPROVED], '1', 'admin@smartprslocal',
    'Decided through the leave screen');

$requests = new Requests($store, $root . '/config/workflows.json');
$raisedRequest = $requests->raise((string) $requests->workflows()[0]['key'], '17', '25000.00',
    'Two-wheeler purchase', '1', 'admin@smartprslocal');
ok($raisedRequest['ok'], 'a workflow request is raised: ' . $raisedRequest['why']);
$requestDecided = $requests->decide((string) $raisedRequest['request']['id'], true,
    'Within policy', '2', 'checker@x');
ok($requestDecided['ok'], 'and decided: ' . $requestDecided['why']);

$customisation = new Customisation($store);
$saved = $customisation->saveRaw('employees', ['label' => 'People'], '1', 'admin@smartprslocal',
    'The customer calls them people');
ok($saved['ok'], 'a module customisation is saved: ' . $saved['why']);

$letters = new Letters($store, $root . '/config/letters.json', $customisation);
$issued = $letters->issue($letters->all()[0], (array) $store->find('employees', '17'),
    // 15 Oct 2026: the offer letter also prints the training terms and the salary-structure annexure
    // (the screens fill both through letterContextFor()).
    'admin@smartprslocal', ['company' => 'Ametecs', 'training_terms' => 'No training period', 'salary_structure' => 'Salary structure annexure']);
ok($issued['ok'], 'a letter is issued: ' . $issued['why']);

// ---- the check ---------------------------------------------------------------

ok(RecordingStore::$written !== [], 'the write paths above actually wrote something');

foreach (RecordingStore::$written as $table => $columns) {
    $blueprint = $schema[$table] ?? null;

    ok($blueprint !== null, "the migrations create a `{$table}` table for the code that writes to it");

    if ($blueprint === null) {
        continue;
    }

    foreach (array_unique($columns) as $column) {
        // `id` and `tenant_id` are the store's own, on every table it writes.
        ok($column === 'id' || $blueprint->has($column), sprintf(
            'The code writes `%s`.`%s`, which the migrations do not create. A write to a column '
            . 'that does not exist is refused outright, so the feature does not work at all — and '
            . 'only against a real schema, which is why this is asserted here.',
            $table, $column));
    }
}

// ---- and the specific names that were wrong ---------------------------------
//
// Both directions on purpose. "The right column exists" passes just as well when
// the code writes the wrong one; "the wrong name is absent from the table" is
// what makes putting it back cost a failure.

/** @var array<string, array<string, string>> table => what the code said => what the table says */
$ported = [
    'leaves' => [
        'employment_id' => 'employee_id',
        'leave_type' => 'type_id',
        'from' => 'from_date',
        'to' => 'to_date',
        'state' => 'status',
        'requested_at' => 'created_at',
    ],
    'attendance_logs' => [
        'employment_id' => 'emp_code',
        'at' => 'punch_at',
        'source_reference' => 'external_id',
        'recorded_at' => 'created_at',
    ],
    'attendance_corrections' => [
        'employment_id' => 'emp_code',
        'date' => 'log_date',
        'direction' => 'sub_type',
        'state' => 'status',
        'note' => 'remarks',
        'requested_at' => 'created_at',
    ],
    'payroll_runs' => [
        'period' => 'cycle_label',
        'employee_count' => 'employees_count',
        'net' => 'net_total',
        'closed_at' => 'locked_at',
        'rows' => 'run_rows',
        'inputs' => 'run_inputs',
        'payable' => 'payable_amount',
        'arrears_total' => 'arrears_amount',
    ],
    // Surface tables, where the mistake was the other shape: writing a FORMATTED
    // or derived figure beside the stored one. `net_delta`, `total_minor`,
    // `final_pay_minor` and `encashment_total` are all derived on read now.
    'payroll_corrections' => ['net_delta' => 'net_delta_minor'],
    'settlements' => [
        'final_pay_minor' => 'final_pay',
        'total_minor' => 'total',
        'encashment_total' => 'encashments',
    ],
];

foreach ($ported as $table => $columns) {
    $blueprint = $schema[$table] ?? null;
    ok($blueprint !== null, "the migrations create `{$table}`");

    if ($blueprint === null) {
        continue;
    }

    foreach ($columns as $wrong => $right) {
        ok($blueprint->has($right), sprintf('`%s`.`%s` is the column that exists.', $table, $right));
        ok(!$blueprint->has($wrong), sprintf(
            '`%s`.`%s` must NOT be on the table. If both names existed neither would be '
            . 'authoritative, and the write path would keep working by accident.', $table, $wrong));
        ok(!in_array($wrong, RecordingStore::$written[$table] ?? [], true), sprintf(
            'and nothing writes `%s`.`%s` any more — a read may fall back to an old name, a write '
            . 'may not, because writing the old name IS the defect.', $table, $wrong));
    }
}
