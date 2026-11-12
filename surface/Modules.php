<?php

declare(strict_types=1);

namespace SmartPRS\Surface;


/**
 * EVERY MODULE IS A ROW IN A CONFIG FILE, NOT A CONTROLLER.
 *
 * SmartPRS 2 answers 408 routes from 90 controllers, one of which is 1.2 MB. The
 * shapes are nearly all the same — list the rows, search them, page through
 * them, open one, save it — and each is written out again, so a fix to paging
 * has to be made ninety times and is made in about sixty.
 *
 * Here a module DECLARES itself in `config/modules.json`: its table, the columns
 * worth showing, the ones worth searching. Adding a screen is an entry in that
 * file. That is the governing sentence of the rebuild made literal, and it is
 * why the list below is 79 modules rather than 8.
 *
 * A DECLARED COLUMN THAT DOES NOT EXIST IS A REFUSAL, not a skipped column. A
 * screen quietly missing a field is one nobody notices is broken — the person
 * looking at it does not know it should have been there.
 *
 * WHAT THIS IS NOT: a replacement for the screens that carry real behaviour. The
 * payroll register, the leave ledger, attendance settlement and full-and-final
 * are hand-built because their WORK is the point, not their table. This engine
 * covers the modules whose job is to show and edit rows, which is most of them,
 * and it is honest about the difference rather than pretending one screen shape
 * fits a payroll run.
 *
 * ponytail: no per-module field types, validation rules or relations yet — those
 * are the next fields in the same JSON, added when a module needs one rather
 * than designed for all 79 up front.
 */
final class Modules
{
    /** Columns that are links into Organization Setup, and the list each offers. */
    public const ORG_LOOKUPS = [
        'employer_company_id' => ['table' => 'companies', 'value' => 'id', 'show' => ['name']],
        'branch_id' => ['table' => 'branches', 'value' => 'id', 'show' => ['name']],
        'department_id' => ['table' => 'departments', 'value' => 'id', 'show' => ['name']],
        'designation_id' => ['table' => 'designations', 'value' => 'id', 'show' => ['name']],
        'team_id' => ['table' => 'teams', 'value' => 'id', 'show' => ['name']],
        // AN EMPLOYEE IS PICKED, NEVER TYPED (owner, 28 Sep: "NO blanks or free
        // texts where the dropdown values already exists"). Sixteen modules
        // declared no lookup for employee_id and rendered a bare number box.
        'employee_id' => ['table' => 'employees', 'value' => 'id', 'show' => ['emp_code', 'name']],
        'issued_to_employee_id' => ['table' => 'employees', 'value' => 'id', 'show' => ['emp_code', 'name']],
    ];

    /**
     * WHAT A SAME-NAMED ROW IS TOLD APART BY in a dropdown: two "Operations"
     * departments in two branches must not read as one option twice.
     */
    private const PARENTS = [
        'departments' => ['branch_id' => 'branches', 'employer_company_id' => 'companies'],
        'designations' => ['department_id' => 'departments', 'employer_company_id' => 'companies'],
        'branches' => ['employer_company_id' => 'companies'],
        'teams' => ['employer_company_id' => 'companies'],
    ];

    /** @var (\Closure(array<string, mixed>): bool)|null which employee rows this viewer may see */
    private ?\Closure $employeeVisible = null;

    /**
     * NARROW EVERY EMPLOYEE DROPDOWN AND EVERY PER-EMPLOYEE LIST to the viewer's
     * own reach (owner, 28 Sep: an employee sees only their own data; a manager
     * only their reporting tree). Set once by the front controller from Scope.
     *
     * @param \Closure(array<string, mixed>): bool $visible given an employees row
     */
    public function restrictEmployees(\Closure $visible): void
    {
        $this->employeeVisible = $visible;
    }

