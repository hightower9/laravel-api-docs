<?php

namespace Souqbox\ApiDocs\Tests\Unit;

use Souqbox\ApiDocs\SourceInspector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers what is read from controller source code.
 */
class SourceInspectorTest extends TestCase
{
    /**
     * Pagination code to look for, and the parameters it accepts.
     */
    private const METHODS = ['conditionalPaginate(' => ['page', 'paginate', 'is_paginate']];

    /**
     * Filters, sorts, includes and pagination are found in same-class helpers too.
     */
    public function test_it_reads_query_builder_calls_from_helpers(): void
    {
        $source = new SourceInspector(new ReflectionMethod(SourceInspectorFixtureController::class, 'index'));

        $this->assertSame(['search', 'status', 'type'], $source->filters());
        $this->assertSame(['name', 'created_at'], $source->sorts());
        $this->assertSame(['contacts'], $source->includes());
        $this->assertSame('name', $source->defaultSort());
        $this->assertSame(['page', 'paginate', 'is_paginate'], $source->pagination(self::METHODS));
        $this->assertSame(['year', 'search'], $source->inputs());
    }

    /**
     * Inputs read straight from request() are found.
     */
    public function test_it_reads_request_inputs_without_query_builder(): void
    {
        $source = new SourceInspector(new ReflectionMethod(SourceInspectorFixtureController::class, 'login'));

        $this->assertSame(['email', 'password'], $source->inputs());
        $this->assertSame([], $source->pagination(self::METHODS));
    }
}

/**
 * Controller fixture whose source is read by the tests above.
 */
class SourceInspectorFixtureController
{
    /**
     * Fixture: a list endpoint that builds its query in a helper.
     */
    public function index(): mixed
    {
        $year = request('year');

        return $this->query()->where('search', request()->search);
    }

    /**
     * Fixture: an endpoint that reads inputs without a FormRequest.
     */
    public function login(): array
    {
        return [request()['email'], request()['password']];
    }

    /**
     * Fixture: the query-builder helper used by index().
     */
    private function query(): mixed
    {
        return QueryBuilder::for(Model::class)
            ->defaultSort('name')
            ->allowedFilters([
                'search',
                AllowedFilter::exact('status'),
                AllowedFilter::custom('type', new TypeFilter(['a', 'b'])),
            ])
            ->allowedSorts('name', AllowedSort::field('created_at', 'created_at'))
            ->allowedIncludes(['contacts'])
            ->conditionalPaginate();
    }
}
