<?php

namespace Hightower9\ApiDocs\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Hightower9\ApiDocs\ApiDocsBuilder;
use Hightower9\ApiDocs\Http\ApiDocsController;
use Hightower9\ApiDocs\Tests\Fixtures\Sales\Forecast\Monthly\ReportController;
use Hightower9\ApiDocs\Tests\TestCase;

/**
 * Covers building, checking and serving the static API docs site.
 */
class ApiDocsTest extends TestCase
{
    /**
     * Start from an empty output folder and build cache.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(ApiDocsBuilder::class)->clean();
    }

    /**
     * Remove the test build.
     */
    protected function tearDown(): void
    {
        app(ApiDocsBuilder::class)->clean();

        parent::tearDown();
    }

    /**
     * Read a built spec.
     */
    private function spec(): array
    {
        return json_decode(File::get(public_path(self::PATH . '/specs/demo.json')), true);
    }

    /**
     * A build writes the page, the manifest and a spec generated from routes and overlays.
     */
    public function test_build_writes_a_static_site(): void
    {
        $results = app(ApiDocsBuilder::class)->build(['demo']);

        $this->assertSame(4, $results['demo']['operations']);
        $this->assertFileExists(public_path(self::PATH . '/index.html'));

        $manifest = json_decode(File::get(public_path(self::PATH . '/manifest.json')), true);
        $this->assertSame(['demo'], array_keys($manifest['portals']));
        $this->assertFalse($manifest['live']);

        $spec = $this->spec();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertStringContainsString('POST /api/demo/login', $spec['info']['description']);

        $login = $spec['paths']['/api/demo/login']['post'];
        $this->assertSame([], $login['security']);
        $this->assertSame('Login', $login['summary']);
        $this->assertSame(['email', 'password'], $login['requestBody']['content']['application/json']['schema']['required']);

        $items = $spec['paths']['/api/demo/items']['get'];
        $this->assertSame([['demoAuth' => []]], $items['security']);
        $this->assertArrayHasKey('401', $items['responses']);
        $this->assertSame('Overlay description.', $items['description']);
    }

    /**
     * Pagination parameters come from the config.
     */
    public function test_pagination_parameters_come_from_the_config(): void
    {
        app(ApiDocsBuilder::class)->build(['demo']);

        $parameters = array_column($this->spec()['paths']['/api/demo/items']['get']['parameters'], 'name');

        $this->assertSame(['page', 'per_page'], $parameters);
    }

    /**
     * Error messages are top-level by default, and wrapped when an envelope is set.
     */
    public function test_error_envelope_wraps_error_messages(): void
    {
        $schema = fn () => $this->spec()['components']['responses']['Forbidden']['content']['application/json']['schema'];

        app(ApiDocsBuilder::class)->build(['demo']);
        $this->assertArrayHasKey('message', $schema()['properties']);

        config(['api-docs.error_envelope' => 'data']);

        app(ApiDocsBuilder::class)->build(['demo']);
        $this->assertArrayHasKey('message', $schema()['properties']['data']['properties']);
    }

    /**
     * Sidebar groups are derived from whichever listed namespace the controller belongs to.
     */
    public function test_controllers_can_list_several_namespaces(): void
    {
        config(['api-docs.portals.demo.controllers' => ['Some\Other\Controllers', 'Hightower9\ApiDocs\Tests\Fixtures']]);

        app(ApiDocsBuilder::class)->build(['demo']);

        $groups = array_column($this->spec()['x-tagGroups'], 'name');

        $this->assertContains('General', $groups);
        $this->assertNotContains('Hightower9', $groups);
    }

    /**
     * Every folder level stays in the tag, joined by " / " for the docs page to nest.
     */
    public function test_nested_controller_folders_keep_every_level_in_the_tag(): void
    {
        Route::get('api/demo/reports', [ReportController::class, 'index']);

        app(ApiDocsBuilder::class)->build(['demo']);

        $sales = collect($this->spec()['x-tagGroups'])->firstWhere('name', 'Sales');

        $this->assertSame(['Forecast / Monthly'], $sales['tags']);
    }

    /**
     * A portal's exclude leaves routes out of that portal only.
     */
    public function test_portal_exclude_leaves_routes_out_of_that_portal(): void
    {
        config([
            'api-docs.portals.demo.exclude' => ['api/demo/device'],
            'api-docs.portals.other'        => ['prefixes' => ['api/demo/device']] + config('api-docs.portals.demo'),
        ]);
        config(['api-docs.portals.other.exclude' => []]);

        $results = app(ApiDocsBuilder::class)->build(['demo', 'other']);

        $this->assertSame(3, $results['demo']['operations']);
        $this->assertArrayNotHasKey('/api/demo/device', $this->spec()['paths']);
        $this->assertSame(1, $results['other']['operations']);
    }

