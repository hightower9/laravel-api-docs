<?php

namespace Hightower9\ApiDocs\Tests\Unit;

use Hightower9\ApiDocs\RulesToSchema;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\TestCase;

/**
 * Covers the conversion of Laravel validation rules into JSON Schema.
 */
class RulesToSchemaTest extends TestCase
{
    /**
     * Types, formats, limits, enums and notes of single-value fields.
     */
    public function test_it_converts_scalar_rules(): void
    {
        $result = (new RulesToSchema())->convert([
            'name'     => 'required|string|max:255',
            'email'    => ['nullable', 'email'],
            'quantity' => ['required', 'integer', 'min:1'],
            'status'   => ['required', Rule::in(['draft', 'live'])],
            'date'     => ['nullable', 'date_format:d/m/Y'],
            'staff_id' => ['exists:staff,id'],
        ]);

        $schema = $result['schema'];

        $this->assertFalse($result['multipart']);
        $this->assertSame(['name', 'quantity', 'status'], $schema['required']);
        $this->assertSame(['type' => 'string', 'maxLength' => 255], $schema['properties']['name']);
        $this->assertSame(['string', 'null'], $schema['properties']['email']['type']);
        $this->assertSame('email', $schema['properties']['email']['format']);
        $this->assertSame(1, $schema['properties']['quantity']['minimum']);
        $this->assertSame(['draft', 'live'], $schema['properties']['status']['enum']);
        $this->assertSame('Format: `d/m/Y`.', $schema['properties']['date']['description']);
        $this->assertSame('Must exist in `staff`.', $schema['properties']['staff_id']['description']);
    }

    /**
     * Wildcard, dotted and positional keys become arrays and objects.
     */
    public function test_it_builds_nested_arrays_and_objects(): void
    {
        $schema = (new RulesToSchema())->convert([
            'items'            => ['required', 'array'],
            'items.*.id'       => ['required', 'integer'],
            'items.*.note'     => ['nullable', 'string'],
            'address'          => ['array'],
            'address.city'     => ['string'],
            'positions'        => ['array'],
            'positions.0.name' => ['in:corn'],
        ])['schema'];

        $items = $schema['properties']['items'];
        $this->assertSame('array', $items['type']);
        $this->assertSame('object', $items['items']['type']);
        $this->assertSame(['id'], $items['items']['required']);

        $this->assertSame('object', $schema['properties']['address']['type']);
        $this->assertSame('string', $schema['properties']['address']['properties']['city']['type']);

        $this->assertSame('array', $schema['properties']['positions']['type']);
        $this->assertArrayHasKey('name', $schema['properties']['positions']['items']['properties']);
    }

    /**
     * A file rule makes the body multipart/form-data.
     */
    public function test_file_rules_switch_to_multipart(): void
    {
        $result = (new RulesToSchema())->convert(['document' => ['required', 'file', 'mimes:pdf,png']]);

        $this->assertTrue($result['multipart']);
        $this->assertSame('binary', $result['schema']['properties']['document']['format']);
    }

    /**
     * A confirmed rule adds the matching *_confirmation field.
     */
    public function test_confirmed_adds_confirmation_field(): void
    {
        $schema = (new RulesToSchema())->convert(['password' => ['required', 'confirmed']])['schema'];

        $this->assertArrayHasKey('password_confirmation', $schema['properties']);
    }

    /**
     * Top-level properties become query parameters; arrays get the [] suffix.
     */
    public function test_it_turns_properties_into_query_parameters(): void
    {
        $converter = new RulesToSchema();
        $parameters = $converter->toQueryParameters($converter->convert([
            'year' => ['required', 'integer'],
            'ids'  => ['array'],
            'q'    => ['nullable'],
        ])['schema']);

        $this->assertSame(['year', 'ids[]', 'q'], array_column($parameters, 'name'));
        $this->assertTrue($parameters[0]['required']);
        $this->assertSame(['type' => 'string'], $parameters[2]['schema']);
    }
}
