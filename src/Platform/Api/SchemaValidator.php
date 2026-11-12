<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * THE CONFORMANCE GATE — Part XXIV R-XXIV-061.
 *
 * "Every integration test's request and response is validated against the schema
 * at runtime. Fails the build when an implementation returns a field the schema
 * does not declare, or omits a required one."
 *
 * Both directions matter, and the first one is the one usually skipped. A
 * response carrying an UNDECLARED field is not harmless: it is either a leak (a
 * salary or an identifier that reached a payload nobody reviewed) or a field
 * clients will start depending on before it is a contract, at which point
 * removing it becomes a breaking change to something that was never promised.
 *
 * ponytail: a deliberately small subset of JSON Schema — types, required,
 * additionalProperties, enum, and $ref into components. That is everything the
 * generated document uses. Upgrade path if the document ever needs more:
 * allOf/oneOf and format assertions, added here, in this one class.
 */
final class SchemaValidator
{
    /**
     * @param array<string, mixed> $document the generated OpenAPI document
     * @return list<string> empty means the payload conforms
     */
    public static function validate(array $document, string $schemaName, array $payload): array
    {
        $schemas = $document['components']['schemas'] ?? [];

        if (!isset($schemas[$schemaName])) {
            return ["the document declares no schema '{$schemaName}', so this payload was never published"];
        }

        return self::check($schemas[$schemaName], $payload, $schemas, $schemaName);
    }

    /** @return list<string> */
    private static function check(array $schema, mixed $value, array $schemas, string $path): array
    {
        if (isset($schema['$ref'])) {
            $name = basename((string) $schema['$ref']);

            return isset($schemas[$name])
                ? self::check($schemas[$name], $value, $schemas, $path)
                : ["{$path}: \$ref to an undeclared schema '{$name}'"];
        }

        $problems = [];
        $type = $schema['type'] ?? null;

        if ($type === 'object') {
            if (!is_array($value)) {
                return ["{$path}: expected an object, got " . get_debug_type($value)];
            }

            foreach ((array) ($schema['required'] ?? []) as $field) {
                if (!array_key_exists($field, $value)) {
                    $problems[] = "{$path}.{$field}: required by the schema and absent from the payload";
                }
            }

            $properties = (array) ($schema['properties'] ?? []);

            foreach ($value as $field => $child) {
                if (!isset($properties[$field])) {
                    // Not harmless. An undeclared field is either a leak, or one
                    // clients will depend on before it is a contract — at which
                    // point removing it breaks something never promised.
                    $problems[] = "{$path}.{$field}: present in the payload and NOT declared by the schema";

                    continue;
                }

                $problems = [...$problems, ...self::check($properties[$field], $child, $schemas,
                    "{$path}.{$field}")];
            }

            return $problems;
        }

        if ($type === 'array') {
            if (!is_array($value)) {
                return ["{$path}: expected an array"];
            }

            foreach ($value as $index => $item) {
                $problems = [...$problems, ...self::check((array) ($schema['items'] ?? []), $item,
                    $schemas, "{$path}[{$index}]")];
            }

            return $problems;
        }

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return ["{$path}: " . var_export($value, true) . ' is not one of the declared values'];
        }

        $ok = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            null => true,
            default => true,
        };

        return $ok ? [] : ["{$path}: expected {$type}, got " . get_debug_type($value)];
    }
}
