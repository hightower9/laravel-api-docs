<?php

namespace Souqbox\ApiDocs\Tests\Fixtures;

/**
 * Controller fixture documented by the feature tests; its methods are read, never called.
 */
class DemoController
{
    /**
     * Login
     */
    public function login(DemoLoginRequest $request): array
    {
        return [];
    }

    /**
     * Logout
     */
    public function logout(): array
    {
        return [];
    }

    /**
     * List items
     */
    public function index(): mixed
    {
        return $this->query()->paginate(request('per_page'));
    }

    /**
     * Device status
     */
    public function device(): array
    {
        return [];
    }

    /**
     * Fixture: the query used by index().
     */
    private function query(): mixed
    {
        return null;
    }
}
