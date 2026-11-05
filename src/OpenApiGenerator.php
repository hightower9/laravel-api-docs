<?php

namespace Souqbox\ApiDocs;

use Generator;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Builds the OpenAPI 3.1 document for a portal from its routes, FormRequest rules and the docs/api
 * overlays.
 */
class OpenApiGenerator
{
    /**
     * Path item keys that are operations.
     */
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options'];

    /**
     * Fallback summaries for resource actions without a useful PHPDoc.
     */
    private const RESOURCE_SUMMARIES = [
        'index'   => 'List %s',
        'store'   => 'Create %s',
        'show'    => 'Get %s',
        'update'  => 'Update %s',
        'destroy' => 'Delete %s',
    ];

    /**
     * Warnings raised during the current run.
     */
    private array $warnings = [];

    /**
     * Issued operationIds, used to keep them unique.
     */
    private array $operationIds = [];

    /**
     * Request schemas resolved in the current run, keyed by route, FormRequest and method.
     */
    private array $schemas = [];

    /**
     * Path parameter types per model class.
     */
    private array $keyTypes = [];

    /**
     * Create a new generator.
     */
    public function __construct(
        private readonly Router $router,
        private readonly RulesToSchema $rulesToSchema,
    ) {
    }

    /**
     * Generate the OpenAPI document for the given portal.
     */
    public function generate(string $portal): array
    {
        $this->warnings = [];
        $this->operationIds = [];
        $this->schemas = [];

        $config = config("api-docs.portals.{$portal}");
        $cache = new OperationCache(storage_path("framework/cache/api-docs/operations-{$portal}.bin"), $this->salt());
        $paths = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! $this->belongsTo($route, $config)) {
                continue;
            }

            $key = md5(json_encode([$route->methods(), $route->uri(), $route->getActionName(), $route->middleware(), $route->wheres]));
            $entry = $cache->get($key) ?? $cache->put($key, $this->describeRoute($route, $portal, $config));
            array_push($this->warnings, ...$entry['warnings']);

