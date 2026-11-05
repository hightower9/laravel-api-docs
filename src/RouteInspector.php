<?php

namespace Hightower9\ApiDocs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Throwable;

/**
 * Inspects a route's controller action: middleware, FormRequest, model bindings and PHPDoc.
 */
class RouteInspector
{
    /**
     * The controller method, or null when it cannot be reflected.
     */
    private ?ReflectionMethod $method;

    /**
     * The resolved middleware, cached after the first call.
     */
    private ?array $middleware = null;

    /**
     * False when only the route-level middleware could be read.
     */
    private bool $middlewareResolved = true;

    /**
     * Cached source analysis of the controller method.
     */
    private ?SourceInspector $source = null;

    /**
     * Create a new inspector for the route.
     */
    public function __construct(public readonly Route $route)
    {
        $this->method = $this->reflectMethod();
    }

    /**
     * Get the controller [class, method]; invokable controllers use "__invoke".
     */
    public function action(): array
    {
        [$class, $method] = array_pad(explode('@', $this->route->getActionName()), 2, '__invoke');

        return [ltrim($class, '\\'), $method];
    }

    /**
     * Get the route and controller middleware, or only the route's when the controller can't be
     * resolved.
     */
    public function middleware(): array
    {
        if ($this->middleware !== null) {
            return $this->middleware;
        }

        try {
            return $this->middleware = $this->route->gatherMiddleware();
        } catch (Throwable) {
            $this->middlewareResolved = false;

            return $this->middleware = $this->route->middleware();
        }
    }

    /**
     * Determine whether the full middleware stack is known.
     */
    public function middlewareResolved(): bool
    {
        $this->middleware();

        return $this->middlewareResolved;
    }

    /**
     * Get the auth guards required by the route.
     */
    public function guards(): array
    {
        return collect($this->middleware())
            ->filter(fn (mixed $middleware) => is_string($middleware) && Str::before($middleware, ':') === 'auth')
            ->flatMap(fn (string $middleware) => str_contains($middleware, ':') ? explode(',', Str::after($middleware, ':')) : ['default'])
            ->values()
            ->all();
    }

    /**
     * Get the permissions required by the route; any one of them grants access.
     */
    public function permissions(): array
    {
        return collect($this->middleware())
            ->filter(fn (mixed $middleware) => is_string($middleware) && Str::startsWith($middleware, 'permission:'))
            ->flatMap(fn (string $middleware) => explode('|', Str::after($middleware, 'permission:')))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Determine whether the route uses the given middleware alias.
     */
    public function hasMiddleware(string $name): bool
    {
        return collect($this->middleware())->contains(
            fn (mixed $middleware) => is_string($middleware) && Str::before($middleware, ':') === $name
        );
    }

    /**
     * Get the FormRequest class used by the controller method.
     */
    public function formRequest(): ?string
    {
        foreach ($this->method?->getParameters() ?? [] as $parameter) {
            $class = $this->parameterClass($parameter->getType());

            if ($class && is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Evaluate the FormRequest rules against a stub request, which is also bound as request().
     */
    public function rules(string $formRequest, string $method): array
    {
        $stub = Request::create('/' . $this->route->uri(), strtoupper($method));
        $stub->setRouteResolver(fn () => $this->placeholderRoute());
        $stub->setUserResolver(fn () => null);

        $request = $formRequest::createFrom($stub);
        $request->setContainer(app())->setRedirector(app('redirect'));

        $original = app('request');
        app()->instance('request', $stub);

        try {
            return method_exists($request, 'rules') ? (array) app()->call([$request, 'rules']) : [];
        } finally {
            app()->instance('request', $original);
        }
    }

    /**
     * Get the source files the route's docs depend on.
     */
    public function dependencies(): array
    {
        return collect([$this->action()[0], $this->formRequest(), ...array_values($this->boundModels())])
            ->filter()
            ->unique()
            ->map(fn (string $class) => rescue(fn () => (new ReflectionClass($class))->getFileName(), null, false))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the source analysis of the controller method.
     */
    public function source(): ?SourceInspector
    {
        return $this->source ??= $this->method ? new SourceInspector($this->method, array_keys(config('api-docs.pagination.parameters', []))) : null;
    }

    /**
     * Get the models bound to route parameters, keyed by parameter name.
     */
    public function boundModels(): array
    {
        $models = [];

        foreach ($this->method?->getParameters() ?? [] as $parameter) {
            $class = $this->parameterClass($parameter->getType());

            if ($class && is_subclass_of($class, Model::class)) {
                $models[Str::snake($parameter->getName())] = $class;
                $models[$parameter->getName()] = $class;
            }
        }

        return $models;
    }

    /**
     * Get the [summary, description] from the method's PHPDoc, ignoring Laravel's stub comments.
     */
    public function docBlock(): array
    {
        $comment = $this->method?->getDocComment();

        if (! $comment) {
            return [null, null];
        }

        $lines = [];
        foreach (preg_split('/\R/', $comment) as $line) {
            $line = trim(preg_replace('#^\s*/?\*+/?#', '', $line));

            if (Str::startsWith($line, '@')) {
                break;
            }

            $lines[] = $line;
        }

        $text = trim(implode("\n", $lines));

        // Stubs such as "Display a listing of the resource." or "Show the specified resource."
        if ($text === '' || preg_match('/\b(an?|the)( specified| newly created)? resources?\b|resource in storage|constructor/i', $text)) {
            return [null, null];
        }

        $parts = preg_split('/\n\s*\n|\n/', $text, 2);

        return [rtrim(trim($parts[0]), '.'), isset($parts[1]) ? trim($parts[1]) : null];
    }

    /**
     * Copy the route with empty models as parameters, so rules() can read them.
     */
    private function placeholderRoute(): Route
    {
        $route = clone $this->route;
        $route->parameters = [];
        $models = $this->boundModels();

        foreach ($route->parameterNames() as $name) {
            $model = $models[$name] ?? $models[Str::camel($name)] ?? null;
            $route->setParameter($name, $model ? new $model() : null);
        }

        return $route;
    }

    /**
     * Reflect the controller method.
     */
    private function reflectMethod(): ?ReflectionMethod
    {
        [$class, $method] = $this->action();

        try {
            return (new ReflectionClass($class))->getMethod($method);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the class name of a parameter type, if it is a class.
     */
    private function parameterClass(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;
    }
}
