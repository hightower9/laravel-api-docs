# Laravel API Docs

Generates a static API docs site from your Laravel routes, controllers and FormRequests, with no annotations. `php artisan api-docs:generate` writes the site to `public/docs/`, and the web server serves it directly, so opening the docs never boots Laravel.

Requires PHP 8.2+ and Laravel 12.

## Adding the docs to a new project

Follow these steps in the root of the Laravel project.

### 1. Install the package

```bash
composer require hightower9/laravel-api-docs
```

Laravel discovers the service provider automatically, so there is nothing to register.

### 2. Publish the config and starter intro

```bash
php artisan vendor:publish --tag=api-docs-config
php artisan vendor:publish --tag=api-docs-overlays
```

This creates `config/api-docs.php` and `docs/api/_intro.md`. Both belong to the project, so commit them.

### 3. Update `.gitignore`

```gitignore
/public/docs
```

### 4. Describe your API in `config/api-docs.php`

Add one portal per API audience. Most projects need only one:

```php
'portals' => [
    'api' => [
        'title'       => 'API',
        'prefixes'    => ['api/v1'],                // routes that belong to this portal
        'guard'       => 'sanctum',
        'controllers' => 'App\Http\Controllers',    // sidebar groups come from folders under this
        'login'       => 'api/v1/login',
        'logout'      => 'api/v1/logout',
        'token_path'  => 'data.token',              // where the login response holds the token
    ],
],
```

Check `token_path` against a real login response, or the "Try it" console won't save the token. For example, a login that returns `{ "data": { "access_token": "…" } }` needs `data.access_token`.

If one portal's prefix sits inside another's, such as `api/v1` and `api/v1/admin`, give the outer portal its own `exclude` so routes don't appear twice:

```php
'tenant' => [
    'prefixes' => ['api/v1'],
    'exclude'  => ['api/v1/admin', 'api/v1/admin/*'],
    // ...
],
```

The top-level `exclude` applies to every portal, so don't use it for this. If the project has its own pagination helpers, list them under `pagination`.

### 5. Build and check it

```bash
php artisan api-docs:generate
```

Open `/docs` and confirm that the endpoints are listed, you can log in through **Try it**, and a request succeeds. The command lists any FormRequest it couldn't read. While working on the docs, `php artisan api-docs:generate --watch` rebuilds on save.

### 6. Add it to the deploy

The deploy must build the docs after installing dependencies:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
php artisan api-docs:generate --no-interaction
```

- **Separate web server container:** `api-docs:generate` writes to the app container's `public/docs`. If the web server gets `public/` from somewhere else, such as a host folder, mount `public/docs` into both containers, or the page loads but its specs and assets return 404.
- `www-data` (or whichever user runs the command) must be able to write to `public/docs`.

Set these in each environment's `.env`:

```dotenv
API_DOCS_ENABLED=true
API_DOCS_SERVERS="Dev=https://dev-api.example.com,UAT=https://uat-api.example.com"
```

Docs are on everywhere except production by default. In production, the command builds nothing and deletes any previous build, so the docs can't leak by accident. Set `API_DOCS_ENABLED=true` only if a production environment should have them.

If the app sets a Content Security Policy, allow the `API_DOCS_SERVERS` origins in `connect-src`. Calling another server from the docs page also needs CORS to allow it.

The usual Laravel nginx config already serves `public/docs`. The `/docs` Laravel route is only a fallback.

## What gets documented

| Source | Becomes |
| --- | --- |
| Routes under a portal's `prefixes` | Endpoints in that portal |
| Controller folder under the portal's `controllers` namespace | Sidebar group and nested folders, to any depth |
| First line of the controller method's PHPDoc | Endpoint summary (otherwise derived from the method name) |
| `auth:<guard>` middleware | Lock icon and Bearer token |
| `permission:a\|b` middleware | "Permissions" note |
| FormRequest `rules()` | Request body (or query parameters on `GET`) |
| `request('x')`, `$request->input('x')` in the controller | Query parameters or body fields when there is no FormRequest |
| `allowedFilters/allowedSorts/allowedIncludes` | `filter[...]`, `sort`, `include` |
| Code listed in `pagination.methods` | Pagination parameters |

## Configuration

`config/api-docs.php` holds everything specific to a project.

| Key | Purpose |
| --- | --- |
| `title`, `version`, `path` | Page title, spec version, and URL / output folder |
| `overlay_path` | Folder of hand-written docs, `docs/api` by default |
| `servers` | Extra "Try it" targets, from `API_DOCS_SERVERS="Dev=https://dev.example.com,UAT=https://uat.example.com"` |
| `watch` | Files `--watch` observes |
| `exclude` | Route URIs to leave out (`Str::is` patterns) |
| `error_envelope` | The key error messages are wrapped in, such as `data`. Leave it `null` for Laravel's `{ "message": "…" }`. |
| `pagination` | Which code marks an endpoint as paginated, and the parameters that documents |
| `portals` | One entry per API audience |

Each portal needs `title`, `prefixes`, `guard`, `controllers`, `login` and `logout`. `controllers` can be a list if the portal spans several namespaces. These are optional:

| Key | Purpose |
| --- | --- |
| `token_path` | Where the login response holds the token. Default `data.token`. |
| `bearer_format` | Shown on the token scheme, e.g. `JWT`. |
| `exclude` | Route URIs left out of this portal only (`Str::is` patterns). |
| `api_key` | Routes that use a header key instead of a token: `scheme`, `header`, `description`, `middleware`, `guards`. |

## Enriching the docs

Everything in `docs/api` is optional and is merged over the generated spec:

```
docs/api/
  _intro.md            shared intro
  shared/*.yaml        fragments applied to every portal
  <portal>/_intro.md   shown above the shared intro
  <portal>/**/*.yaml   fragments for that portal
```

A fragment is plain OpenAPI 3.1. Files are applied in alphabetical order. Maps are deep-merged and lists replace the generated value. `_intro.md` files can use `{{title}}`, `{{login}}` and `{{logout}}`.

```yaml
paths:
  /api/orders:
    get:
      summary: List orders
      description: Returns the orders visible to the current user.
```

Override `tags` (and `x-group` for the group heading) to move an endpoint in the sidebar. Separate levels with ` / ` to nest it, e.g. `Forecast / Monthly`.

## Commands

| Command | What it does |
| --- | --- |
| `php artisan api-docs:generate` | Builds `public/docs/`. Also runs as part of `php artisan optimize`. |
| `… --watch` | Rebuilds on save. Open pages reload without losing your place. |
| `… --portal=<name>` | Rebuilds only that portal (repeatable). |
| `… --check` | Exits non-zero when the built specs differ from the code, for CI. |
| `php artisan api-docs:clear` | Deletes the build and its cache. |

## Developing this package

```bash
composer install
vendor/bin/phpunit
```

To try a change in a project before releasing it, point the project at your local copy, and don't commit that change:

```bash
composer config repositories.laravel-api-docs path ../laravel-api-docs
composer update hightower9/laravel-api-docs
```

## Releasing a fix

1. Fix it here, and tag a release: `v1.0.1` for a fix, `v1.1.0` for a feature, `v2.0.0` for a breaking change.
2. In each project, run `composer update hightower9/laravel-api-docs` and commit `composer.lock`.
3. Deploy. `api-docs:generate` rebuilds the specs and assets.

Projects only change when their `composer.lock` does, so a release never reaches a project until someone updates it.

## License

MIT. See [LICENSE](LICENSE).