    /**
     * A portal's api_key adds the scheme and applies it to the routes it matches.
     */
    public function test_api_key_routes_use_the_configured_scheme(): void
    {
        app(ApiDocsBuilder::class)->build(['demo']);

        $spec = $this->spec();

        $this->assertSame('X-DEVICE-KEY', $spec['components']['securitySchemes']['deviceKey']['name']);
        $this->assertSame([['deviceKey' => []]], $spec['paths']['/api/demo/device']['get']['security']);
    }

    /**
     * The token scheme stays generic unless the portal sets a format, and names the token path.
     */
    public function test_token_scheme_is_generic_by_default(): void
    {
        app(ApiDocsBuilder::class)->build(['demo']);

        $scheme = $this->spec()['components']['securitySchemes']['demoAuth'];

        $this->assertArrayNotHasKey('bearerFormat', $scheme);
        $this->assertStringContainsString('`token`', $scheme['description']);
    }

    /**
     * The page links hashed assets copied from the package, the spec, and the token path.
     */
    public function test_index_page_references_hashed_assets_and_the_token_path(): void
    {
        app(ApiDocsBuilder::class)->build(['demo']);

        $hash = json_decode(File::get(public_path(self::PATH . '/manifest.json')), true)['portals']['demo']['hash'];
        $html = File::get(public_path(self::PATH . '/index.html'));

        $this->assertStringContainsString(json_encode('/' . self::PATH . "/specs/demo.json?v={$hash}"), $html);
        $this->assertMatchesRegularExpression('#/' . self::PATH . '/assets/docs\.js\?v=[0-9a-f]{12}#', $html);
        $this->assertStringContainsString('"tokenPath":"token"', $html);
        $this->assertFileEquals(__DIR__ . '/../../resources/assets/docs.js', public_path(self::PATH . '/assets/docs.js'));
        $this->assertFileEquals(__DIR__ . '/../../resources/assets/docs.css', public_path(self::PATH . '/assets/docs.css'));
    }

    /**
     * The favicon shows the first letter of the title.
     */
    public function test_index_page_has_a_favicon_with_the_title_initial(): void
    {
        config(['api-docs.title' => 'nimble API']);

        app(ApiDocsBuilder::class)->build(['demo']);

        preg_match('/<link rel="icon" type="image\/svg\+xml" href="data:image\/svg\+xml,([^"]+)">/', File::get(public_path(self::PATH . '/index.html')), $match);

        $this->assertNotEmpty($match);
        $this->assertStringContainsString('>N</text>', rawurldecode($match[1]));
    }

    /**
     * --check passes for a fresh build and fails once a spec differs.
     */
    public function test_check_detects_out_of_date_docs(): void
    {
        $builder = app(ApiDocsBuilder::class);
        $builder->build(['demo']);

        $this->assertSame(['demo' => true], $builder->check(['demo']));

        File::put(public_path(self::PATH . '/specs/demo.json'), '{}');

        $this->assertSame(['demo' => false], $builder->check(['demo']));
    }

    /**
     * The /docs fallback serves the built page, or build instructions.
     */
    public function test_docs_route_serves_the_built_page_or_explains_how_to_build_it(): void
    {
        $controller = app(ApiDocsController::class);

        $missing = $controller(app(ApiDocsBuilder::class));
        $this->assertSame(503, $missing->getStatusCode());
        $this->assertStringContainsString('api-docs:generate', $missing->getContent());

        app(ApiDocsBuilder::class)->build(['demo']);

        $built = $controller(app(ApiDocsBuilder::class));
        $this->assertSame(200, $built->getStatusCode());
        $this->assertSame(File::get(public_path(self::PATH . '/index.html')), $built->getContent());
    }

    /**
     * Nothing is generated where the docs are disabled.
     */
    public function test_generate_command_builds_nothing_when_disabled(): void
    {
        config(['api-docs.enabled' => false]);

        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->assertDirectoryDoesNotExist(public_path(self::PATH));
    }

    /**
     * An unknown --portal fails the command.
     */
    public function test_generate_command_rejects_unknown_portals(): void
    {
        $this->artisan('api-docs:generate', ['--portal' => ['nope']])->assertFailed();
    }
}
