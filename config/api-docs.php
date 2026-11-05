<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Documentation Status
    |--------------------------------------------------------------------------
    |
    | Determines whether the documentation is built and served. When disabled,
    | "php artisan api-docs:generate" builds nothing and removes any previous
    | build, so the site cannot be exposed by accident. By default, it will
    | be enabled in every environment except for production.
    |
    */

    'enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Documentation Path
    |--------------------------------------------------------------------------
    |
    | The URL path the documentation is served from. This value also names
    | the folder inside "public" that the static site is written to, which
    | should be excluded from version control as it is a build artifact.
    |
    */

    'path' => 'docs',

    /*
    |--------------------------------------------------------------------------
    | Documentation Title
    |--------------------------------------------------------------------------
    |
    | The name shown in the page title and header. The first letter of this
    | value is also used for the favicon and the mark shown in the sidebar.
    |
    */

    'title' => env('APP_NAME', 'Laravel') . ' API',

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | The version of your API, published as "info.version" in every generated
    | OpenAPI document. This is independent of the version of this package.
    |
    */

    'version' => env('API_DOCS_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Overlay Path
    |--------------------------------------------------------------------------
    |
    | The folder holding your hand-written documentation. Intro Markdown and
    | OpenAPI YAML fragments found here are merged over the generated specs,
    | allowing you to enrich any endpoint without changing application code.
    |
    */

    'overlay_path' => base_path('docs/api'),

    /*
    |--------------------------------------------------------------------------
    | Additional Servers
    |--------------------------------------------------------------------------
    |
    | Extra environments the "Try it" console can send requests to, alongside
    | the server hosting the documentation. Define these as comma separated
    | Name=URL pairs in the API_DOCS_SERVERS environment variable, such as:
    |
    | API_DOCS_SERVERS="Dev=https://dev.example.com,UAT=https://uat.example.com"
    |
    */

    'servers' => collect(explode(',', (string) env('API_DOCS_SERVERS', '')))
        ->map(fn (string $pair) => array_map('trim', explode('=', $pair, 2)))
        ->filter(fn (array $pair) => count($pair) === 2 && filter_var($pair[1], FILTER_VALIDATE_URL))
        ->map(fn (array $pair) => ['name' => $pair[0], 'url' => rtrim($pair[1], '/')])
        ->values()
        ->all(),

    /*
    |--------------------------------------------------------------------------
    | Watched Paths
    |--------------------------------------------------------------------------
    |
    | The files and folders observed by "php artisan api-docs:generate --watch"
    | which trigger a rebuild when changed. Each entry is a glob pattern that
    | is relative to the root of your project.
    |
    */

    'watch' => [
        'app/Http',
        'routes',
        'docs/api',
        'config/api-docs.php',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Routes
    |--------------------------------------------------------------------------
    |
    | Route URIs left out of every portal. Wildcards are supported using the
    | "*" character. To leave a route out of a single portal only, use the
    | "exclude" option of that portal instead.
    |
    */

    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Error Envelope
    |--------------------------------------------------------------------------
    |
    | The key your API wraps error messages in. For example, "data" documents
    | errors as { "data": { "message": "..." } }. When this value is null,
    | Laravel's default top level { "message": "..." } format is documented.
    |
    */

    'error_envelope' => null,

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Determines how paginated endpoints are detected and documented.
    |
    | "methods" maps code found in a controller to the query parameters it
    | accepts, and the first match wins. List any custom pagination helpers
    | used by your application above the defaults.
    |
    | "size_parameter" is also documented when a controller reads that input
    | by name, and "parameters" defines the schema of each query parameter.
    |
    */

    'pagination' => [
        'methods' => [
            'simplePaginate(' => ['page'],
            'cursorPaginate(' => ['page'],
            '->paginate('     => ['page'],
        ],

        'size_parameter' => 'per_page',

        'parameters' => [
            'page'     => ['schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'description' => 'Page number.'],
            'per_page' => ['schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 15], 'description' => 'Items per page.'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Portals
    |--------------------------------------------------------------------------
    |
    | Each portal documents the API of one audience, and the documentation
    | page offers a switcher between them. The following options are required:
    |
    | title        The name of the portal.
    | prefixes     The route URI prefixes that belong to this portal.
    | guard        The authentication guard protecting these routes.
    | controllers  The namespace, or list of namespaces, that the sidebar
    |              groups are derived from.
    | login        The URI of the login route, used to capture the token.
    | logout       The URI of the logout route, used to clear the token.
    |
    | The following options may also be used where they are needed:
    |
    | token_path     The location of the token in the login response, using
    |                "dot" notation. Defaults to "data.token".
    | bearer_format  The token format shown in the docs, such as "JWT".
    | exclude        Route URIs left out of this portal only.
    | api_key        Documents routes that authenticate using a header key
    |                instead of a token. Accepts "scheme", "header" and
    |                "description", and is applied to the routes matching
    |                its "middleware" and "guards" lists.
    |
    */

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
