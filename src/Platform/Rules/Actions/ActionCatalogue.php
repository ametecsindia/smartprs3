<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules\Actions;

/**
 * The closed, typed action catalogue of Part VII §6.2.
 *
 * The engine never executes behaviour. It constructs AppliedAction values and
 * the calling domain service interprets them inside its own transaction
 * (R-VII-028). That is what makes the simulator trivially safe: the same code
 * path, the same inputs, and no side effects to suppress.
 *
 * WHAT IS NOT HERE, AND WHY
 * -------------------------
 * There is no DELETE, PURGE or TRUNCATE verb, at any severity, for any module
 * (R-VII-030). There is no verb that writes the employee master, changes a role,
 * a scope, a permission or a configuration artefact (R-VII-031) — a rule that
 * could grant a permission would make rule authoring a privilege-escalation
 * primitive. There is no `register()`, no plugin path, no script hook and no
 * "custom action" record, in any deployment mode including on-premise
 * (R-VII-034). The absence of a mutator on this class is the enforcement.
 *
 * HOW AMETECS ADDS AN ACTION (§6.5) — the only path that exists
 * ------------------------------------------------------------
 *  1. Migration inserting the `action_definitions` row: action_code, module,
 *     param_schema, effect_class, idempotency_rule, reversal_rule,
 *     min_catalogue_version, is_money_affecting.
 *  2. Add the entry to self::CATALOGUE below, in the owning module's group.
 *  3. Implement ActionHandler::apply(AppliedAction, DomainContext): EffectResult
 *     in the owning module — idempotent on the fingerprint.
 *  4. Add the action to the allow-list of the decision points that may emit it.
 *  5. Conformance tests: parameter type rejection, double-apply convergence,
 *     reversal behaviour, trace rendering, and an authorisation test proving the
 *     action cannot be emitted from a decision point that does not declare it.
 *  6. Bump self::VERSION. A major bump re-validates every published rule and
 *     reports the ones that no longer type-check, WITHOUT changing them.
 *
 * Steps 1, 4, 5 and 6 are outside this file by design: a code-only addition is
 * exactly the change that must not be possible.
 */
final class ActionCatalogue
{
    /**
     * Published rules pin the major version they compiled against (§6.5 step 6).
     */
    public const VERSION = '1.0.0';

    /** §6.4 reversal classes. */
    public const DERIVED = 'derived';
    public const LEDGER = 'ledger';
    public const TERMINAL = 'terminal';

    /**
     * §7.3 conflict behaviour, a property of the ACTION, not of the rules.
     *   additive   accumulates; never conflicts (subject to declared caps)
     *   singleton  two matched rules with different values is ERR_ACTION_CONFLICT
     *   dominant   a declared dominance order settles it without an error
     */
    public const ADDITIVE = 'additive';
    public const SINGLETON = 'singleton';
    public const DOMINANT = 'dominant';

