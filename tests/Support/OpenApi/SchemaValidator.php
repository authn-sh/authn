<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Illuminate\Http\Request;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Process-wide cache of the bundled OpenAPI specs (sibling `openapi/dist/`).
 * Validates every test response against the operation's declared response
 * schema. Bundles are loaded once per process and shared across tests.
 *
 * Two bundles ship: `bapi.bundled.json` (server-to-server, host
 * `api.authn.*`) and `fapi.bundled.json` (per-env browser surface, host
 * `<slug>.authn.*`). They share `components/schemas/` definitions but expose
 * disjoint `paths`, so we pick the bundle by which one declares the path
 * the test hit (host fallback handles ambiguity).
 */
final class SchemaValidator
{
    /** @var array<string, array{spec: stdClass, uri: string}>|null */
    private static ?array $bundles = null;

    private static ?SchemaStorage $storage = null;

    /**
     * Maps a normalized path key (`POST:/v1/users/{}/ban`) to the bundle URI
     * that declares it. Built lazily once bundles load.
     *
     * @var array<string, string>|null
     */
    private static ?array $operationBundle = null;

    public static function isAvailable(): bool
    {
        self::load();

        return self::$bundles !== [];
    }

    /**
     * Validates the response body against the operation's declared response
     * schema for the actual status code. No-op when the bundles aren't
     * available (so contributors without the sibling repo aren't blocked).
     */
    public static function validate(Request $request, Response $response): void
    {
        self::load();
        if (self::$bundles === []) {
            if (getenv('OPENAPI_BUNDLES_REQUIRED') === '1' || ($_ENV['OPENAPI_BUNDLES_REQUIRED'] ?? null) === '1') {
                Assert::fail('OpenAPI bundles not found but OPENAPI_BUNDLES_REQUIRED=1. Run `npm run bundle` in the sibling openapi/ repo.');
            }

            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! str_contains($contentType, 'json')) {
            return;
        }

        $route = $request->route();
        // Closures, ping, and dispatch failures (404 with no matched route)
        // can't be looked up; those aren't part of the OpenAPI surface.
        if ($route === null || ! method_exists($route, 'uri')) {
            return;
        }

        $method = strtolower($request->getMethod());
        $rawPath = '/'.ltrim($route->uri(), '/');
        $key = self::operationKey($method, $rawPath);
        if (! isset(self::$operationBundle[$key])) {
            return;
        }

        $bundleName = self::$operationBundle[$key];
        $bundle = self::$bundles[$bundleName];

        $status = (string) $response->getStatusCode();
        $operation = self::lookupOperation($bundle['spec'], $method, $rawPath);
        if ($operation === null) {
            return;
        }

        $responseDef = self::lookupResponse($bundle['spec'], $operation, $status);
        if ($responseDef === null) {
            return;
        }

        $schemaRef = self::responseJsonSchemaRef($responseDef);
        if ($schemaRef === null) {
            return;
        }

        $body = $response->getContent();
        if ($body === '' || $body === false) {
            return;
        }
        $payload = json_decode((string) $body);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        $rootSchema = (object) ['$ref' => $bundle['uri'].'#/'.$schemaRef];
        $validator = new Validator(new Factory(self::$storage));
        $validator->validate($payload, $rootSchema, Constraint::CHECK_MODE_TYPE_CAST);

        if (! $validator->isValid()) {
            $errors = array_map(
                fn ($e) => "{$e['property']}: {$e['message']}",
                $validator->getErrors()
            );
            Assert::fail(sprintf(
                "OpenAPI contract violation: %s %s -> %s\n  - %s",
                strtoupper($method),
                $rawPath,
                $status,
                implode("\n  - ", $errors)
            ));
        }
    }

