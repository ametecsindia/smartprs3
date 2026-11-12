<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * WHAT CHANGED, AND WHETHER IT BREAKS ANYBODY — Part XXIV R-XXIV-061.
 *
 * The compatibility gate: the generated document is diffed against the last
 * released one, and a BREAKING change proposed inside `v3` fails the build
 * without an explicit, approved, recorded waiver.
 *
 * The classification is the whole value, and it is not symmetric:
 *
 *   ADDING a route, an optional field, an error code, or a response status is
 *   ADDITIVE. Existing clients keep working — they simply do not use it.
 *
 *   REMOVING a route or a response field, adding a REQUIRED request field,
 *   narrowing a type, or changing a status code is BREAKING. Each of them
 *   breaks a client that was written correctly against the previous document,
 *   which is the definition that matters: a change is breaking if it can break
 *   somebody who did nothing wrong.
 *
 * The asymmetry is why this cannot be a byte diff with a threshold. Twenty added
 * fields are harmless and one removed field is an outage, so counting changes
 * tells you nothing; only classifying them does.
 *
 * A waiver is RECORDED, not a flag. `BREAKING:` in a commit message that nobody
 * reads is not an approval — the waiver names the change, so the diff can check
 * that the approval is for the break that actually happened rather than for a
 * break somebody approved last month.
 */
final class ContractDiff
{
    public const ADDITIVE = 'additive';
    public const BREAKING = 'breaking';

    /**
     * @param array<string, mixed> $previous the last released document
     * @param array<string, mixed> $current the generated one
     * @param list<string> $waivers change descriptions explicitly approved
     * @return array{compatible: bool, additive: list<string>, breaking: list<string>, waived: list<string>}
     */
    public static function compare(array $previous, array $current, array $waivers = []): array
    {
        $additive = [];
        $breaking = [];

        $before = self::operations($previous);
        $after = self::operations($current);

        foreach ($before as $key => $operation) {
            if (!isset($after[$key])) {
                $breaking[] = "removed {$key}";

                continue;
            }

            $now = $after[$key];

            // A required request field that did not exist before breaks every
            // client that was sending a valid body yesterday.
            foreach (array_diff($now['required'], $operation['required']) as $field) {
                $breaking[] = "{$key}: '{$field}' is now a required request field";
            }

            // A response field that disappeared breaks every client reading it.
            foreach (array_diff($operation['response_fields'], $now['response_fields']) as $field) {
                $breaking[] = "{$key}: response field '{$field}' was removed";
            }

            foreach (array_diff($now['response_fields'], $operation['response_fields']) as $field) {
                $additive[] = "{$key}: response field '{$field}' was added";
            }

            if ($operation['success_status'] !== $now['success_status']) {
                $breaking[] = "{$key}: success status changed from {$operation['success_status']} to "
                    . $now['success_status'];
            }

            foreach (array_diff($now['error_codes'], $operation['error_codes']) as $code) {
                $additive[] = "{$key}: error code '{$code}' was added";
            }

            // A removed error code is breaking in the direction people forget:
            // a client with a handler for it now receives something else.
            foreach (array_diff($operation['error_codes'], $now['error_codes']) as $code) {
                $breaking[] = "{$key}: error code '{$code}' was removed";
            }
        }

        foreach (array_diff_key($after, $before) as $key => $operation) {
            $additive[] = "added {$key}";
        }

        $waived = [];
        $remaining = [];

        foreach ($breaking as $change) {
            // The waiver must name THIS change. An approval that covers
            // "breaking changes" in general is an approval for whatever happens
            // to break next.
            $matched = false;

            foreach ($waivers as $waiver) {
                if ($waiver === $change) {
                    $matched = true;

                    break;
                }
            }

            if ($matched) {
                $waived[] = $change;
            } else {
                $remaining[] = $change;
            }
        }

        return [
            'compatible' => $remaining === [],
            'additive' => $additive,
            'breaking' => $remaining,
            'waived' => $waived,
        ];
    }

    /**
     * Every field a response can carry, INCLUDING through nested $refs.
     *
     * A collection endpoint returns `{data: [Employee]}`, so its own properties
     * are just `data` — and a naive one-level read would call removing a field
     * from Employee "compatible" for every list endpoint in the API. It is not:
     * a client reading `data[].department` breaks exactly as hard as one reading
     * it from a single-resource response.
     *
     * @param array<string, mixed> $schemas
     * @param list<string> $seen guards a schema that refers to itself
     * @return list<string>
     */
    private static function fieldsOf(string $name, array $schemas, array $seen = []): array
    {
        if (in_array($name, $seen, true) || !isset($schemas[$name])) {
            return [];
        }

        $seen[] = $name;
        $fields = [];

        foreach ((array) ($schemas[$name]['properties'] ?? []) as $field => $definition) {
            $fields[] = $field;

            $nested = $definition['$ref'] ?? $definition['items']['$ref'] ?? null;

            if ($nested !== null) {
                foreach (self::fieldsOf(basename((string) $nested), $schemas, $seen) as $child) {
                    $fields[] = "{$field}[].{$child}";
                }
            }
        }

        return $fields;
    }

    /** @return array<string, array{required: list<string>, response_fields: list<string>, success_status: string, error_codes: list<string>}> */
    private static function operations(array $document): array
    {
        $operations = [];
        $schemas = $document['components']['schemas'] ?? [];

        foreach ($document['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $key = strtoupper($method) . ' ' . $path;

                $requestRef = $operation['requestBody']['content']['application/json']['schema']['$ref']
                    ?? null;
                $required = $requestRef === null
                    ? []
                    : (array) ($schemas[basename($requestRef)]['required'] ?? []);

                $successStatus = '';
                $responseFields = [];
                $errorCodes = [];

                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if ((int) $status < 300) {
                        $successStatus = (string) $status;
                        $ref = $response['content']['application/json']['schema']['$ref'] ?? null;
                        $responseFields = $ref === null
                            ? []
                            : self::fieldsOf(basename($ref), $schemas);

                        continue;
                    }

                    foreach (explode(', ', (string) ($response['description'] ?? '')) as $code) {
                        if ($code !== '' && ErrorCatalogue::knows($code)) {
                            $errorCodes[] = $code;
                        }
                    }
                }

                $operations[$key] = [
                    'required' => array_values($required),
                    'response_fields' => $responseFields,
                    'success_status' => $successStatus,
                    'error_codes' => array_values(array_unique($errorCodes)),
                ];
            }
        }

        return $operations;
    }
}
