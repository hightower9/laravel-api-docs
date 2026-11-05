<?php

namespace Souqbox\ApiDocs\Tests\Feature;

use Illuminate\Support\Facades\File;
use Souqbox\ApiDocs\ApiDocsBuilder;
use Souqbox\ApiDocs\Http\ApiDocsController;
use Souqbox\ApiDocs\Tests\TestCase;

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