    private static function load(): void
    {
        if (self::$bundles !== null) {
            return;
        }

        $bases = [
            __DIR__.'/../../../../openapi/dist',
            __DIR__.'/../../../openapi/dist',
        ];
        $bundles = [];
        foreach ($bases as $base) {
            foreach (['bapi.bundled.json', 'fapi.bundled.json'] as $name) {
                $candidate = $base.'/'.$name;
                if (! is_file($candidate)) {
                    continue;
                }
                $spec = json_decode((string) file_get_contents($candidate));
                if (! $spec instanceof stdClass) {
                    continue;
                }
                self::stripExamples($spec);
                $bundles[$name] = [
                    'spec' => $spec,
                    'uri' => 'file://'.$name,
                ];
            }
            if ($bundles !== []) {
                break;
            }
        }
        self::$bundles = $bundles;
        self::$storage = new SchemaStorage;
        self::$operationBundle = [];

        foreach ($bundles as $name => $entry) {
            self::$storage->addSchema($entry['uri'], $entry['spec']);
            $paths = (array) ($entry['spec']->paths ?? []);
            foreach ($paths as $path => $ops) {
                if (! $ops instanceof stdClass) {
                    continue;
                }
                foreach (get_object_vars($ops) as $method => $_op) {
                    if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                        continue;
                    }
                    $key = self::operationKey($method, (string) $path);
                    // First bundle to declare wins. BAPI and FAPI paths are
                    // disjoint so this never collides in practice.
                    self::$operationBundle[$key] ??= $name;
                }
            }
        }
    }

    private static function operationKey(string $method, string $path): string
    {
        $normalized = preg_replace('/\{[^}]+\}/', '{}', $path);

        return strtolower($method).':'.($normalized ?? $path);
    }

    private static function lookupOperation(stdClass $spec, string $method, string $rawPath): ?stdClass
    {
        $paths = $spec->paths ?? null;
        if (! $paths instanceof stdClass) {
            return null;
        }
        $rawCollapsed = (string) preg_replace('/\{[^}]+\}/', '{}', $rawPath);
        foreach (get_object_vars($paths) as $path => $ops) {
            if (! $ops instanceof stdClass) {
                continue;
            }
            $candidateCollapsed = (string) preg_replace('/\{[^}]+\}/', '{}', (string) $path);
            if ($candidateCollapsed !== $rawCollapsed) {
                continue;
            }
            $op = $ops->{$method} ?? null;

            return $op instanceof stdClass ? $op : null;
        }

        return null;
    }

    private static function lookupResponse(stdClass $spec, stdClass $operation, string $status): ?stdClass
    {
        $responses = $operation->responses ?? null;
        if (! $responses instanceof stdClass) {
            return null;
        }
        $entry = $responses->{$status} ?? $responses->default ?? null;
        if (! $entry instanceof stdClass) {
            return null;
        }

        return self::resolveRef($spec, $entry);
    }

    private static function resolveRef(stdClass $spec, stdClass $node): ?stdClass
    {
        if (! property_exists($node, '$ref')) {
            return $node;
        }
        $ref = $node->{'$ref'};
        if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
            return null;
        }
        $segments = explode('/', substr($ref, 2));
        $cursor = $spec;
        foreach ($segments as $seg) {
            $seg = strtr($seg, ['~1' => '/', '~0' => '~']);
            if ($cursor instanceof stdClass && property_exists($cursor, $seg)) {
                $cursor = $cursor->{$seg};
            } else {
                return null;
            }
        }

        return $cursor instanceof stdClass ? self::resolveRef($spec, $cursor) : null;
    }

    /**
     * Returns the JSON-pointer (without the leading `#/`) targeting the
     * response body schema, or null when the response has no JSON schema.
     */
    private static function responseJsonSchemaRef(stdClass $response): ?string
    {
        $content = $response->content ?? null;
        if (! $content instanceof stdClass) {
            return null;
        }
        $json = $content->{'application/json'} ?? null;
        if (! $json instanceof stdClass) {
            return null;
        }
        $schema = $json->schema ?? null;
        if (! $schema instanceof stdClass) {
            return null;
        }
        if (property_exists($schema, '$ref')) {
            $ref = $schema->{'$ref'};
            if (is_string($ref)) {
                $hash = strpos($ref, '#/');
                if ($hash !== false) {
                    return substr($ref, $hash + 2);
                }
            }
        }

        return null;
    }

    private static function stripExamples(mixed $node): void
    {
        if ($node instanceof stdClass) {
            // OpenAPI 3.1 / JSON Schema 2020-12 use `examples[]` for
            // documentation; justinrainbow (draft-07) treats array values as
            // URIs to resolve and chokes when they aren't.
            if (property_exists($node, 'examples')) {
                unset($node->examples);
            }
            if (property_exists($node, 'example')) {
                unset($node->example);
            }
            foreach (get_object_vars($node) as $value) {
                self::stripExamples($value);
            }
        } elseif (is_array($node)) {
            foreach ($node as $value) {
                self::stripExamples($value);
            }
        }
    }
}
