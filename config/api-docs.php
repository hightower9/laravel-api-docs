<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Documentation
    |--------------------------------------------------------------------------
    |
    | "php artisan api-docs:generate" builds a static docs site into public/<path>
    | from the registered routes and FormRequest rules, enriched with the YAML /
    | Markdown files in "overlay_path". When disabled (production by default)
    | the command generates nothing and removes any previous build.
    |
    */

    'enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),

    // URL path of the docs, and the folder under public/ the static site is written to.
    'path' => 'docs',

    // Shown in the page title and the header.
    'title' => env('APP_NAME', 'Laravel') . ' API',

    // The "info.version" of every generated spec.
    'version' => env('API_DOCS_VERSION', '1.0.0'),

    // Hand-written intro Markdown and YAML fragments merged into the generated specs.
    'overlay_path' => base_path('docs/api'),

    // Extra servers the "Try it" console can send requests to, besides the one serving the docs.
    // Comma separated Name=URL pairs, e.g. "Dev=https://dev-api.example.com,UAT=https://uat-api.example.com".
    'servers' => collect(explode(',', (string) env('API_DOCS_SERVERS', '')))
        ->map(fn (string $pair) => array_map('trim', explode('=', $pair, 2)))
        ->filter(fn (array $pair) => count($pair) === 2 && filter_var($pair[1], FILTER_VALIDATE_URL))
        ->map(fn (array $pair) => ['name' => $pair[0], 'url' => rtrim($pair[1], '/')])
        ->values()
        ->all(),

    // "api-docs:generate --watch" rebuilds when one of these changes (globs, relative to the project root).
    'watch' => [
        'app/Http',
        'routes',
        'docs/api',
        'config/api-docs.php',
    ],

    // Route URIs left out of the docs (Str::is patterns).
    'exclude' => [],

    // The key error messages are wrapped in, e.g. "data" for { "data": { "message": "…" } }.
    'error_envelope' => null,

    // What marks an endpoint as paginated, and the query parameters that documents.
    'pagination' => [
        // Code found in the controller => the parameters it accepts. The first match wins.
        'methods' => [
            'simplePaginate(' => ['page'],
            'cursorPaginate(' => ['page'],
            '->paginate('     => ['page'],
        ],

        // Also documented when the controller reads this input by name.
        'size_parameter' => 'per_page',

        'parameters' => [
            'page'     => ['schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'description' => 'Page number.'],
            'per_page' => ['schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 15], 'description' => 'Items per page.'],
        ],
    ],

    // One docs portal per API audience. "prefixes" select its routes, "controllers" is the
    // namespace the sidebar groups are derived from, "login" / "logout" drive the token handling.
    //
    // Optional per portal:
    //   token_path     Where the login response holds the token (default "data.token").
    //   bearer_format  Shown on the token scheme, e.g. "JWT".
    //   exclude        Route URIs left out of this portal only (Str::is patterns).
    //   api_key        Routes that use a header key instead of a token:
    //                  scheme, header, description, middleware (list), guards (list).
    'portals' => [
        'api' => [
            'title'       => 'API',
            'prefixes'    => ['api'],
            'guard'       => 'sanctum',
            'controllers' => 'App\Http\Controllers',
            'login'       => 'api/login',
            'logout'      => 'api/logout',
        ],
    ],

];
