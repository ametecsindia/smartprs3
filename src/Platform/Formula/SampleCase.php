<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Support\Money;

/**
 * A NAMED SAMPLE WITH AN EXACT EXPECTED VALUE — Part VIII §9.10, R-VIII-047.
 *
 * The twin of Rules\RuleTestCase, and it exists because G10 could only COUNT
 * sample cases: `sampleTests` was a list of strings, so a formula could be
 * published with three case names and no case had ever been run. The gate's own
 * docblock said so. A gate that counts names is a gate that checks nothing,
 * which is the failure this codebase keeps finding in itself.
 *
 * §9.10: "The expected value is asserted EXACTLY, in minor units", not within a
 * tolerance. A tolerance is how a rounding defect survives a regression suite —
 * the defect is a paisa, and any tolerance that admits it admits every rounding
 * error there is.
 *
 * `formula_sample_cases` has existed as a table since the payload migration and
 * had nothing to store. This is what it stores.
 */
final readonly class SampleCase
{
    /** §9.10's classes. A reachable class with no case is a number nobody has checked. */
    public const CLASSES = ['nominal', 'boundary', 'zero', 'negative', 'proration', 'statutory_boundary'];

    public function __construct(
        public string $name,
        public string $class,
        public string $formulaCode,
        public Context $context,
        public int $expectedMinor,
        public string $sampleEmployeeCode = 'SAMPLE-1',
        public ?string $expectedError = null,
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A sample case is named. An unnamed case cannot be reported as failing.');
        }

        if (!in_array($class, self::CLASSES, true)) {
            throw new \InvalidArgumentException(
                "'{$class}' is not a sample case class (§9.10). One of: " . implode(', ', self::CLASSES) . '.'
            );
        }
    }

    /**
     * Run this case against the formula as it would be published.
     *
     * @param  array<string, Formula>  $graph  the formula and everything it depends on
     * @return array{passed: bool, message: string, trace: string}
     */
    public function run(array $graph): array
    {
        $results = (new Calculator($graph))->evaluateAll($this->context, 'sample:' . $this->name);
        $result = $results[$this->formulaCode] ?? null;

        if ($result === null) {
            return [
                'passed' => false,
                'message' => "{$this->formulaCode} was not evaluated by this graph",
                'trace' => '',
            ];
        }

        if ($this->expectedError !== null) {
            $actual = $result->error ?? '';

            return [
                'passed' => str_contains($actual, $this->expectedError),
                'message' => str_contains($actual, $this->expectedError)
                    ? ''
                    : "expected error {$this->expectedError}, got " . ($actual === '' ? 'no error' : $actual),
                'trace' => $result->trace,
            ];
        }

        if ($result->isError()) {
            return [
                'passed' => false,
                'message' => 'the formula failed: ' . (string) $result->error,
                'trace' => $result->trace,
            ];
        }

        $actual = $result->money?->minor;

        // Exactly, in minor units. Not "close enough" — see the class docblock.
        return [
            'passed' => $actual === $this->expectedMinor,
            'message' => $actual === $this->expectedMinor
                ? ''
                : 'expected ' . Money::ofMinor($this->expectedMinor)->format()
                    . ' and got ' . ($result->money?->format() ?? 'no money')
                    . ' (' . ($actual ?? 'null') . ' paise against ' . $this->expectedMinor . ')',
            'trace' => $result->trace,
        ];
    }
}