    /**
     * code => [
     *   module, params (name => type; a trailing '?' marks optional),
     *   effect, conflict, money, idempotency, reversal, dominance?
     * ]
     *
     * A parameter type named here is the SPX type the value must resolve to.
     * `expr` in §6.2 means "an SPX expression of that type, a literal, or a
     * formula reference" — all three arrive resolved, so the catalogue records
     * the type, not the authoring form.
     */
    private const CATALOGUE = [

        // ---------------------------------------------------------------- attendance

        'SET_STATUS' => [
            'module' => 'attendance',
            'params' => ['code' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => true,
            'idempotency' => 'Last writer within one derivation; two different codes from two matched rules is a conflict (§8)',
            'reversal' => 'Superseded by re-derivation',
        ],
        'SET_DAY_TYPE' => [
            'module' => 'attendance',
            'params' => ['type' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => true,
            'idempotency' => 'Idempotent by value',
            'reversal' => 'Superseded',
        ],
        'SET_GRACE' => [
            'module' => 'attendance',
            'params' => ['minutes' => 'Minutes'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => false,
            'idempotency' => 'Idempotent by value',
            'reversal' => 'Superseded',
        ],
        'APPLY_DAY_PENALTY' => [
            'module' => 'attendance',
            'params' => ['fraction' => 'Days', 'reason' => 'Text', 'cap_ref?' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Additive; deduplicated by fingerprint',
            'reversal' => 'Superseded by re-derivation; contributes to the day penalty total',
        ],
        'APPLY_LOP' => [
            'module' => 'attendance',
            'params' => ['amount' => 'Days|Hours', 'reason' => 'Text'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Additive, fingerprinted',
            'reversal' => 'Superseded',
        ],
        'OFFSET_SHORTFALL' => [
            'module' => 'attendance',
            'params' => ['minutes' => 'Minutes', 'source' => 'Code', 'cap?' => 'Minutes'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Additive, capped, fingerprinted',
            'reversal' => 'Superseded',
        ],
        'CREATE_OT' => [
            'module' => 'attendance',
            'params' => ['minutes' => 'Minutes', 'multiplier_ref' => 'Code', 'approval' => 'Code'],
            'effect' => self::LEDGER,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Fingerprinted; re-derivation supersedes the entry only while unpaid',
            'reversal' => 'Once paid: an arrear or recovery action, never an edit',
        ],
        'CREATE_COMP_OFF' => [
            'module' => 'attendance',
            'params' => ['units' => 'Days', 'expiry_ref' => 'Code', 'reason' => 'Text'],
            'effect' => self::LEDGER,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Fingerprinted; the ledger credit happens once per fingerprint',
            'reversal' => 'A compensating debit row, never a delete',
        ],
        'FLAG_FOR_REVIEW' => [
            'module' => 'attendance',
            'params' => ['reason' => 'Text', 'severity' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Idempotent by (subject, reason)',
            'reversal' => 'Cleared by resolution or re-derivation',
        ],

        // ---------------------------------------------------------------- leave

        'DEDUCT' => [
            'module' => 'leave',
            'params' => ['type' => 'Code', 'units' => 'Days'],
            'effect' => self::LEDGER,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Fingerprinted, once per request version',
            'reversal' => 'Compensating credit',
        ],
        'CHARGE_DAY' => [
            'module' => 'leave',
            'params' => ['date' => 'Date', 'type' => 'Code', 'units' => 'Days', 'reason' => 'Text'],
            'effect' => self::LEDGER,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Fingerprinted per (request, date)',
            'reversal' => 'Compensating credit',
        ],
        'EXEMPT_DAY' => [
            'module' => 'leave',
            'params' => ['date' => 'Date', 'reason' => 'Text'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Idempotent',
            'reversal' => 'None required',
        ],
        'CONVERT_TO' => [
            'module' => 'leave',
            'params' => ['type' => 'Code', 'reason' => 'Text'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => true,
            'idempotency' => 'Idempotent by value',
            'reversal' => 'Superseded before approval; after approval a cancellation workflow',
        ],
        'REQUIRE_DOCUMENT' => [
            'module' => 'leave',
            'params' => ['doc_type' => 'Code', 'by' => 'Date'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Idempotent',
            'reversal' => 'Cleared on rule change and re-evaluation',
        ],
        'BLOCK' => [
            'module' => 'leave',
            'params' => ['reason' => 'Text', 'code' => 'Code'],
            'effect' => self::TERMINAL,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Idempotent',
            'reversal' => 'None — a refusal is not reversed, it is re-requested',
        ],
        'ROUTE_TO_WORKFLOW' => [
            'module' => 'leave',
            'params' => ['workflow_code' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => false,
            'idempotency' => 'Idempotent; a second, different route is a conflict',
            'reversal' => 'An in-flight instance completes on its own version (spine S8)',
        ],

        // ---------------------------------------------------------------- payroll

        'ADD_COMPONENT' => [
            'module' => 'payroll',
            'params' => ['code' => 'Code', 'amount' => 'Money', 'basis?' => 'Code'],
            'effect' => self::LEDGER,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Fingerprinted; one line per (component, fingerprint)',
            'reversal' => 'Regeneration creates a new run version, never edits a payslip',
        ],
        'SUPPRESS_COMPONENT' => [
            'module' => 'payroll',
            'params' => ['code' => 'Code', 'reason' => 'Text'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Idempotent',
            'reversal' => 'Superseded',
        ],
        'SET_BASIS' => [
            'module' => 'payroll',
            'params' => ['component' => 'Code', 'basis' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => true,
            'idempotency' => 'Idempotent by value; two different bases is a conflict',
            'reversal' => 'Superseded',
        ],
        'CAP' => [
            'module' => 'payroll',
            'params' => ['component' => 'Code', 'amount' => 'Money', 'mode' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => true,
            'idempotency' => 'Idempotent by value',
            'reversal' => 'Superseded',
        ],
        'CREATE_RECOVERY' => [
            'module' => 'payroll',
            'params' => ['type' => 'Code', 'amount' => 'Money', 'schedule_ref' => 'Code', 'priority' => 'Number'],
            'effect' => self::LEDGER,
            'conflict' => self::ADDITIVE,
            'money' => true,
            'idempotency' => 'Fingerprinted',
            'reversal' => 'Compensating ledger entry',
        ],
        'SET_RECOVERY_ORDER' => [
            'module' => 'payroll',
            'params' => ['order' => 'List<Code>'],
            'effect' => self::DERIVED,
            'conflict' => self::SINGLETON,
            'money' => true,
            'idempotency' => 'Idempotent',
            'reversal' => 'Superseded',
        ],

        // ---------------------------------------------------------------- compliance

        'GATE_PAYMENT' => [
            'module' => 'compliance',
            'params' => ['mode' => 'Code', 'reason' => 'Text', 'code' => 'Code', 'event' => 'Code'],
            'effect' => self::TERMINAL,
            'conflict' => self::DOMINANT,
            'dominance' => ['parameter' => 'mode', 'order' => ['warn', 'block']],
            'money' => true,
            'idempotency' => 'Idempotent by (event, subject, code); block dominates warn',
            'reversal' => 'Cleared when the underlying condition clears and the gate re-evaluates',
        ],
        'RAISE_ITEM' => [
            'module' => 'compliance',
            'params' => ['type' => 'Code', 'due' => 'Date', 'owner_ref' => 'Code', 'severity' => 'Code'],
            'effect' => self::TERMINAL,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Idempotent by (type, subject, due)',
            'reversal' => 'Closed with a reason, never deleted',
        ],
        'SET_RISK' => [
            'module' => 'compliance',
            'params' => ['level' => 'Code'],
            'effect' => self::DERIVED,
            'conflict' => self::DOMINANT,
            'dominance' => ['parameter' => 'level', 'order' => ['low', 'medium', 'high', 'critical']],
            'money' => false,
            'idempotency' => 'Idempotent by value; the highest level wins across matched rules',
            'reversal' => 'Superseded',
        ],

        // ---------------------------------------------------------------- platform

        'REQUIRE_APPROVAL' => [
            'module' => 'platform',
            'params' => ['workflow_code' => 'Code', 'reason' => 'Text'],
            'effect' => self::DERIVED,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Idempotent',
            'reversal' => 'Spine S8',
        ],
        'WRITE_AUDIT' => [
            'module' => 'platform',
            'params' => ['reason' => 'Text', 'detail' => 'Text'],
            'effect' => self::TERMINAL,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Fingerprinted',
            'reversal' => 'Never — the ledger is append-only (spine S14)',
        ],
        // R-VII-029: NOTIFY emits a DOMAIN EVENT, never an email. Channel,
        // audience, template, language and throttling belong to the notification
        // engine. Registered once and emitted by attendance and platform alike.
        'NOTIFY' => [
            'module' => 'platform',
            'params' => ['event' => 'Code', 'audience_ref' => 'Code', 'dedupe_key' => 'Text'],
            'effect' => self::TERMINAL,
            'conflict' => self::ADDITIVE,
            'money' => false,
            'idempotency' => 'Deduped by dedupe_key',
            'reversal' => 'None — a correction sends a correction notice',
        ],
    ];

    public static function knows(string $code): bool
    {
        return isset(self::CATALOGUE[$code]);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** @return array<string, mixed> */
    public static function definition(string $code): array
    {
        return self::CATALOGUE[$code] ?? throw new \InvalidArgumentException(
            "'{$code}' is not in the action catalogue. The catalogue is closed and versioned by "
            . 'Ametecs (R-VII-034); there is no plugin path, no script hook and no custom-action row. '
            . 'See ActionCatalogue for the six-step addition procedure.'
        );
    }

    public static function module(string $code): string
    {
        return (string) self::definition($code)['module'];
    }

    public static function effectClass(string $code): string
    {
        return (string) self::definition($code)['effect'];
    }

    public static function conflictClass(string $code): string
    {
        return (string) self::definition($code)['conflict'];
    }

    public static function isMoneyAffecting(string $code): bool
    {
        return (bool) self::definition($code)['money'];
    }

    public static function idempotencyRule(string $code): string
    {
        return (string) self::definition($code)['idempotency'];
    }

    public static function reversalRule(string $code): string
    {
        return (string) self::definition($code)['reversal'];
    }

    /** @return array{parameter: string, order: list<string>}|null */
    public static function dominance(string $code): ?array
    {
        return self::definition($code)['dominance'] ?? null;
    }

    /**
     * Publish-time parameter check (§8.3 "each parameter type-checks"). Names
     * only: the SPX type check proves the type, and duplicating it here would
     * give two answers to one question.
     *
     * @param array<string, mixed> $parameters
     */
    public static function assertParameters(string $code, array $parameters): void
    {
        $declared = self::definition($code)['params'];
        $required = [];
        $known = [];

        foreach ($declared as $name => $_type) {
            $optional = str_ends_with($name, '?');
            $name = rtrim($name, '?');
            $known[$name] = true;

            if (!$optional) {
                $required[] = $name;
            }
        }

        $missing = array_diff($required, array_keys($parameters));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                "{$code} is missing required parameter(s): " . implode(', ', $missing) . '.'
            );
        }

        $unknown = array_diff(array_keys($parameters), array_keys($known));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "{$code} has no parameter(s): " . implode(', ', $unknown)
                . '. Declared: ' . implode(', ', array_keys($known)) . '.'
            );
        }
    }
}