    /**
     * The column that says whose row this is, or null for a table that is not
     * about one employee. `employees` itself is keyed by its own id.
     */
    public function ownerColumn(array $module): ?string
    {
        if ((string) ($module['table'] ?? '') === 'employees') {
            return 'id';
        }

        $declared = array_keys((array) ($module['fields'] ?? []));
        $columns = $declared !== [] ? $declared : (array) ($module['columns'] ?? []);

        foreach (['employee_id', 'issued_to_employee_id'] as $candidate) {
            if (in_array($candidate, array_map('strval', $columns), true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** May this viewer see this row of this module? True for tables not about one employee. */
    public function rowVisible(array $module, array $row): bool
    {
        $column = $this->ownerColumn($module);

        if ($column === null || $this->employeeVisible === null) {
            return true;
        }

        $id = trim((string) ($row[$column] ?? ''));

        if ($id === '') {
            // A row about nobody in particular is an administrator's to see.
            return ($this->employeeVisible)(['id' => '']);
        }

        return ($this->employeeVisible)(['id' => $id]);
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $modules = null;

    /** @var callable(string): bool */
    private $may;

    /**
     * @param (callable(string): bool)|null $may whether the viewer holds a permission.
     *     Omitted, it DENIES every permissioned field rather than allowing them: a
     *     field-level lock that defaults to open is one that is open everywhere it
     *     was not explicitly wired, which is the whole class of bug this file keeps
     *     finding.
     */
    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
        private readonly ?Customisation $customisation = null,
        ?callable $may = null,
    ) {
        $this->may = $may ?? static fn (string $permission): bool => false;
    }

    /**
     * A COLUMN CAN BE LOCKED ON ITS OWN, not just the screen it sits on.
     *
     * `ctc` is on the employee list. The employee list is behind `employee.view`,
     * which a viewer holds, and salary is behind `employee.salary.view`, which
     * they do not — so locking the report and the stat tile and leaving the column
     * printed in the row gives the number back one line at a time. Found exactly
     * that way: the tile said "not yours to see" and the table beside it said
     * ₹42,00,000.
     *
     * A locked field is not rendered, not offered on the form, and NOT WRITABLE:
     * a field hidden from a template but accepted by the save is a lock on the
     * door of a room with no wall.
     */
    public function locked(array $field): bool
    {
        $permission = (string) ($field['permission'] ?? '');

        return $permission !== '' && !($this->may)($permission);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['modules'] ?? null)) {
            throw new \RuntimeException(
                "The module registry at {$this->file} is missing or unreadable. It is refused rather "
                . 'than treated as "no modules", which would render an application with an empty '
                . 'navigation and no indication that anything was wrong.'
            );
        }

        $modules = self::withDropIns($decoded['modules'], $this->file);

        // ONE HOOK, so nothing can read a module without its customer's version
        // of it. Applying the overlay in `fields()` instead would leave `rows()`
        // and the navigation showing the shipped labels and columns, which is
        // the customisation-half-applied bug: the list says one thing and the
        // form beneath it says another.
        return $this->modules = $this->customisation === null
            ? $modules
            : array_map($this->customisation->apply(...), $modules);
    }

    /**
     * DROP-IN DECLARATIONS: config/modules.d/<section>.json, each holding
     * {"modules": [...]} in the same shape, appended after the main file. A
     * module section ships as one file beside the others rather than as an
     * edit to one shared registry. A key declared twice: the drop-in wins,
     * because it is the more specific file.
     *
     * @param list<array<string, mixed>> $modules
     * @return list<array<string, mixed>>
     */
    private static function withDropIns(array $modules, string $file): array
    {
        $dir = dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '.d';
        $byKey = [];

        foreach ($modules as $m) {
            $byKey[(string) $m['key']] = $m;
        }

        foreach (glob($dir . '/*.json') ?: [] as $dropIn) {
            $more = json_decode((string) file_get_contents($dropIn), true);

            if (!is_array($more) || !is_array($more['modules'] ?? null)) {
                throw new \RuntimeException("The module drop-in {$dropIn} is not {\"modules\": [...]}.");
            }

            foreach ($more['modules'] as $m) {
                $byKey[(string) $m['key']] = $m;
            }
        }

        return array_values($byKey);
    }

    /** The module AS SHIPPED, before this customer's overrides — what a change is compared against. */
    public function shipped(string $key): ?array
    {
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        foreach (self::withDropIns((array) ($decoded['modules'] ?? []), $this->file) as $module) {
            if ((string) $module['key'] === $key) {
                return $module;
            }
        }

        return null;
    }

    /** @return array<string, list<array<string, mixed>>> group => modules, in declared order */
    public function byGroup(): array
    {
        $groups = [];

        foreach ($this->all() as $module) {
            $groups[(string) $module['group']][] = $module;
        }

        return $groups;
    }

    public function find(string $key): ?array
    {
        foreach ($this->all() as $module) {
            if ((string) $module['key'] === $key) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Rows for a module, searched and paged.
     *
     * THE LIST PAGES IN THE STORE, NOT IN PHP. It used to read the whole table
     * and slice it — 0.51s and 68 MB at 40,000 rows, a fatal error at half a
     * million — for the 25 rows a person is looking at. The order comes from the
     * module's declared `order` column, defaulting to `id`: a page needs one to
     * be reproducible, and an order the table cannot honour is REPORTED on the
     * screen through `why` rather than silently replaced.
     *
     * SEARCH PAGES IN THE STORE TOO, and this is the half that mattered. The
     * unsearched list was bounded and the searched one fell back to `all()`, so
     * the screen was fast until somebody used it — the worst possible
     * arrangement, and one keystroke away on every list in the product. The
     * declared `search` columns go into the store as a WHERE, checked against the
     * real schema and REFUSED by name if one is not there: a search that quietly
     * drops a column returns fewer rows and every one of them looks right.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int,
     *     columns: list<string>, order: string, bounded: bool, why: ?string}
     */
    public function rows(array $module, string $query = '', int $page = 1, int $perPage = 25): array
    {
        $columns = array_map('strval', (array) $module['columns']);
        $search = array_map('strval', (array) $module['search']);
        $table = (string) $module['table'];
        $order = (string) ($module['order'] ?? 'id');

        // A query with nothing declared to search it in searches nothing, rather
        // than searching everything or being ignored: the module said which
        // columns are searchable and the answer is that none are.
        $searching = $query !== '' && $search !== [];
        $needle = $searching ? $query : '';
        $in = $searching ? $search : [];

        // A PER-EMPLOYEE TABLE, READ BY SOMEBODY WHO DOES NOT SEE EVERYONE, is
        // filtered row by row and paged here: the store pages the whole table, and
        // a page of other people's leave with the rows blanked out is still a
        // count of other people's leave.
        // ONE TABLE, TWO SCREENS (module option `fixed`, 30 Oct: Commission Setup / Incentive Setup share
        // incentive_schemes): only rows holding the fixed values are listed, and Save writes them.
        $fixed = array_map('strval', (array) ($module['fixed'] ?? []));
        $perRow = $this->employeeVisible !== null && $this->ownerColumn($module) !== null
            && !($this->employeeVisible)(['id' => '__all__']);

        if ($perRow || $fixed !== []) {
            try {
                $all = array_values(array_filter($this->store->all($table), fn (array $r): bool
                    => (!$perRow || $this->rowVisible($module, $r))
                    && array_filter($fixed, static fn (string $v, string $k): bool => strcasecmp(trim((string) ($r[$k] ?? '')), $v) !== 0, ARRAY_FILTER_USE_BOTH) === []
                    && ($needle === '' || array_filter($in, static fn (string $c): bool
                        => stripos((string) ($r[$c] ?? ''), $needle) !== false) !== [])));
                usort($all, static fn (array $a, array $b): int
                    => strnatcmp((string) ($a[$order] ?? ''), (string) ($b[$order] ?? '')));
                $total = count($all);
                $pages = max(1, (int) ceil($total / $perPage));
                $page = max(1, min($page, $pages));

                return ['rows' => array_slice($all, ($page - 1) * $perPage, $perPage), 'total' => $total,
                    'page' => $page, 'pages' => $pages, 'columns' => $columns, 'order' => $order,
                    'bounded' => true, 'why' => null];
            } catch (\Throwable $e) {
                return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'columns' => $columns,
                    'order' => $order, 'bounded' => true, 'why' => $e->getMessage()];
            }
        }

        try {
            $total = $this->store->count($table, $in, $needle);
            $pages = max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $pages));

            return [
                'rows' => $this->store->page($table, $order, $perPage, ($page - 1) * $perPage,
                    'asc', $in, $needle),
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'columns' => $columns,
                'order' => $order,
                'bounded' => true,
                'why' => null,
            ];
        } catch (\Throwable $e) {
            // The reason reaches the screen. "No rows" and "that table is not
            // there" look identical to a person and mean opposite things.
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'columns' => $columns,
                'order' => $order, 'bounded' => true, 'why' => $e->getMessage()];
        }
    }

    /**
     * Retire a row — never delete it. A table with `deleted_at` gets the stamp;
     * one with a `status` column gets a retired value from its own vocabulary;
     * anything else is refused, because a row payroll or attendance may point at
     * cannot simply vanish.
     *
     * @return array{ok: bool, why: string}
     */
    /** Two decimal strings compared exactly, to four places, without a float. */
    public static function decimalCompare(string $a, string $b): int
    {
        $scaled = static function (string $n): int {
            $neg = str_starts_with($n, '-');
            [$whole, $frac] = array_pad(explode('.', ltrim($n, '+-'), 2), 2, '');
            $v = (int) ($whole === '' ? '0' : $whole) * 10000 + (int) substr(str_pad($frac, 4, '0'), 0, 4);

            return $neg ? -$v : $v;
        };

        return $scaled($a) <=> $scaled($b);
    }

    /** Deleted, switched off or marked inactive/retired/archived — kept in history, offered nowhere. */
    public static function isRetired(array $row): bool
    {
        return (string) ($row['deleted_at'] ?? '') !== ''
            || (array_key_exists('is_active', $row) && (string) $row['is_active'] === '0')
            || in_array(strtolower((string) ($row['status'] ?? '')), ['inactive', 'retired', 'archived'], true);
    }

    public function retire(array $module, string $id): array
    {
        $table = (string) $module['table'];
        $row = $this->store->find($table, $id);

        if ($row === null) {
            return ['ok' => false, 'why' => 'No such row.'];
        }

        if (in_array('deleted_at', $this->store->columnsOf($table), true)) {
            $this->store->put($table, ['id' => $id, 'deleted_at' => date('Y-m-d H:i:s')]);

            return ['ok' => true, 'why' => "Row {$id} removed from {$module['label']} (kept in history)."];
        }

        if (in_array('status', $this->store->columnsOf($table), true)) {
            // Stored VALUES, not labels: a value => label map ("retired" => "Retired") retires to its key.
            $statusOptions = (array) ($module['fields']['status']['options'] ?? []);
            $options = array_map('strval', array_is_list($statusOptions) ? $statusOptions : array_keys($statusOptions));
            $retired = array_values(array_intersect(['retired', 'inactive', 'archived', 'closed', 'cancelled'], $options))[0] ?? 'inactive';
            $this->store->put($table, ['id' => $id, 'status' => $retired]);

            return ['ok' => true, 'why' => "Row {$id} marked {$retired} — it stops being offered; nothing that already names it is touched."];
        }

        return ['ok' => false, 'why' => "{$module['label']} rows cannot be removed: other records may point at them and the table has no way to retire one. Correct it instead."];
    }

    /** Words that mean the same thing on every status column in the product. */
    private const STATUS_TINTS = [
        'active' => 'green', 'approved' => 'green', 'verified' => 'green', 'paid' => 'green',
        'completed' => 'green', 'issued' => 'green', 'joined' => 'green', 'closed' => 'green',
        'selected' => 'green', 'confirmed' => 'green',
        'pending' => 'amber', 'submitted' => 'amber', 'probation' => 'amber', 'draft' => 'amber',
        'open' => 'amber', 'partial' => 'amber', 'applied' => 'amber', 'screening' => 'amber',
        'rejected' => 'red', 'expired' => 'red', 'exited' => 'red', 'failed' => 'red',
        'cancelled' => 'red', 'absent' => 'red', 'terminated' => 'red', 'lapsed' => 'red',
        'notice' => 'blue', 'on hold' => 'blue', 'processing' => 'blue', 'interviewing' => 'blue',
        'shortlisted' => 'blue', 'offered' => 'purple', 'suspended' => 'purple',
    ];

    /**
     * HOW A LIST SHOULD LOOK, derived from the columns and overridden by the
     * declaration.
     *
     * SmartPRS 2 writes this out per screen: the avatar tint array, the status
     * badge map and the rupee formatting are copied into `employees/index`,
     * `payslips/index`, `loans/index` and the rest, so a status colour that is
     * wrong is wrong in one screen and right in the others. Here it is derived
     * once from the column names — anything called status or state becomes a
     * badge, anything that reads as money is formatted as rupees — and a module
     * that wants something different says so in `display`.
     *
     * DERIVED, NOT REQUIRED. Eighty-four modules will not each get a hand-written
     * presentation block, and a scheme that needs one is a scheme that stays
     * unapplied on the sixty modules nobody got to.
     *
     * @return array{avatar: ?string, subtitle: ?string, badges: array<string, array<string,string>>,
     *     money: list<string>, stats: list<array<string, mixed>>}
     */
    public function display(array $module): array
    {
        $declared = (array) ($module['display'] ?? []);
        $columns = array_map('strval', (array) $module['columns']);
        $badges = [];
        $money = [];

        foreach ($columns as $column) {
            if (preg_match('/(^|_)(status|state|stage|kind|type)$/', $column) === 1) {
                $badges[$column] = self::STATUS_TINTS;
            }

            if (preg_match(
                '/(amount|ctc|gross|net|salary|total|outstanding|balance|paid|due|cost|fee|value)/',
                $column,
            ) === 1) {
                $money[] = $column;
            }
        }

        // The first column that reads like a name carries the avatar, because a
        // list of rows with an id in the first cell is a database browser.
        $avatar = null;

        foreach (['name', 'label', 'title', 'full_name', 'employee_name'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $avatar = $candidate;

                break;
            }
        }

        return [
            'avatar' => $declared['avatar'] ?? $avatar,
            'subtitle' => $declared['subtitle'] ?? null,
            'badges' => (array) ($declared['badges'] ?? $badges),
            'money' => array_map('strval', (array) ($declared['money'] ?? $money)),
            'stats' => (array) ($declared['stats'] ?? []),
        ];
    }

    /**
     * The little figures above a list.
     *
     * Each is declared with a measure and an optional filter, and — where it
     * totals money — the permission it sits behind. A stat is an aggregate of
     * rows, which is the same thing a report is, and the dashboard already taught
     * this lesson: a summary nobody thinks of as data is the easiest place to
     * hand back a figure the rest of the product locks.
     *
     * @param callable(string): bool $may
     * @return list<array<string, mixed>>
     */
    public function stats(array $module, callable $may): array
    {
        $spec = $this->display($module)['stats'];

        if ($spec === []) {
            return [];
        }

        $table = (string) $module['table'];
        $out = [];
        // A TENANT-WIDE TOTAL OVER OTHER PEOPLE'S ROWS is withheld from somebody
        // who sees only some of them (28 Sep) — a count of everybody's leave is
        // still information about everybody.
        $partial = $this->employeeVisible !== null && $this->ownerColumn($module) !== null
            && !($this->employeeVisible)(['id' => '__all__']);

        foreach ($spec as $stat) {
            $permission = (string) ($stat['permission'] ?? '');

            if ($partial || ($permission !== '' && !$may($permission))) {
                $out[] = $stat + ['value' => null, 'locked' => true];

                continue;
            }

            $sum = (string) ($stat['measure'] ?? 'count') === 'sum';

            // COUNTED AND SUMMED WHERE THE ROWS ARE. This used to read the whole
            // table once and filter it in PHP per tile — 0.51s and 68 MB at
            // 40,000 rows to print four numbers.
            try {
                $bucket = $this->store->aggregate($table, $sum ? (string) $stat['column'] : '',
                    (array) ($stat['where'] ?? []))[0] ?? ['count' => 0, 'sum' => '0.00'];
            } catch (\Throwable) {
                // One tile that cannot be worked out does not take the other
                // three off the screen with it.
                $out[] = $stat + ['value' => null, 'locked' => false];

                continue;
            }

            // A COUNT IS AN INT; A SUM IS EXACT MINOR UNITS RENDERED AS A
            // DECIMAL STRING. array_sum() over float casts was a module stat
            // ("total CTC on this screen") accumulated in float, next to a
            // report screen that totals the same column exactly — two answers
            // to one question is how a figure stops being trusted.
            $out[] = $stat + ['value' => $sum ? $bucket['sum'] : $bucket['count'], 'locked' => false];
        }

        return $out;
    }

    /**
     * The columns a module can be edited through — every real column except the
     * ones the store owns.
     *
     * @return list<string>
     */
    public function editable(array $module): array
    {
        // `uuid` is the store's, like `id`: it is identity, not a field somebody
        // fills in, and a form that offers it invites a value that breaks a
        // unique index.
        $owned = ['id', 'uuid', 'tenant_id', 'created_at', 'updated_at', 'deleted_at'];

        return array_values(array_diff($this->columnsOf($module), $owned));
    }

    /**
     * Every column the module's table really has.
     *
     * Asked of the STORE, not of an empty row: reading the columns off the first
     * row means an empty table has no fields, so a module can never be filled in
     * for the first time — the bug where a screen works only once it already has
     * data.
     *
     * @return list<string>
     */
    private function columnsOf(array $module): array
    {
        try {
            $columns = $this->store->columnsOf((string) $module['table']);
        } catch (\Throwable) {
            $columns = [];
        }

        if ($columns !== []) {
            return $columns;
        }

        // EMPTY IS NOT AN ANSWER HERE. A file store with no rows yet has no
        // columns to report, and a form built from that has no fields — so the
        // first row can never be added, which is the one moment the screen has
        // to work. The declaration is the fallback: it is what somebody wrote
        // down as belonging on this module, and it exists before any data does.
        return array_values(array_unique(array_merge(
            array_map('strval', (array) $module['columns']),
            array_map('strval', array_keys((array) ($module['fields'] ?? []))),
        )));
    }

    /**
     * HOW EACH FIELD IS EDITED — derived from the column, overridden by the
     * module's declaration.
     *
     * The derivation means every module has usable fields the moment its table
     * exists, with no per-module work: a date column gets a date picker, a
     * boolean gets a checkbox, a long text gets a textarea. The OVERRIDE is the
     * customisation the master prompt asks for — a label in the customer's own
     * words, a select with the values they actually use, a field marked required
     * or read-only — and it is a few lines of JSON, not a form to write.
     *
     * A declared field the table does not have is REPORTED on the screen, never
     * silently dropped. A customisation that quietly does nothing is worse than
     * one that fails, because the person who wrote it believes it took.
     *
     * @return array{fields: list<array<string, mixed>>, unknown: list<string>}
     */
    public function fields(array $module): array
    {
        $declared = (array) ($module['fields'] ?? []);
        $real = $this->columnsOf($module);
        $types = $this->store instanceof SqlStore
            ? $this->store->columnTypes((string) $module['table'])
            : [];
        // NULL, OR NOT WORTH ASKING. `null` (not an empty array) means "this
        // store cannot say" — the file-backed `Store` has no such constraint to
        // report — so `save()` below keeps its old behaviour there rather than
        // reading "not in this empty list" as "not nullable".
        $nullable = method_exists($this->store, 'nullableColumns')
            ? $this->store->nullableColumns((string) $module['table'])
            : null;

        $fields = [];

        // DECLARING FIELDS CHOOSES THEM. `employees` has 81 columns; a form
        // offering all of them is not a customised screen, it is a database
        // browser. So a module that declares `fields` shows exactly those, in
        // the order declared — and one that declares none shows everything,
        // which is the useful default for a module nobody has got to yet.
        $columns = $declared === []
            ? $this->editable($module)
            : array_values(array_intersect(array_keys($declared), $this->editable($module)));

        foreach ($columns as $column) {
            $spec = (array) ($declared[$column] ?? []);
            $sqlType = strtolower((string) ($types[$column] ?? 'varchar'));
            // ORGANISATION LINKS ARE PICKED FROM THE ORGANISATION SETUP LISTS BY
            // DEFAULT. A column named employer_company_id, branch_id, department_id,
            // designation_id or team_id offers the rows of that setup table — a
            // free-text box for a company id is a typo waiting for payroll to find.
            // A module may still declare its own `lookup`, which wins.
            $lookup = $this->lookup((array) ($spec['lookup'] ?? (self::ORG_LOOKUPS[$column]
                ?? (($spec['options'] ?? []) === [] ? $this->textLookup((string) $module['table'], $column) : []))));

            $fields[] = [
                'name' => $column,
                'permission' => (string) ($spec['permission'] ?? ''),
                'label' => (string) ($spec['label'] ?? self::humanise($column)),
                // A `month` column is a month picker, YYYY-MM (7 Oct) — never free text.
                'type' => (string) ($spec['type'] ?? ($column === 'month' ? 'month' : self::typeFor($column, $sqlType))),
                // A FORMAT A VALUE MUST MATCH (regex) and the words for it.
                'pattern' => (string) ($spec['pattern'] ?? ($column === 'month' ? '^\\d{4}-(0[1-9]|1[0-2])$' : '')),
                'pattern_hint' => (string) ($spec['pattern_hint'] ?? ($column === 'month' ? 'a month as YYYY-MM, e.g. 2026-10' : '')),
                // REQUIRED ONLY WHEN another field holds one of these values:
                // {"basis": ["percent", "days"]} (7 Oct).
                'required_when' => (array) ($spec['required_when'] ?? []),
                // SHOWN ONLY WHEN another field holds one of these values (28 Oct): {"payout_method": ["Separate payout …"]}.
                'show_when' => (array) ($spec['show_when'] ?? []),
                // SET BY ANOTHER FIELD (29 Oct): {"basis": {"Manual": "Open"}} — Paid on = Manual makes How it pays = Open.
                'set_when' => (array) ($spec['set_when'] ?? []),
                // A LABEL THAT FOLLOWS ANOTHER FIELD (29 Oct): {"rate_type": {"Percent": "Rate (% …)", "Fixed": "Flat ₹ …"}}.
                'label_when' => (array) ($spec['label_when'] ?? []),
                // SLAB BANDS' "UP TO" HEADING (3 Nov): e.g. "Achievement up to (%)" on a target-based Commission Setup.
                'upto_label' => (string) ($spec['upto_label'] ?? ''),
                // WORKED OUT, NEVER TYPED (30 Oct): ["old_ctc", "new_ctc"] — this field is (new − old) / old × 100.
                'percent_change' => array_values(array_map('strval', (array) ($spec['percent_change'] ?? []))),
                // THE COLUMN'S OWN TYPE, carried alongside the input type. They are
                // not the same question: `type` decides which control is rendered,
                // and this decides what the DATABASE will accept. A json column
                // renders as a textarea, which is right — and a textarea will
                // cheerfully take "PROBE", which MySQL then rejects with a CHECK
                // constraint the person never heard of. Four modules did exactly
                // that, and the person filling the form got SQLSTATE[23000] 4025.
                'sql_type' => $sqlType,
                'required' => (bool) ($spec['required'] ?? false),
                'readonly' => (bool) ($spec['readonly'] ?? false),
                'hidden' => (bool) ($spec['hidden'] ?? false),
                'options' => $this->withAdditions($spec),
                // "+ ADD NEW" ON A DROPDOWN (owner, 2 Nov 2026): `addable: "<list>"` — values added on screen are kept
                // under that list name (Customisation key dropdown-additions), so every field naming it offers them.
                'addable' => (string) ($spec['addable'] ?? ''),
                // `lookup` stays exactly what it was — the list of options — so
                // nothing rendering it has to change to keep working. What is NEW
                // is `lookup_bounds`, which says whether that list is the whole
                // table. A screen that does not render it shows a complete list
                // as before; a screen that does can say "500 of 1,240" and offer
                // free entry instead of a select. Bolted on beside rather than
                // folded in, because a truncated list nested one key deeper is a
                // shape change every caller must notice, and the ones that do not
                // notice fail silently — which is the defect, not the fix.
                'lookup' => $lookup['options'],
                'lookup_bounds' => array_diff_key($lookup, ['options' => null]),
                'help' => (string) ($spec['help'] ?? ''),
                'nullable' => $nullable === null || in_array($column, $nullable, true),
                // WHAT AN EMPTY DROPDOWN MEANS. An org-scope column (company,
                // branch, department, designation, team, employee) left blank
                // means ALL; anything else means none. Declare "empty" to say otherwise.
                'empty' => (string) ($spec['empty'] ?? (isset(self::ORG_LOOKUPS[$column]) || $column === 'employee_id' ? 'ALL' : '— none —')),
                'min' => isset($spec['min']) ? (string) $spec['min'] : null,
                'max' => isset($spec['max']) ? (string) $spec['max'] : null,
            ];
        }

        return [
            'fields' => array_values(array_filter($fields, fn (array $f): bool
                => !$f['hidden'] && !$this->locked($f))),
            'locked' => array_values(array_map(static fn (array $f): string => $f['name'],
                array_filter($fields, fn (array $f): bool => $this->locked($f)))),
            'unknown' => array_values(array_diff(array_keys($declared), $real)),
        ];
    }

    /**
     * A TEXT COLUMN THAT NAMES AN ORG ENTITY (config/module-text-lookups.json)
     * is offered as a dropdown of that register's names (28 Sep).
     *
     * @return array<string, mixed>
     */
    private function textLookup(string $table, string $column): array
    {
        static $declared = null;
        $file = dirname($this->file) . '/module-text-lookups.json';
        $declared ??= (is_file($file) ? json_decode((string) file_get_contents($file), true) : null) ?: [];

        if (in_array($table, (array) ($declared['skip_tables'] ?? []), true)) {
            return [];
        }

        // "table.column" names one register's column; a bare column name, every register's.
        return (array) (($declared['columns'] ?? [])["{$table}.{$column}"] ?? ($declared['columns'] ?? [])[$column] ?? []);
    }

    /** Where a browser `<select>` stops being usable — and where the read stops being cheap. */
    private const LOOKUP_CAP = 500;

    /**
     * A dropdown whose values come from ANOTHER table.
     *
     * `employee_id` showing 47 is a screen that makes somebody open a second one
     * to find out who 47 is. The lookup is declared — which table, which column
     * is the value, which is the label — rather than guessed from the column
     * name, because `reporting_manager_id` points at `employees` and no naming
     * convention gets that right.
     *
     * The list is READ THROUGH THE STORE, so it is tenant-scoped like everything
     * else: a dropdown is the easiest place to leak another customer's names.
     *
     * IT IS COMPLETE, OR IT SAYS IT IS NOT. A dropdown silently showing 50 of 500
     * people is a defect, not an optimisation: somebody picks the wrong Sharma,
     * or concludes an employee does not exist and creates a duplicate. So below
     * the cap the list is the WHOLE table — which is what a picker is for — and
     * above it the first `LOOKUP_CAP` by label with `complete => false`, `total`
     * and `shown` in the answer, so the screen can say so and offer the field as
     * free entry instead. The missing rows stay REACHABLE: `problems()` validates
     * a typed id against the store rather than against the truncated list, so a
     * value that is not on screen still saves and one that does not exist still
     * does not.
     *
     * ponytail: the cap is a count, not a search-as-you-type endpoint. 500 is
     * where a browser `<select>` stops being usable, which is the same place the
     * read stops being cheap; the upgrade path when a customer has 5,000
     * employees is `page()` behind a typeahead, using the search WHERE that
     * `rows()` already goes through.
     *
     * @return array{options: list<array{id: string, label: string}>, complete: bool,
     *     total: int, shown: int, table: string, value: string}
     */
    private function lookup(array $spec): array
    {
        $table = (string) ($spec['table'] ?? '');
        $value = (string) ($spec['value'] ?? 'id');
        $show = (array) ($spec['show'] ?? ['name']);
        $none = ['options' => [], 'complete' => true, 'total' => 0, 'shown' => 0,
            'table' => '', 'value' => ''];

        if ($spec === [] || $table === '') {
            return $none;
        }

        try {
            $total = $this->store->count($table);
            $complete = $total <= self::LOOKUP_CAP;
            $rows = $complete
                ? $this->store->all($table)
                // Ordered by what the option READS AS where that is a real
                // column, so "the first 500" is the first 500 alphabetically
                // rather than the first 500 by insertion — which is a list a
                // person cannot reason about at all.
                : $this->store->page($table, $this->labelOrder($table, $show, $value),
                    self::LOOKUP_CAP);
        } catch (\Throwable) {
            // An empty dropdown, not a crashed screen — and the field keeps its
            // label so the person can see what is missing.
            return array_merge($none, ['table' => $table, 'value' => $value]);
        }

        $options = [];
        $seenDistinct = [];
        // BY DESIGNATION: "designation": "manager" on the lookup keeps only the
        // employees whose job title says so — a team's manager is picked from
        // managers, not from the whole directory.
        $designation = strtolower(trim((string) ($spec['designation'] ?? '')));

        // …UNLESS NOBODY'S TITLE SAYS SO, in which case the filter is dropped
        // rather than the list left empty (owner, 28 Sep: no blank dropdowns). A
        // company whose managers are called "Head" or "Lead" still gets a list.
        if ($designation !== '' && array_filter($rows, fn (array $r): bool
            => !self::isRetired($r) && $this->holdsDesignation($r, $designation)) === []) {
            $designation = '';
        }

        foreach ($rows as $row) {
            // A RETIRED ROW IS NOT OFFERED. Deleted (deleted_at) or switched off
            // (is_active = 0 / status inactive|retired) rows stay in history and
            // stay out of every dropdown — a retired branch offered twice was the
            // first thing the owner saw.
            if (self::isRetired($row) || ($designation !== '' && !$this->holdsDesignation($row, $designation))
                || (isset($spec['filled']) && trim((string) ($row[(string) $spec['filled']] ?? '')) === '')) {
                continue;   // `filled`: only rows with that column set (e.g. schemes from Commission / Incentive Setup)
            }

            // `distinct`: one option per value (a Role list read off the designations).
            if (!empty($spec['distinct'])) {
                $dv = strtolower(trim((string) ($row[$value] ?? '')));
                if ($dv === '' || isset($seenDistinct[$dv])) {
                    continue;
                }
                $seenDistinct[$dv] = true;
            }

            // AN EMPLOYEE THIS VIEWER MAY NOT SEE IS NOT OFFERED EITHER — a
            // dropdown is a list of names.
            if ($table === 'employees' && $this->employeeVisible !== null && !($this->employeeVisible)($row)) {
                continue;
            }

            $parts = [];

            foreach ($show as $column) {
                if (($row[$column] ?? null) !== null && (string) $row[$column] !== '') {
                    $parts[] = (string) $row[$column];
                }
            }

            $options[] = [
                'id' => (string) ($row[$value] ?? ''),
                'label' => $parts === [] ? '#' . (string) ($row[$value] ?? '?') : implode(' — ', $parts),
            ];
        }

        // NO TWO OPTIONS READ THE SAME. A duplicated label gets its parent's name
        // (the branch of a department, the company of a branch) and, failing that,
        // its id — the one that is picked is then the one that was meant.
        $labels = array_count_values(array_column($options, 'label'));

        if (max($labels ?: [0]) > 1) {
            $byId = [];

            foreach ($rows as $row) {
                $byId[(string) ($row[$value] ?? '')] = $row;
            }

            foreach ($options as $i => $option) {
                if (($labels[$option['label']] ?? 0) < 2) {
                    continue;
                }

                $qualifier = '';

                foreach (self::PARENTS[$table] ?? [] as $column => $parentTable) {
                    $parentId = (string) ($byId[$option['id']][$column] ?? '');

                    if ($parentId !== '' && ($parent = $this->store->find($parentTable, $parentId)) !== null) {
                        $qualifier = trim((string) ($parent['name'] ?? ''));

                        break;
                    }
                }

                $options[$i]['label'] = $option['label'] . ' (' . ($qualifier !== '' ? $qualifier : '#' . $option['id']) . ')';
            }
        }

        usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return ['options' => $options, 'complete' => $complete, 'total' => $total,
            'shown' => count($options), 'table' => $table, 'value' => $value];
    }

    /** @var array<string, string>|null designation id => name, read once per request */
    private ?array $designationNames = null;

    /** Whether an employee's job title contains the words — by designation_id through the Designations register, or by the legacy text column. */
    public function holdsDesignation(array $employee, string $needle): bool
    {
        if ($this->designationNames === null) {
            $this->designationNames = [];

            try {
                foreach ($this->store->all('designations') as $d) {
                    // THE TITLE AND ITS JOB ROLE (28 Sep): a designation called
                    // "Executive" whose Role is "HR Admin" is an HR approver too.
                    $this->designationNames[(string) $d['id']] = strtolower(trim((string) ($d['name'] ?? $d['title'] ?? '')
                        . ' ' . (string) ($d['role'] ?? '')));
                }
            } catch (\Throwable) { // gate:allow-swallow no designations table on this installation — the legacy text column alone decides
            }
        }

        $title = $this->designationNames[(string) ($employee['designation_id'] ?? '')] ?? strtolower((string) ($employee['designation'] ?? ''));

        return $title !== '' && str_contains($title, strtolower($needle));
    }

    /**
     * The column a truncated lookup is ordered by: the first `show` column the
     * table really has, falling back to the value column and then to `id`.
     *
     * Never a guess that the store would refuse — an unknown order column is a
     * refusal there, and a dropdown is not the place to discover it.
     */
    private function labelOrder(string $table, array $show, string $value): string
    {
        $columns = $this->store->columnsOf($table);

        foreach ([...array_map('strval', $show), $value, 'id'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $value;
    }

    /**
     * The permission a module needs. Declared per module, because "can see the
     * employee list" and "can see everybody's salary" are not the same
     * permission, and giving them one name is how the second gets granted by
     * somebody who meant the first.
     */
    public function permission(array $module, string $action): string
    {
        $declared = (array) ($module['permissions'] ?? []);

        // MODULES THAT HOLD OTHER PEOPLE'S DATA BUT DECLARED ONLY THE DEFAULT
        // `employee.view` — which every employee login holds — are guarded by
        // config/module-view-guards.json (owner, 28 Sep: an employee sees only
        // their own data). A guard there wins for `view`.
        if ($action === 'view') {
            static $guards = null;
            $file = dirname($this->file) . '/module-view-guards.json';
            $guards ??= (array) ((is_file($file) ? json_decode((string) file_get_contents($file), true) : null)['view'] ?? []);
            $key = (string) ($module['key'] ?? '');

            if ($key !== '' && isset($guards[$key])) {
                return (string) $guards[$key];
            }
        }

        return (string) ($declared[$action] ?? match ($action) {
            'view' => 'employee.view',
            default => 'employee.update',
        });
    }

    /** A column name as a person would write it, until somebody declares better. */
    private static function humanise(string $column): string
    {
        $words = str_replace('_', ' ', preg_replace('/_id$/', '', $column) ?? $column);

        return ucfirst(trim($words));
    }

    /** The input a column type deserves, before any declaration overrides it. */
    private static function typeFor(string $column, string $sqlType): string
    {
        return match (true) {
            str_contains($sqlType, 'date') && !str_contains($sqlType, 'datetime') => 'date',
            str_contains($sqlType, 'datetime') || str_contains($sqlType, 'timestamp') => 'datetime-local',
            str_contains($sqlType, 'time') => 'time',
            $sqlType === 'tinyint' => 'checkbox',
            str_contains($sqlType, 'int') || str_contains($sqlType, 'decimal') => 'number',
            str_contains($sqlType, 'text') || str_contains($sqlType, 'json') => 'textarea',
            str_contains($column, 'email') => 'email',
            str_contains($column, 'mobile') || str_contains($column, 'phone') => 'tel',
            default => 'text',
        };
    }

    /**
     * Check a row against the module's declared rules BEFORE it reaches storage.
     *
     * @return list<string> what is wrong, empty when nothing is
     */
    public function problems(array $module, array $input): array
    {
        $problems = [];
        // WHAT IS ALREADY ON FILE (13 Oct): an Edit re-posts every saved value, and a
        // value that was valid when saved (a since-retired branch, say) must not make
        // the whole row unsaveable. Only a CHANGED lookup value is checked.
        $onFile = [];
        if ((string) ($input['id'] ?? '') !== '') {
            try { $onFile = (array) ($this->store->find((string) $module['table'], (string) $input['id']) ?? []); } catch (\Throwable) { $onFile = []; }
        }

        foreach ($this->fields($module)['fields'] as $field) {
            $value = trim((string) ($input[$field['name']] ?? ''));

            if ($field['required'] && $value === '') {
                $problems[] = $field['label'] . ' is required.';

                continue;
            }

            foreach ($field['required_when'] ?? [] as $other => $when) {
                $otherValue = trim((string) ($input[$other] ?? ''));

                if ($value === '' && in_array($otherValue, array_map('strval', (array) $when), true)) {
                    // Named by the other field's own label ("Paid on"), not its column ("Basis") — 29 Oct.
                    $otherSpec = array_column($this->fields($module)['fields'], null, 'name')[(string) $other] ?? null;
                    $otherLabel = (string) ($otherSpec['label'] ?? self::humanise((string) $other));
                    $otherOpts = (array) ($otherSpec['options'] ?? []);
                    $shown = !array_is_list($otherOpts) && isset($otherOpts[$otherValue]) ? (string) $otherOpts[$otherValue] : $otherValue;
                    $problems[] = $field['label'] . ' is required when ' . $otherLabel . ' is "' . $shown . '".';

                    continue 2;
                }
            }

            // WHAT THE COLUMN WILL ACCEPT, CHECKED HERE RATHER THAN BY THE DRIVER.
            //
            // The point of this method is that a refusal names the FIELD in the
            // customer's own words. A value MySQL rejects skips all of that and
            // arrives as `SQLSTATE[23000]: Integrity constraint violation: 4025
            // CONSTRAINT `settings.value` failed` — a sentence that tells the
            // person nothing they can act on and names a constraint they have
            // never seen. Found by a sweep that tried to save a row through every
            // one of the eighty-four modules: five of them failed this way.
            if ($value !== '') {
                $sqlType = (string) ($field['sql_type'] ?? '');

                // The DECLARED type counts as well as the schema's. MariaDB stores a
                // JSON column as longtext with a `json_valid` CHECK, and the
                // application account cannot read information_schema.CHECK_CONSTRAINTS
                // — so on that server the schema genuinely cannot say. Widening the
                // account's grants to find out would trade a real privilege boundary
                // for a nicety. The registry is data and can simply say so, which is
                // what `"type": "json"` on the field means; MySQL 8 answers `json`
                // from the schema and both routes agree.
                if ((str_contains($sqlType, 'json') || (string) $field['type'] === 'json')
                    && json_decode($value) === null && strtolower($value) !== 'null') {
                    $problems[] = $field['label'] . ' holds structured data (JSON), and this is not '
                        . 'valid JSON — so the database would refuse the whole row and name a '
                        . 'constraint rather than this field. An empty box is fine; a list looks '
                        . 'like ["one","two"] and a setting like {"key":"value"}.';

                    continue;
                }

                if ((str_contains($sqlType, 'datetime') || str_contains($sqlType, 'timestamp')
                        || $sqlType === 'date' || $sqlType === 'time')
                    && strtotime($value) === false) {
                    $problems[] = $field['label'] . ' is a date and time, and "' . $value . '" is '
                        . 'not one this build can read. Refused here rather than sent to the '
                        . 'database, which would store 0000-00-00 or reject the row depending on '
                        . 'how it happens to be configured — and those look identical from here.';

                    continue;
                }
            }

            if ($value !== '' && ($field['pattern'] ?? '') !== '' && preg_match('~' . $field['pattern'] . '~', $value) !== 1) {
                $problems[] = $field['label'] . ' must be ' . ($field['pattern_hint'] ?: 'in the expected format') . ' — got "' . $value . '".';

                continue;
            }

            $bounds = (array) ($field['lookup_bounds'] ?? []);

            if ($value !== '' && ($bounds['table'] ?? '') !== ''
                && $value !== trim((string) ($onFile[$field['name']] ?? ''))
                && !in_array($value, array_column($field['lookup'], 'id'), true)) {
                // NOT ON THE LIST IS NOT THE SAME AS NOT REAL. Where the list is
                // the whole table those two coincide and the check is exact.
                // Where it was truncated, rejecting everything off-list would
                // make the 741st employee unselectable — the truncation turning
                // into data loss, which is the reason a silent cap is worse than
                // the slow read it replaced. So the row is looked up instead.
                $exists = false;

                if (!($bounds['complete'] ?? true) && ($bounds['value'] ?? 'id') === 'id') {
                    try {
                        $exists = $this->store->find((string) $bounds['table'], $value) !== null;
                    } catch (\Throwable) {
                        $exists = false;
                    }
                }

                if (!$exists) {
                    $problems[] = !($bounds['complete'] ?? true) && ($bounds['value'] ?? 'id') !== 'id'
                        ? sprintf('%s: the list of %s here is showing %d of %d and is keyed by `%s` '
                            . 'rather than by id, so a value off the list cannot be checked. Refused '
                            . 'rather than accepted unchecked.', $field['label'],
                            (string) $bounds['table'], (int) $bounds['shown'], (int) $bounds['total'],
                            (string) $bounds['value'])
                        : $field['label'] . ': there is no record with id ' . $value . ' to point at.';

                    continue;
                }
            }

            // A DECLARED RANGE ("min": 0 on grace minutes): a figure outside it
            // is refused by name. Compared as decimal strings, never floats.
            if ($value !== '' && (($field['min'] ?? null) !== null || ($field['max'] ?? null) !== null)) {
                if (!is_numeric($value)) {
                    $problems[] = $field['label'] . ' must be a number — got "' . $value . '".';

                    continue;
                }

                if (($field['min'] ?? null) !== null && self::decimalCompare($value, (string) $field['min']) < 0) {
                    $problems[] = $field['label'] . ' cannot be below ' . $field['min'] . ' — got ' . $value . '.';

                    continue;
                }

                if (($field['max'] ?? null) !== null && self::decimalCompare($value, (string) $field['max']) > 0) {
                    $problems[] = $field['label'] . ' cannot be above ' . $field['max'] . ' — got ' . $value . '.';

                    continue;
                }
            }

            // OPTIONS ARE EITHER SELF-LABELED ("1", "0" shown as themselves) OR A
            // VALUE => LABEL MAP (stored "1", shown "Active") — the same idea the
            // lookup options already use. Checked against the stored value either
            // way, never the label, so a saved "1" is never rejected because the
            // label on screen reads "Active".
            //
            // array_keys() on a value=>label map whose keys are numeric strings
            // ("1", "0") returns PHP ints (json_decode casts numeric-string object
            // keys to int, and there is no way to stop it) — every submitted value
            // is a string, so a strict in_array() of '1' against [1, 0] always
            // failed and refused a perfectly valid save. strval() every key back
            // before comparing.
            $validOptions = array_is_list($field['options']) ? $field['options']
                : array_map('strval', array_keys($field['options']));

            if ($value !== '' && $validOptions !== [] && !in_array($value, $validOptions, true)) {
                $problems[] = sprintf('%s must be one of: %s — got "%s".',
                    $field['label'], implode(', ', array_is_list($field['options'])
                        ? $field['options'] : array_values($field['options'])), $value);
            }
        }

        // ONE CODE PER TENANT. Two departments may share a name (one per
        // company) but never a code — "RO" twice is the confusion the owner
        // named. `unique` on the module declaration says which columns; a
        // module that says nothing gets `code` when its table has one. Retired
        // rows do not hold a code hostage; case does not make two codes.
        $unique = (array) ($module['unique'] ?? (in_array('code', $this->columnsOf($module), true) ? ['code'] : []));

        foreach ($unique as $column) {
            $value = trim((string) ($input[$column] ?? ''));

            if ($value === '') {
                continue;
            }

            foreach ($this->store->all((string) $module['table']) as $other) {
                if ((string) ($other['id'] ?? '') !== (string) ($input['id'] ?? '') && !self::isRetired($other)
                    && strcasecmp(trim((string) ($other[$column] ?? '')), $value) === 0) {
                    $problems[] = sprintf('%s "%s" is already used by %s. Codes are unique across your organisation; pick another.',
                        $this->fields($module)['fields'][array_search($column, array_column($this->fields($module)['fields'], 'name'), true)]['label'] ?? ucfirst($column),
                        $value, (string) ($other['name'] ?? $other['label'] ?? ('record #' . $other['id'])));

                    break;
                }
            }
        }

        // COMPANY FOLLOWS THE EMPLOYEE (owner, 22 Oct): a row is never booked to a company the employee is not in.
        if (!empty($module['company_follows_employee']) && trim((string) ($input['employee_id'] ?? '')) !== ''
            && trim((string) ($input['employer_company_id'] ?? '')) !== '') {
            $co = (string) ($this->store->find('employees', (string) $input['employee_id'])['employer_company_id'] ?? '');
            $co !== '' && $co !== trim((string) $input['employer_company_id'])
                && $problems[] = 'Company must be the selected employee\'s own company.';
        }

        // RESULT = OF − LESS (module `difference_check`, e.g. Net = Gross − TDS): payroll pays Gross and deducts TDS,
        // the ledger shows Net — they must agree to the paisa. Checked only when all three are filled.
        $d = (array) ($module['difference_check'] ?? []);
        if ($d !== []) {
            $vals = array_map(static fn (string $k): string => trim((string) ($input[(string) ($d[$k] ?? '')] ?? '')), ['result' => 'result', 'of' => 'of', 'less' => 'less']);
            $paise = static function (string $v): int { $neg = str_starts_with($v, '-'); [$w, $f] = array_pad(explode('.', ltrim($v, '-'), 2), 2, '0'); $p = (int) $w * 100 + (int) substr(str_pad($f, 2, '0'), 0, 2); return $neg ? -$p : $p; };
            if (!in_array('', $vals, true) && is_numeric($vals['result']) && is_numeric($vals['of']) && is_numeric($vals['less'])
                && $paise($vals['result']) !== $paise($vals['of']) - $paise($vals['less'])) {
                $label = array_column($this->fields($module)['fields'], 'label', 'name');
                $problems[] = sprintf('%s must equal %s − %s (%s − %s = %s) — got %s.', $label[$d['result']] ?? $d['result'], $label[$d['of']] ?? $d['of'],
                    $label[$d['less']] ?? $d['less'], $vals['of'], $vals['less'], number_format(($paise($vals['of']) - $paise($vals['less'])) / 100, 2, '.', ''), $vals['result']);
            }
        }

        return $problems;
    }

    /**
     * Save a row into a module's table.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    /**
     * LEGACY CASE IS NOT A WRONG VALUE (13 Oct). Rows imported before the
     * dropdowns-only change hold "active" where the option is now "Active"; an
     * Edit re-posts it and the whole row became unsaveable. A value that matches
     * an option ignoring case is stored as that option — anything else is still
     * refused by problems().
     */
    public const ADDITIONS_KEY = 'dropdown-additions';

    /** A plain option list plus anything added on screen to its `addable` list. @param array<string, mixed> $spec */
    private function withAdditions(array $spec): array
    {
        $options = array_map('strval', (array) ($spec['options'] ?? []));
        $list = (string) ($spec['addable'] ?? '');

        if ($list === '' || !array_is_list($options)) {
            return $options;
        }

        foreach ((array) (($this->customisation?->live()[self::ADDITIONS_KEY]['lists'] ?? [])[$list] ?? []) as $added) {
            $added = (string) $added;
            array_filter($options, static fn (string $o): bool => strcasecmp($o, $added) === 0) === [] && $options[] = $added;
        }

        return $options;
    }

    /**
     * ADD A VALUE TO AN `addable` DROPDOWN (owner, 2 Nov 2026) — versioned like every other customisation.
     * A value already offered (any case) is returned as it is, not added twice.
     *
     * @return array{ok: bool, why: string, value: string}
     */
    public function addOption(array $module, string $field, string $value, string $byId, string $byLabel): array
    {
        $spec = (array) ($module['fields'][$field] ?? []);
        $list = (string) ($spec['addable'] ?? '');
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $label = (string) ($spec['label'] ?? $field);

        if ($list === '' || $this->customisation === null) {
            return ['ok' => false, 'why' => "New values cannot be added to {$label}.", 'value' => ''];
        }
        if ($value === '' || mb_strlen($value) > 60 || preg_match('/[\x00-\x1F<>]/u', $value) === 1) {
            return ['ok' => false, 'why' => "Type the new {$label} (up to 60 characters) in the box under the dropdown.", 'value' => ''];
        }
        foreach ($this->withAdditions($spec) as $o) {
            if (strcasecmp($o, $value) === 0) {
                return ['ok' => true, 'why' => "{$label} \"{$o}\" is already in the list.", 'value' => $o];
            }
        }

        $lists = (array) ($this->customisation->live()[self::ADDITIONS_KEY]['lists'] ?? []);
        $lists[$list][] = $value;
        $r = $this->customisation->saveRaw(self::ADDITIONS_KEY, ['lists' => $lists], $byId, $byLabel, "Added \"{$value}\" to {$label}");

        return ['ok' => $r['ok'], 'why' => $r['ok'] ? "\"{$value}\" added to {$label}." : $r['why'], 'value' => $r['ok'] ? $value : ''];
    }

    private function canonicalOptions(array $module, array $input): array
    {
        foreach ($this->fields($module)['fields'] as $field) {
            $value = trim((string) ($input[$field['name']] ?? ''));
            $options = (array) ($field['options'] ?? []);

            if ($value === '' || $options === []) {
                continue;
            }

            $valid = array_is_list($options) ? array_map('strval', $options) : array_map('strval', array_keys($options));

            if (in_array($value, $valid, true)) {
                continue;
            }

            foreach ($valid as $option) {
                if (strcasecmp($option, $value) === 0) {
                    $input[$field['name']] = $option;

                    break;
                }
            }
        }

        return $input;
    }

    /** (new − old) / old × 100 to 2 places, half away from zero, in whole paise — never a float. Null when old is blank or 0. */
    public static function percentChange(string $old, string $new): ?string
    {
        // DECIMAL(18,4) reads back as "350000.0000": the trailing zeros are dropped, not rounded away.
        $tidy = static fn (string $v): string => ($v = str_replace(',', '', trim($v))) === '' ? '0'
            : (str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v);
        try {
            $o = \SmartPRS\Platform\Support\Money::of($tidy($old))->minor;
            $n = \SmartPRS\Platform\Support\Money::of($tidy($new))->minor;
        } catch (\Throwable) { // gate:allow-swallow not a number: the % stays blank and the amount fields' own checks report it
            return null;
        }
        if ($o <= 0 || trim($new) === '') {
            return null;
        }
        $x = ($n - $o) * 10000;                                   // hundredths of a percent, times old
        $h = intdiv(abs($x) * 2 + $o, 2 * $o);                    // rounded half up
        return ($x < 0 && $h > 0 ? '-' : '') . intdiv($h, 100) . '.' . str_pad((string) ($h % 100), 2, '0', STR_PAD_LEFT);
    }

    public function save(array $module, array $input): array
    {
        $input = $this->canonicalOptions($module, $input);
        $input = array_map('strval', (array) ($module['fixed'] ?? [])) + $input;   // `fixed` — see rows()

        // COMPANY FOLLOWS THE EMPLOYEE (owner, 22 Oct; module flag `company_follows_employee`): blank = theirs.
        if (!empty($module['company_follows_employee']) && trim((string) ($input['employee_id'] ?? '')) !== ''
            && trim((string) ($input['employer_company_id'] ?? '')) === '') {
            $input['employer_company_id'] = (string) ($this->store->find('employees', (string) $input['employee_id'])['employer_company_id'] ?? '');
        }

        // COMMISSION MONEY IS WORKED OUT, NEVER TYPED (owner, 29 Oct 2026; module flag `commission_figures`): gross from
        // the scheme, TDS by the employee's Salary Type, net = gross − TDS — the same on the Add form and a bulk upload.
        if (!empty($module['commission_figures'])) {
            $empId = trim((string) ($input['employee_id'] ?? ''));

            if ($empId === '') {
                return ['ok' => false, 'why' => 'Pick the employee this entry is for.'];
            }

            $rules = (array) (((array) json_decode((string) @file_get_contents(__DIR__ . '/../config/compensation.json'), true))['commission'] ?? []);
            $schemeId = trim((string) ($input['scheme_id'] ?? ''));
            $schemeRow = $schemeId === '' ? null : $this->store->find('incentive_schemes', $schemeId);
            $empRow = $this->store->find('employees', $empId);
            $roles = [];
            foreach ($this->store->all('designations') as $d) { $roles[(string) ($d['id'] ?? '')] = trim((string) ($d['role'] ?? '')); }
            $selfId0 = trim((string) ($input['id'] ?? ''));
            $beforeRow = $selfId0 === '' ? null : $this->store->find('commissions', $selfId0);
            $isOwnFixed = $beforeRow !== null && (string) ($beforeRow['decided_by'] ?? '') === Compensation::FIXED_BY;
            if (!$isOwnFixed && Compensation::isFixedCommission($schemeRow)) {
                return ['ok' => false, 'why' => 'Scheme "' . (string) ($schemeRow['name'] ?? '') . '" is a Fixed Commission — it is paid automatically every month, not entered here. Pick a target-based scheme.'];
            }
            if ($schemeRow !== null && $empRow !== null && !Compensation::eligible($schemeRow, $empRow, $roles)) {
                return ['ok' => false, 'why' => 'This employee is not covered by the scheme "' . (string) ($schemeRow['name'] ?? '') . '" — check its Who it is for fields (Eligible employees: '
                    . (string) $schemeRow['eligible_salary_types'] . '; their Salary Type: ' . (trim((string) ($empRow['salary_type'] ?? '')) ?: 'not set') . ').'];
            }
            // FIXED COMMISSION PAYS BY ITSELF (owner, 3 Nov 2026): an employee covered by an Active Fixed Commission scheme
            // takes no custom entry, and a Fixed scheme is never picked by hand. Its own monthly entries
            // (Compensation::ensureFixed) can still be opened to change their status.
            if (!$isOwnFixed && $empRow !== null
                && ($fx = Compensation::fixedSchemeFor($this->store->all('incentive_schemes'), $empRow, $roles, (string) ($input['month'] ?? ''))) !== null) {
                return ['ok' => false, 'why' => Employment::label($empRow) . ' is covered as part of Fixed Commission (scheme "' . (string) ($fx['name'] ?? '')
                    . '"). No custom commission entry allowed.'];
            }
            // EVERYTHING THE SCHEME ALREADY SAYS COMES FROM THE SCHEME (owner, 3 Nov 2026: "fields redundant which were already
            // set in Commission Setup"): a new entry names its scheme; purpose, portfolio and payout (with salary / separate,
            // and its date) are the scheme's; a % or slab scheme needs the Target Achieved, and the commission is never typed.
            if (!$isOwnFixed && $beforeRow === null && $schemeRow === null) {
                return ['ok' => false, 'why' => 'Pick the scheme — the commission, TDS and net payable are worked out by it.'];
            }
            $rtNow = strtolower(trim((string) ($schemeRow['rate_type'] ?? '')));
            if (!$isOwnFixed && $schemeRow !== null && in_array($rtNow, ['percent', 'slab'], true) && trim((string) ($input['base_amount'] ?? '')) === '') {
                return ['ok' => false, 'why' => 'Enter the Target Achieved (₹) — scheme "' . (string) ($schemeRow['name'] ?? '') . '" works the commission out from it.'];
            }
            $gatedNow = trim((string) ($schemeRow['threshold'] ?? '')) !== '' && trim((string) ($schemeRow['target_amount'] ?? '')) === '';
            if (!$isOwnFixed && $schemeRow !== null && in_array($rtNow, ['fixed', 'percent', 'slab'], true) && !$gatedNow) {
                unset($input['gross_amount'], $input['tds_rate']);   // worked out below, never typed
            }
            if (!$isOwnFixed && $schemeRow !== null) {
                $input['purpose'] = (string) ($schemeRow['purpose'] ?? '');
                $input['portfolio'] = (string) ($schemeRow['portfolio'] ?? '');
                $input['payout_method'] = trim((string) ($schemeRow['payout_method'] ?? '')) ?: 'With salary (in the payslip)';
                $input['payout_date'] = (string) (Compensation::payoutDate($schemeRow, substr(trim((string) ($input['month'] ?? '')), 0, 7)) ?? '');
            }
            if ($isOwnFixed) {
                // the money of a Fixed entry is what the scheme paid that month — kept, never retyped
                $input['scheme_id'] = (string) ($beforeRow['scheme_id'] ?? '');
                $input['employee_id'] = (string) ($beforeRow['employee_id'] ?? '');
                $input['month'] = (string) ($beforeRow['month'] ?? '');
                $worked = ['gross_amount' => (string) ($beforeRow['gross_amount'] ?? '0'), 'tds_rate' => (string) ($beforeRow['tds_rate'] ?? '0'),
                    'tds_amount' => (string) ($beforeRow['tds_amount'] ?? '0'), 'amount' => (string) ($beforeRow['amount'] ?? '0'), 'working' => (string) ($beforeRow['remarks'] ?? '')];
            } else {
                $worked = Compensation::entry($input, $empRow, $schemeRow, $rules);
            }
            // SEPARATE PAYOUT: a blank Payout Date takes the scheme's payout day in this entry's month (29 Oct).
            if ($schemeRow !== null && trim((string) ($input['payout_date'] ?? '')) === '' && str_starts_with(strtolower(trim((string) ($input['payout_method'] ?? ''))), 'separate')) {
                $input['payout_date'] = (string) (Compensation::payoutDate($schemeRow, substr(trim((string) ($input['month'] ?? '')), 0, 7)) ?? '');
            }
            trim((string) ($input['remarks'] ?? '')) === '' && $input['remarks'] = mb_substr($worked['working'], 0, 250);
            unset($worked['working']);
            $input = $worked + $input;

            $selfId = trim((string) ($input['id'] ?? ''));
            $before = $selfId === '' ? null : $this->store->find('commissions', $selfId);
            $newStatus = strtolower(trim((string) ($input['status'] ?? '')));
            $wasStatus = strtolower(trim((string) ($before['status'] ?? '')));

            // THE SCHEME'S OWN LIMITS (owner, 2 Nov 2026): validity window, max claims and max ₹ per person.
            if ($schemeRow !== null && !in_array($newStatus, ['rejected', 'clawed back'], true)) {
                try {
                    $all = $this->store->all('commissions');
                } catch (\Throwable) { // gate:allow-swallow no commissions table on a file store: nothing to count against
                    $all = [];
                }
                $why = Compensation::limitsProblem($schemeRow, $empId, (string) ($input['month'] ?? ''), Amount::of($worked['gross_amount']), $all, $selfId);
                if ($why !== null) {
                    return ['ok' => false, 'why' => $why];
                }
            }

            // CLAWBACK ON BOUNCE (owner, 2 Nov 2026): "Clawed back" only where the scheme allows it; an entry already paid
            // (Approved / Confirmed / Paid) then raises a linked Clawbacks / Reversals row that payroll recovers.
            if ($newStatus === 'clawed back' && $wasStatus !== 'clawed back') {
                if ($schemeRow !== null && trim((string) ($schemeRow['scheme_kind'] ?? '')) !== '' && !Compensation::clawbackAllowed($schemeRow)) {
                    return ['ok' => false, 'why' => 'Scheme "' . (string) ($schemeRow['name'] ?? '') . '" does not allow clawback — tick "Clawback on bounce?" on the scheme first.'];
                }
                if ($before === null) {
                    return ['ok' => false, 'why' => 'Only a saved entry can be clawed back.'];
                }
                in_array($wasStatus, ['approved', 'confirmed', 'paid'], true) && $clawback = $before;
            }
        }

        // SET BY ANOTHER FIELD, then WHAT IS HIDDEN IS EMPTY (module flag `clear_hidden`, 29 Oct): a field shown only
        // for some choices (Target gate, Rate, Slab bands, Payout Date) is saved blank when that choice is not made, so
        // a value typed before switching cannot quietly keep working (e.g. a target gate on an "Amount collected" scheme).
        $specs = $this->fields($module)['fields'];
        $setBy = [];
        foreach ($specs as $f) {
            foreach ((array) $f['set_when'] as $other => $map) {
                $v = trim((string) ($input[$other] ?? ''));
                foreach ((array) $map as $when => $to) {
                    if (strcasecmp($v, (string) $when) === 0) {
                        $input[$f['name']] = (string) $to;
                        $setBy[$f['name']] = true;
                    }
                }
            }
        }
        if (!empty($module['clear_hidden'])) {
            for ($pass = 0; $pass < 2; $pass++) {   // twice: Slab bands hide when How it pays is itself hidden
                foreach ($specs as $f) {
                    if (isset($setBy[$f['name']])) {
                        continue;   // set by another field: kept even though it is not shown
                    }
                    foreach ((array) $f['show_when'] as $other => $vals) {
                        $v = trim((string) ($input[$other] ?? ''));
                        $shown = array_filter(array_map('strval', (array) $vals), static fn (string $o): bool => strcasecmp($o, $v) === 0) !== [];
                        $shown || !array_key_exists($f['name'], $input) || $input[$f['name']] = '';
                    }
                }
            }
        }
        $problems = $this->problems($module, $input);

        if ($problems !== []) {
            // Checked here, before storage, so the message names the FIELD in the
            // customer's own words rather than the column in MySQL's.
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        $fieldSpecs = $this->fields($module)['fields'];
        $readonly = array_map(
            static fn (array $f): string => $f['name'],
            array_filter($fieldSpecs, static fn (array $f): bool => $f['readonly']),
        );
        $allowed = array_diff($this->editable($module), $readonly, $this->fields($module)['locked']);
        $nullableOf = array_column($fieldSpecs, 'nullable', 'name');
        $row = [];

        foreach ($input as $column => $value) {
            if ($column === 'id' && (string) $value !== '') {
                $row['id'] = $value;

                continue;
            }

            if (!in_array($column, $allowed, true)) {
                continue;
            }

            if ($value !== '') {
                $row[$column] = $value;

                continue;
            }

            // An empty box means "no value", not the empty string: a NOT NULL
            // date column given '' is a refusal from MySQL that reads like a
            // bug, and a nullable one given '' stores a value that is not
            // missing and not set. But a NOT NULL column the form left blank
            // (a status select nobody touched while adding a row) is a THIRD
            // case: it is not nullable, so it is left OUT of the row entirely,
            // the same way `store->put()` already treats a column the caller
            // never mentions — letting the database's own default apply
            // instead of sending an explicit NULL a NOT NULL column refuses.
            if ($nullableOf[$column] ?? true) {
                $row[$column] = null;
            }
        }

        // % INCREASE FROM OLD AND NEW (owner, 30 Oct 2026; field option `percent_change`): worked out here, whatever was posted.
        foreach ($fieldSpecs as $f) {
            [$from, $to] = $f['percent_change'] + [null, null];
            if ($from !== null && $to !== null && array_key_exists($from, $input) && array_key_exists($to, $input)) {
                $row[$f['name']] = self::percentChange((string) $input[$from], (string) $input[$to]);
            }
        }

        if ($row === [] || array_keys($row) === ['id']) {
            return ['ok' => false, 'why' => 'Nothing was filled in, so nothing was saved.'];
        }

        try {
            $saved = $this->store->put((string) $module['table'], $row);
            $why = 'Saved to `' . $module['table'] . '`.';

            // THE LINKED RECOVERY for a clawed-back commission / incentive (see above): what reached the employee
            // (net), recovered in the current payroll month once approved — each row names the other.
            if (isset($clawback)) {
                $cb = $this->store->put('clawbacks', [
                    'employee_id' => (string) $clawback['employee_id'],
                    'portfolio' => (string) ($clawback['portfolio'] ?? ''),
                    'month' => date('Y-m'),
                    'amount' => (string) ((trim((string) ($clawback['amount'] ?? '')) ?: (string) ($clawback['gross_amount'] ?? '0'))),
                    'reason' => 'Clawback on bounce — commission / incentive entry #' . (string) $clawback['id'] . ' (' . (string) ($clawback['month'] ?? '') . ')',
                    'status' => 'Pending',
                ]);
                $note = 'Clawback #' . (string) ($cb['id'] ?? '') . ' raised (Clawbacks / Reversals) — approve it to recover in payroll';
                $this->store->put((string) $module['table'], ['id' => (string) $saved['id'], 'remarks' => mb_substr(trim((string) ($saved['remarks'] ?? '') . ' | ' . $note, ' |'), 0, 250)]);
                $why .= ' ' . $note . '.';
            }

            return ['ok' => true, 'row' => $saved, 'why' => $why];
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        }
    }
}
