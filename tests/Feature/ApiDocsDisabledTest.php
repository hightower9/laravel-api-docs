<?php

namespace Hightower9\ApiDocs\Tests\Feature;

use Hightower9\ApiDocs\Tests\TestCase;

/**
 * Covers the app when the docs are turned off before it boots.
 */
class ApiDocsDisabledTest extends TestCase
{
    /**
     * Turn the docs off.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-docs.enabled', false);
    }

    /**
     * The docs route does not exist where the docs are disabled.
     */
    public function test_docs_route_is_not_registered_when_disabled(): void
    {
        $this->get('/' . self::PATH)->assertNotFound();
    }
}
