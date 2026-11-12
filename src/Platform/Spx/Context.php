<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Support\Rational;

/**
 * THE PREPARED CONTEXT — Part VII §10.1c.
 *
 * "The engine has no database access. The evaluator is constructed with a
 * PreparedContext — a finite, typed, immutable value object. It holds no
 * connection, no repository, no container and no query builder. There is no
 * symbol that can reach a row the context does not contain."
 *
 * That is this class, and it is why the safety argument is structural rather
 * than procedural. Everything an expression can read — symbols, calendars, slab
 * tables, day-length policies, aggregation results, the answers to the
 * data-backed functions — is a value put here by the domain service AFTER
 * authorisation, under tenant scope, for ONE subject. The evaluator performs no
 * I/O of any kind; `Compiled::preparedKeys()` tells the caller exactly what to
 * assemble, so a missing input is a named ERR_MISSING_INPUT and never a query.
 *
 * `evaluationDate` is the only clock (R-VII-019). There is no NOW() in SPX,
 * which is what makes a recalculation of March, run in November, produce
 * March's answer. The business timezone is deliberately NOT a property here: it
 * is `context.tenant_timezone`, a declared symbol the caller supplies like any
 * other, so no default for it can hide in this code (Law 1).
 */
final readonly class Context
{
    /** @var array<string, Value> canonical key => value */
    private array $symbols;

    /** @var array<string, Value> canonical call key => value */
    private array $prepared;

    /**
     * @param array<string, mixed> $symbols  canonical key => value; declared wide because
     *        this constructor is the boundary between the calling domain service and the
     *        engine (see class docblock), and the loop below is what actually proves each
     *        entry is an Spx\Value before it is stored
     * @param array<string, array{holidays?: list<string>, weekly_offs?: list<string>, holiday_types?: array<string, string>}> $calendars
     * @param array<string, array{bands: list<array{from: int, rate: string}>, flat?: bool, marginal_relief?: array{threshold: int}}> $slabs
     * @param array<string, string> $dayLengths  policy code => minutes in one day, as a decimal string
     * @param array<string, mixed> $prepared  canonical call key => value; declared wide for the
     *        same reason as $symbols above
     */
    public function __construct(
        public \DateTimeImmutable $evaluationDate,
        array $symbols = [],
        private array $calendars = [],
        private array $slabs = [],
        private array $dayLengths = [],
        array $prepared = [],
        private array $bands = [],
    ) {
        // The context is the boundary between the calling domain service and the
        // engine, so it is checked here rather than discovered halfway through an
        // evaluation. A raw PHP scalar has no SPX type, and guessing one is the
        // coercion this whole engine exists to prevent.
        foreach ([$symbols, $prepared] as $bag) {
            foreach ($bag as $key => $value) {
                if (!$value instanceof Value) {
                    throw new \InvalidArgumentException(
                        "Context entry '{$key}' must be an Spx\\Value carrying its type, not a bare "
                        . get_debug_type($value) . '.'
                    );
                }
            }
        }

        /**
         * @var array<string, Value> $symbols
         * @var array<string, Value> $prepared
         */
        $this->symbols = $symbols;
        $this->prepared = $prepared;
    }

    /** @param array<string, Value> $symbols */
    public function withSymbols(array $symbols): self
    {
        return new self(
            $this->evaluationDate,
            $symbols + $this->symbols,
            $this->calendars,
            $this->slabs,
            $this->dayLengths,
            $this->prepared,
            $this->bands,
        );
    }

    /**
     * @param list<string> $holidays  Y-m-d
     * @param list<string> $weeklyOffs  Y-m-d
     * @param array<string, string> $holidayTypes  Y-m-d => national|festival|optional|restricted
     */
    public function withCalendar(string $scope, array $holidays = [], array $weeklyOffs = [], array $holidayTypes = []): self
    {
        return new self(
            $this->evaluationDate,
            $this->symbols,
            [$scope => [
                'holidays' => $holidays,
                'weekly_offs' => $weeklyOffs,
                'holiday_types' => $holidayTypes,
            ]] + $this->calendars,
            $this->slabs,
            $this->dayLengths,
            $this->prepared,
            $this->bands,
        );
    }

    /**
     * A statutory slab table from the resolved, signed rule pack. `from` is the
     * inclusive lower bound in minor units; `rate` is a percentage as a decimal
     * string. No threshold is ever a literal in a rule (§5.7).
     *
     * @param list<array{from: int, rate: string}> $bands
     * @param array{threshold: int}|null $marginalRelief
     */
    public function withSlabTable(string $code, array $bands, bool $flat = false, ?array $marginalRelief = null): self
    {
        $table = ['bands' => $bands, 'flat' => $flat];

        if ($marginalRelief !== null) {
            $table['marginal_relief'] = $marginalRelief;
        }

        return new self(
            $this->evaluationDate,
            $this->symbols,
            $this->calendars,
            [$code => $table] + $this->slabs,
            $this->dayLengths,
            $this->prepared,
            $this->bands,
        );
    }

    /**
     * A configured band table: parallel bounds and results, resolved by the
     * caller from the band-table artefact and put in front of the engine, which
     * fetches nothing itself.
     *
     * @param list<mixed> $bounds  ascending lower bounds
     * @param list<mixed> $results one result per bound
     */
    public function withBandTable(string $code, array $bounds, array $results): self
    {
        if (count($bounds) !== count($results) || $bounds === []) {
            throw new \InvalidArgumentException(
                "Band table '{$code}': bounds and results must be non-empty and the same length."
            );
        }

        return new self(
            $this->evaluationDate,
            $this->symbols,
            $this->calendars,
            $this->slabs,
            $this->dayLengths,
            $this->prepared,
            [$code => ['bounds' => $bounds, 'results' => $results]] + $this->bands,
        );
    }

    /** @return array{bounds: list<mixed>, results: list<mixed>}|null */
    public function bandTable(string $code): ?array
    {
        return $this->bands[$code] ?? null;
    }

    /** R-VIII-016: a Days value means nothing without the policy that defines a day. */
    public function withDayLength(string $code, string $minutesPerDay): self
    {
        return new self(
            $this->evaluationDate,
            $this->symbols,
            $this->calendars,
            $this->slabs,
            [$code => $minutesPerDay] + $this->dayLengths,
            $this->prepared,
            $this->bands,
        );
    }

    /**
     * Answers the caller resolved before evaluation began: aggregation results,
     * and the data-backed functions (PAYABLE_DAYS, ATTENDANCE_HOURS, …). Key
     * them with Context::key() / Compiled::preparedKeys().
     *
     * @param array<string, Value> $values
     */
    public function withPrepared(array $values): self
    {
        return new self(
            $this->evaluationDate,
            $this->symbols,
            $this->calendars,
            $this->slabs,
            $this->dayLengths,
            $values + $this->prepared,
            $this->bands,
        );
    }

    public function symbol(string $key): ?Value
    {
        return $this->symbols[$key] ?? null;
    }

    public function hasSymbol(string $key): bool
    {
        return array_key_exists($key, $this->symbols);
    }

    public function prepared(string $key): ?Value
    {
        return $this->prepared[$key] ?? null;
    }

    /** @return array{holidays: list<string>, weekly_offs: list<string>, holiday_types: array<string, string>}|null */
    public function calendar(string $scope): ?array
    {
        if (!isset($this->calendars[$scope])) {
            return null;
        }

        return [
            'holidays' => $this->calendars[$scope]['holidays'] ?? [],
            'weekly_offs' => $this->calendars[$scope]['weekly_offs'] ?? [],
            'holiday_types' => $this->calendars[$scope]['holiday_types'] ?? [],
        ];
    }

    /** @return array{bands: list<array{from: int, rate: string}>, flat: bool, marginal_relief?: array{threshold: int}}|null */
    public function slabTable(string $code): ?array
    {
        return $this->slabs[$code] ?? null;
    }

    public function dayLength(string $code): ?Rational
    {
        return isset($this->dayLengths[$code])
            ? Rational::fromDecimalString($this->dayLengths[$code])
            : null;
    }

    /**
     * The canonical key for a keyed symbol or a data-backed call. Stable across
     * processes and versions: it is what the caller prepares against and what
     * the aggregation counter is looked up by.
     *
     * @param list<Value> $arguments
     */
    public static function key(string $name, array $arguments = []): string
    {
        if ($arguments === []) {
            return $name;
        }

        return $name . '(' . implode(',', array_map(self::render(...), $arguments)) . ')';
    }

    private static function render(Value $value): string
    {
        return match (true) {
            $value->isNull() => 'NULL',
            is_string($value->payload) => "'" . $value->payload . "'",
            $value->payload instanceof \DateTimeImmutable => '#' . $value->payload->format('Y-m-d') . '#',
            $value->payload instanceof Rational => (string) $value->payload,
            is_bool($value->payload) => $value->payload ? 'TRUE' : 'FALSE',
            default => $value->describe(),
        };
    }
}
