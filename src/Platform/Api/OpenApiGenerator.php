<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * THE DOCUMENT IS GENERATED — Part XXIV R-XXIV-057, R-XXIV-063.
 *
 * OpenAPI 3.1, built from the route registry, the error catalogue and the
 * resource schemas. Never hand-maintained: a hand-written specification drifts,
 * and SmartPRS 2's documentation disagrees with its source in sixteen recorded
 * places — including three comments that state the opposite of the code they
 * annotate. A document that can disagree with the code eventually does, and
 * partners believe the document.
 *
 * R-XXIV-063: the error catalogue, the scope registry and the payload schemas
 * come from the SAME sources as this document, "so all four are mutually
 * consistent BY CONSTRUCTION". That phrase is the design: consistency is not
 * something a reviewer checks, it is something the generator cannot violate
 * because it reads one set of facts.
 *
 * The tenant-schema variant (R-XXIV-058) is what makes "add a LinkedIn Profile
 * field" a configuration task ALL THE WAY OUT TO THE API. A custom field that
 * exists in the database and not in the published contract is a field no
 * integration can use, which quietly turns the entity-schema engine back into a
 * feature only the UI has.
 */
final class OpenApiGenerator
{
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly string $version,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $schemas resource schemas, by name
     * @param array<string, array<string, string>> $tenantCustomFields entity => field => type,
     *        materialised only for a tenant-bound credential (R-XXIV-058)
     */
    public function generate(array $schemas, array $tenantCustomFields = []): array
    {
        $paths = [];

        foreach ($this->routes->all() as $route) {
            $path = $this->templatePath($route['path']);
            $operation = [
                'operationId' => $route['operation_id'],
                'summary' => $route['summary'],
                'security' => [['bearer' => $route['scopes']]],
                // The permission travels INTO the document. An endpoint whose
                // authorisation is documented only in the code is one an
                // integrator discovers by getting a 403.
                'x-permission' => $route['permission'],
                'x-scopes' => $route['scopes'],
                'parameters' => $this->parametersFor($route),
                'responses' => $this->responsesFor($route),
            ];

            if ($route['request_schema'] !== null) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $route['request_schema']],
                    ]],
                ];
            }

            $paths[$path][strtolower($route['method'])] = $operation;
        }

        // Custom fields are materialised into the schemas they extend, not
        // bolted on as a free-form bag: an integrator must be able to see the
        // field's TYPE, or they cannot generate a client for it.
        foreach ($tenantCustomFields as $entity => $fields) {
            foreach ($fields as $field => $type) {
                $schemas[$entity]['properties'][$field] = [
                    'type' => $type,
                    'x-tenant-custom-field' => true,
                ];
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SmartPRS 3 API',
                'version' => $this->version,
                'description' => 'The only programmatic surface of SmartPRS 3. The first-party client '
                    . 'calls these routes with the identical serialisation, authorisation and error '
                    . 'envelope a third party gets.',
            ],
            'servers' => [['url' => 'https://{host}/api/v3', 'variables' => ['host' => ['default' => '']]]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => $schemas + ['Error' => $this->errorSchema()],
                // Generated from the same catalogue the envelope validates
                // against, so the two cannot disagree.
                'x-error-catalogue' => ErrorCatalogue::CODES,
            ],
        ];
    }

    /** `{id:uuid}` in the registry becomes `{id}` in the document, with the type on the parameter. */
    private function templatePath(string $path): string
    {
        return (string) preg_replace('/\{([a-z_]+):uuid\}/', '{$1}', $path);
    }

    /** @return list<array<string, mixed>> */
    private function parametersFor(array $route): array
    {
        $parameters = [];

        preg_match_all('/\{([a-z_]+):uuid\}/', $route['path'], $m);

        foreach ($m[1] as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ];
        }

        if ($route['method'] === 'GET' && !str_contains($route['path'], '{')) {
            // Cursor, never offset — and it is in the document so nobody has to
            // discover it by trying `?page=2` and getting a 400.
            $parameters[] = ['name' => 'cursor', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string'],
                'description' => 'An opaque cursor from a previous response. Offset pagination is not '
                    . 'offered: it silently skips and duplicates rows under concurrent writes.'];
            $parameters[] = ['name' => 'limit', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'integer', 'default' => 50, 'maximum' => 500]];
            $parameters[] = ['name' => 'as_of', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string', 'format' => 'date'],
                'description' => 'Resolve effective-dated content as at this date; defaults to today.'];
        }

        if ($route['idempotent']) {
            $parameters[] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                'schema' => ['type' => 'string'],
                'description' => 'Required. A replay with the same key and body returns the stored '
                    . 'response; a replay with a different body is refused and never executed.'];
        }

        return $parameters;
    }

    /** @return array<string, array<string, mixed>> */
    private function responsesFor(array $route): array
    {
        $responses = [
            (string) ($route['method'] === 'POST' ? 201 : 200) => [
                'description' => 'Success',
                'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/' . $route['response_schema']],
                ]],
            ],
        ];

        // Every route can return these, so every route documents them. An
        // integrator who has to guess which errors an endpoint produces writes
        // a client that handles the ones they happened to see in testing.
        $universal = ['auth.unauthenticated', 'auth.forbidden', 'resource.not_found',
            'rate.exceeded', 'server.unexpected'];

        foreach ([...$universal, ...$route['error_codes']] as $code) {
            $status = (string) ErrorCatalogue::statusFor($code);
            $responses[$status]['description'] = ($responses[$status]['description'] ?? '')
                . ($responses[$status]['description'] ?? '' ? ', ' : '') . $code;
            $responses[$status]['content'] = ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/Error'],
            ]];
        }

        return $responses;
    }

    private function errorSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['error'],
            'properties' => ['error' => [
                'type' => 'object',
                'required' => ['code', 'message', 'status', 'request_id', 'correlation_id', 'retryable'],
                'properties' => [
                    'code' => ['type' => 'string', 'enum' => array_keys(ErrorCatalogue::CODES)],
                    'message' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'request_id' => ['type' => 'string'],
                    'correlation_id' => ['type' => 'string'],
                    'documentation_url' => ['type' => 'string'],
                    'details' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'retryable' => ['type' => 'boolean'],
                ],
            ]],
        ];
    }
}