            foreach ($entry['operations'] as $method => $operation) {
                $paths[$entry['path']][$method] = ['operationId' => $this->operationId($method, $route->uri())] + $operation;
            }
        }

        $cache->save();

        $spec = [
            'openapi' => '3.1.0',
            'info'    => [
                'title'   => config('api-docs.title') . ' · ' . $config['title'],
                'version' => (string) config('api-docs.version'),
            ],
            // Relative so the spec is identical on every machine (required by "api-docs:generate --check").
            'servers'    => [['url' => '/', 'description' => 'The server hosting these docs']],
            'security'   => [["{$portal}Auth" => []]],
            'paths'      => $paths,
            'components' => $this->components($portal, $config),
        ];

        $spec = (new OverlayMerger(config('api-docs.overlay_path')))->apply($spec, $portal, [
            '{{title}}'  => $config['title'],
            '{{login}}'  => '/' . $config['login'],
            '{{logout}}' => '/' . $config['logout'],
        ]);

        return $this->withTags($spec);
    }

    /**
     * Get the warnings raised by the last run.
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Describe a route for the operation cache: its operations, source files and warnings.
     */
    private function describeRoute(Route $route, string $portal, array $config): array
    {
        $inspector = new RouteInspector($route);
        $warningsBefore = count($this->warnings);
        $operations = [];

        foreach (array_diff(array_map('strtolower', $route->methods()), ['head']) as $method) {
            $operations[$method] = $this->operation($inspector, $method, $portal, $config);
        }

        $warnings = array_splice($this->warnings, $warningsBefore);
        $dependencies = collect($inspector->dependencies())->mapWithKeys(fn (string $file) => [$file => filemtime($file)])->all();

        return [
            'path'         => '/' . str_replace('?}', '}', $route->uri()),
            'operations'   => $operations,
            'dependencies' => $dependencies,
            'warnings'     => $warnings,
        ];
    }

    /**
     * Fingerprint of the package code and config; any change, such as a package update,
     * invalidates the whole cache.
     */
    private function salt(): string
    {
        $files = [...glob(__DIR__ . '/*.php'), __DIR__ . '/../config/api-docs.php', config_path('api-docs.php')];

        return md5(implode('|', array_map('md5_file', array_filter($files, 'is_file'))));
    }

    /**
     * Determine whether the route belongs to the portal.
     */
    private function belongsTo(Route $route, array $config): bool
    {
        $uri = $route->uri();

        $excluded = [...config('api-docs.exclude'), ...($config['exclude'] ?? [])];

        if ($route->getActionName() === 'Closure' || Str::is($excluded, $uri)) {
            return false;
        }

        return collect($config['prefixes'])->contains(fn (string $prefix) => $uri === $prefix || Str::startsWith($uri, "{$prefix}/"));
    }

    /**
     * Build the OpenAPI operation for one HTTP method of the route.
     */
    private function operation(RouteInspector $inspector, string $method, string $portal, array $config): array
    {
        [$class, $action] = $inspector->action();
        [$group, $tag] = $this->classify($class, $config['controllers']);
        [$summary, $details] = $this->describe($inspector, $class, $action);

        $guards = $inspector->guards();
        $usesApiKey = $this->usesApiKey($inspector, $guards, $config);
        $isAuthenticated = $guards !== [] || ! $inspector->middlewareResolved();
        $permissions = $inspector->permissions();

        $operation = [
            'tags'     => [$tag],
            'summary'  => $summary,
            'x-group'  => $group,
            'security' => match (true) {
                $usesApiKey      => [[$config['api_key']['scheme'] => []]],
                $isAuthenticated => [["{$portal}Auth" => []]],
                default          => [],
            },
        ];

        $description = array_filter([
            $details,
            $permissions ? '**Permissions** (any of): ' . implode(', ', array_map(fn (string $permission) => "`{$permission}`", $permissions)) : null,
            $inspector->hasMiddleware('2fa') ? '**Requires** a validated 2FA session.' : null,
            '<sub>Handled by `' . class_basename($class) . "@{$action}`</sub>",
        ]);
        $operation['description'] = implode("\n\n", $description);

        $parameters = $this->pathParameters($inspector);
        $formRequest = $inspector->formRequest();
        $request = $formRequest ? $this->requestSchema($inspector, $formRequest, $method) : null;
        $source = $inspector->source();

        if (in_array($method, ['get', 'delete'], true)) {
            $parameters = [
                ...$parameters,
                ...($request ? $this->rulesToSchema->toQueryParameters($request['schema']) : []),
                ...($source ? $this->sourceQueryParameters($source) : []),
            ];
        } elseif ($request) {
            $operation['requestBody'] = [
                'required' => ! empty($request['schema']['required']),
                'content'  => [
                    ($request['multipart'] ? 'multipart/form-data' : 'application/json') => ['schema' => $request['schema']],
                ],
            ];
        } elseif ($inputs = $source?->inputs()) {
            // No FormRequest: document the fields the controller reads, without types ({} = any).
            $operation['requestBody'] = [
                'content' => [
                    'application/json' => ['schema' => [
                        'type'       => 'object',
                        'properties' => array_fill_keys($inputs, new stdClass()),
                    ]],
                ],
            ];
        }

        if ($parameters) {
            $operation['parameters'] = array_values(collect($parameters)->unique(fn (array $parameter) => "{$parameter['in']}:{$parameter['name']}")->all());
        }

        $file = $source?->fileResponse();

        $operation['responses'] = array_filter([
            '200' => $file
                ? ['description' => 'File download.', 'content' => [$file => ['schema' => ['type' => 'string', 'format' => 'binary']]]]
                : ['$ref' => '#/components/responses/Success'],
            '401' => $isAuthenticated || $usesApiKey ? ['$ref' => '#/components/responses/Unauthenticated'] : null,
            '403' => $permissions ? ['$ref' => '#/components/responses/Forbidden'] : null,
            '404' => $inspector->route->parameterNames() ? ['$ref' => '#/components/responses/NotFound'] : null,
            '422' => $formRequest ? ['$ref' => '#/components/responses/ValidationError'] : null,
        ]);

        return $operation;
    }

    /**
     * Get the sidebar [group, tag] from the controller folder, e.g. Sales\ForecastTool => [Sales,
     * Forecast Tool].
     */
    private function classify(string $class, string $root): array
    {
        $relative = Str::startsWith($class, "{$root}\\")
            ? Str::after($class, "{$root}\\")
            : Str::afterLast($class, 'Controllers\\');

        $parts = explode('\\', $relative);
        $controller = array_pop($parts);

        if (Str::endsWith($controller, 'AuthController')) {
            return ['Authentication', 'Authentication'];
        }

        $name = $this->humanize(Str::endsWith($controller, 'Controller') ? Str::beforeLast($controller, 'Controller') : $controller);

        if ($parts === []) {
            return ['General', $name];
        }

        $group = $this->humanize(array_shift($parts));

        return [$group, $parts ? implode(' / ', array_map($this->humanize(...), $parts)) : $name];
    }

    /**
     * Get the [summary, description] from the PHPDoc, or a sensible default.
     */
    private function describe(RouteInspector $inspector, string $class, string $action): array
    {
        [$summary, $details] = $inspector->docBlock();

        if ($summary) {
            return [$summary, $details];
        }

        $resource = Str::lower($this->humanize(Str::beforeLast(class_basename($class), 'Controller')));

        if (isset(self::RESOURCE_SUMMARIES[$action])) {
            return [sprintf(self::RESOURCE_SUMMARIES[$action], $action === 'index' ? Str::plural($resource) : $resource), null];
        }

        if ($action === '__invoke') {
            return [Str::ucfirst($resource), null];
        }

        return [Str::ucfirst(Str::lower($this->humanize($action))), null];
    }

    /**
     * Build the route's path parameters.
     */
    private function pathParameters(RouteInspector $inspector): array
    {
        $models = $inspector->boundModels();
        $wheres = $inspector->route->wheres;

        return array_map(function (string $name) use ($models, $wheres): array {
            $model = $models[$name] ?? $models[Str::camel($name)] ?? null;
            $schema = ['type' => $this->keyType($model, $wheres[$name] ?? null)];

            return array_filter([
                'name'        => $name,
                'in'          => 'path',
                'required'    => true,
                'description' => $model ? 'ID of the ' . Str::lower($this->humanize(class_basename($model))) . '.' : null,
                'schema'      => $schema,
            ], fn (mixed $value) => $value !== null);
        }, $inspector->route->parameterNames());
    }

    /**
     * Build the query parameters found in the controller source: inputs, filters, sorting and
     * pagination.
     */
    private function sourceQueryParameters(SourceInspector $source): array
    {
        $query = fn (string $name, array $schema, ?string $description = null): array => array_filter([
            'name'        => $name,
            'in'          => 'query',
            'required'    => false,
            'description' => $description,
            'schema'      => $schema,
        ], fn (mixed $value) => $value !== null);

        $list = fn (array $values): string => implode(', ', array_map(fn (string $value) => "`{$value}`", $values));

        $parameters = array_map(
            fn (string $name) => $query(preg_replace('/\.(\w+)/', '[$1]', $name), ['type' => 'string']),
            $source->inputs(),
        );

        foreach ($source->filters() as $filter) {
            $parameters[] = $query("filter[{$filter}]", ['type' => 'string']);
        }

        if ($sorts = $source->sorts()) {
            $parameters[] = $query('sort', array_filter(['type' => 'string', 'default' => $source->defaultSort()]),
                "Comma separated, prefix with `-` for descending. Allowed: {$list($sorts)}.");
        }

        if ($includes = $source->includes()) {
            $parameters[] = $query('include', ['type' => 'string'], "Comma separated relations. Allowed: {$list($includes)}.");
        }

        $pagination = config('api-docs.pagination');

        foreach ($source->pagination($pagination['methods'], $pagination['size_parameter'] ?? null) as $name) {
            $parameters[] = $query($name, $pagination['parameters'][$name]['schema'], $pagination['parameters'][$name]['description'] ?? null);
        }

        return $parameters;
    }

    /**
     * Get the JSON type of a path parameter.
     */
    private function keyType(?string $model, ?string $pattern): string
    {
        if ($pattern !== null) {
            return in_array($pattern, ['[0-9]+', '\d+'], true) ? 'integer' : 'string';
        }

        return $this->keyTypes[$model ?? ''] ??= $this->modelKeyType($model);
    }

    /**
     * Get "integer" for models with an incrementing key, otherwise "string".
     */
    private function modelKeyType(?string $model): string
    {
        try {
            $instance = $model ? new $model() : null;
        } catch (Throwable) {
            $instance = null;
        }

        return $instance && $instance->getKeyType() === 'int' && $instance->getRouteKeyName() === $instance->getKeyName()
            ? 'integer'
            : 'string';
    }

    /**
     * Convert the FormRequest rules into a request schema; failures are recorded as warnings.
     */
    private function requestSchema(RouteInspector $inspector, string $formRequest, string $method): ?array
    {
        $key = $inspector->route->uri() . "|{$formRequest}|" . ($method === 'patch' ? 'put' : $method);

        if (array_key_exists($key, $this->schemas)) {
            return $this->schemas[$key];
        }

        try {
            return $this->schemas[$key] = $this->rulesToSchema->convert($inspector->rules($formRequest, $method));
        } catch (Throwable $e) {
            $this->warnings[] = class_basename($formRequest) . ': ' . Str::limit($e->getMessage(), 120);

            return $this->schemas[$key] = null;
        }
    }

    /**
     * Build a unique operationId, e.g. "getAdminCustomers".
     */
    private function operationId(string $method, string $uri): string
    {
        $id = Str::camel($method . ' ' . preg_replace('/[^A-Za-z0-9]+/', ' ', Str::after($uri, 'api/')));
        $count = $this->operationIds[$id] = ($this->operationIds[$id] ?? 0) + 1;

        return $count > 1 ? "{$id}{$count}" : $id;
    }

    /**
     * Build the tag list and sidebar groups from the operations.
     */
    private function withTags(array $spec): array
    {
        $groupsByTag = [];
        foreach ($this->operations($spec) as [$path, $method, $operation]) {
            $groupsByTag[$operation['tags'][0] ?? 'General'][$operation['x-group'] ?? 'General'] = true;
        }

        $tagGroups = [];
        $displayNames = [];
        foreach ($this->operations($spec) as [$path, $method, $operation]) {
            $group = $operation['x-group'] ?? 'General';
            $tag = $operation['tags'][0] ?? 'General';

            if (count($groupsByTag[$tag]) > 1 && $tag !== $group) {
                $displayNames["{$group} › {$tag}"] = $tag;
                $tag = "{$group} › {$tag}";
            }

            $spec['paths'][$path][$method]['tags'] = [$tag];
            unset($spec['paths'][$path][$method]['x-group']);
            $tagGroups[$group][$tag] = true;
        }

        uksort($tagGroups, fn (string $a, string $b) => $this->groupWeight($a) <=> $this->groupWeight($b) ?: strcasecmp($a, $b));

        $existing = collect($spec['tags'] ?? [])->keyBy('name');
        $spec['tags'] = [];
        $spec['x-tagGroups'] = [];

        foreach ($tagGroups as $group => $tags) {
            $names = array_keys($tags);
            natcasesort($names);
            $names = array_values($names);

            $spec['x-tagGroups'][] = ['name' => $group, 'tags' => $names];
            foreach ($names as $name) {
                $spec['tags'][] = $existing->get($name, array_filter(['name' => $name, 'x-displayName' => $displayNames[$name] ?? null]));
            }
        }

        return $spec;
    }

    /**
     * Iterate over every operation as [path, method, operation].
     */
    private function operations(array $spec): Generator
    {
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (in_array($method, self::HTTP_METHODS, true) && is_array($operation)) {
                    yield [$path, $method, $operation];
                }
            }
        }
    }

    /**
     * Sort weight of a sidebar group: Authentication first, General last.
     */
    private function groupWeight(string $group): int
    {
        return match ($group) {
            'Authentication' => 0,
            'General'        => 2,
            default          => 1,
        };
    }

    /**
     * Turn an identifier into words, e.g. "ExternalAPI" => "External API".
     */
    private function humanize(string $value): string
    {
        return trim(preg_replace(
            ['/[_-]+/', '/(?<=[a-z])(?=[A-Z0-9])/', '/(?<=[0-9])(?=[A-Z][a-z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/'],
            ' ',
            $value,
        ));
    }

    /**
     * Determine whether the route authenticates with the portal's API key instead of a token.
     */
    private function usesApiKey(RouteInspector $inspector, array $guards, array $config): bool
    {
        $apiKey = $config['api_key'] ?? [];

        return array_intersect($guards, $apiKey['guards'] ?? []) !== []
            || collect($apiKey['middleware'] ?? [])->contains(fn (string $middleware) => $inspector->hasMiddleware($middleware));
    }

    /**
     * Build the shared security schemes, schemas and error responses.
     */
    private function components(string $portal, array $config): array
    {
        $message = function (string $example): array {
            $schema = ['type' => 'object', 'properties' => ['message' => ['type' => 'string', 'examples' => [$example]]]];
            $envelope = config('api-docs.error_envelope');

            return $envelope ? ['type' => 'object', 'properties' => [$envelope => $schema]] : $schema;
        };

        $securitySchemes = [
            "{$portal}Auth" => array_filter([
                'type'         => 'http',
                'scheme'       => 'bearer',
                'bearerFormat' => $config['bearer_format'] ?? null,
                'description'  => "Access token returned by `POST /{$config['login']}` (`" . ($config['token_path'] ?? 'data.token') . '`). The docs page stores it automatically after you log in.',
            ]),
        ];

        if ($apiKey = $config['api_key'] ?? null) {
            $securitySchemes[$apiKey['scheme']] = [
                'type'        => 'apiKey',
                'in'          => 'header',
                'name'        => $apiKey['header'],
                'description' => $apiKey['description'],
            ];
        }

        return [
            'securitySchemes' => $securitySchemes,
            'schemas'         => [
                'SuccessEnvelope' => [
                    'type'       => 'object',
                    'required'   => ['data'],
                    'properties' => ['data' => ['description' => 'Response payload. Shape depends on the endpoint.']],
                ],
                'ValidationError' => [
                    'type'       => 'object',
                    'properties' => [
                        'message' => ['type' => 'string', 'examples' => ['The name field is required.']],
                        'errors'  => [
                            'type'                 => 'object',
                            'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'examples'             => [['name' => ['The name field is required.']]],
                        ],
                    ],
                ],
            ],
            'responses' => [
                'Success' => [
                    'description' => 'Successful response.',
                    'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessEnvelope']]],
                ],
                'Unauthenticated' => [
                    'description' => 'Missing or invalid access token.',
                    'content'     => ['application/json' => ['schema' => $message('Unauthenticated.')]],
                ],
                'Forbidden' => [
                    'description' => 'The user lacks the required permission.',
                    'content'     => ['application/json' => ['schema' => $message('Forbidden')]],
                ],
                'NotFound' => [
                    'description' => 'The requested record does not exist.',
                    'content'     => ['application/json' => ['schema' => $message('Record not found.')]],
                ],
                'ValidationError' => [
                    'description' => 'The request failed validation.',
                    'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
                ],
            ],
        ];
    }
}
