<?php

namespace Souqbox\ApiDocs;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationRuleParser;
use ReflectionProperty;
use stdClass;
use Stringable;
use UnitEnum;

/**
 * Converts Laravel validation rules into JSON Schema.
 */
class RulesToSchema
{
    /**
     * Rules that set a field's type.
     */
    private const TYPES = [
        'Integer'    => 'integer',
        'Numeric'    => 'number',
        'Decimal'    => 'number',
        'Boolean'    => 'boolean',
        'Accepted'   => 'boolean',
        'Declined'   => 'boolean',
        'Array'      => 'array',
        'List'       => 'array',
        'String'     => 'string',
        'Json'       => 'string',
        'File'       => 'file',
        'Image'      => 'file',
        'Mimes'      => 'file',
        'Mimetypes'  => 'file',
        'Extensions' => 'file',
    ];

    /**
     * Rules that set a string format.
     */
    private const FORMATS = [
        'Email' => 'email',
        'Uuid'  => 'uuid',
        'Url'   => 'uri',
        'Date'  => 'date',
        'Ipv4'  => 'ipv4',
        'Ipv6'  => 'ipv6',
    ];

    /**
     * Whether the rules being converted contain a file upload.
     */
    private bool $hasFiles = false;

    /**
     * Convert FormRequest rules into a schema, and whether the body must be multipart.
     */
    public function convert(array $rules): array
    {
        $this->hasFiles = false;
        $tree = [];

        // Build a tree of fields from the dotted keys, then convert it bottom-up.
        foreach ($rules as $key => $fieldRules) {
            $node = &$tree;
            foreach (explode('.', (string) $key) as $segment) {
                $node = &$node['children'][$segment];
            }
            $node['rules'] = $this->parse($fieldRules);
            unset($node);
        }

        $schema = $this->schemaFor($tree);
        $schema['type'] = 'object';

        return ['schema' => $schema, 'multipart' => $this->hasFiles];
    }

    /**
     * Convert a schema's top-level properties into query parameters.
     */
    public function toQueryParameters(array $schema): array
    {
        $parameters = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $property = (array) $property;
            $isArray = in_array('array', (array) ($property['type'] ?? []), true);

            $parameters[] = array_filter([
                'name'        => $isArray ? "{$name}[]" : $name,
                'in'          => 'query',
                'required'    => in_array($name, $schema['required'] ?? [], true),
                'description' => $property['description'] ?? null,
                'schema'      => array_diff_key($property, ['description' => true]) ?: ['type' => 'string'],
            ], fn (mixed $value) => $value !== null);
        }

        return $parameters;
    }

    /**
     * Normalise a field's rules into [name, parameters] pairs.
     */
    private function parse(mixed $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : (array) $rules;
        $parsed = [];

        foreach ($rules as $rule) {
            if ($rule instanceof Stringable && ! $rule instanceof RuleContract && ! $rule instanceof ValidationRule) {
                $rule = (string) $rule;
            }

            if (is_string($rule)) {
                $parsed[] = ValidationRuleParser::parse($rule);
            } elseif (is_object($rule) && ! $rule instanceof Closure) {
                $parsed[] = [$rule, []];
            }
        }

        return $parsed;
    }

    /**
     * Build the schema for one field and its children.
     */
    private function schemaFor(array $node): array
    {
        $rules = $node['rules'] ?? [];
        $children = $node['children'] ?? [];
        $names = array_map(fn (array $rule) => is_string($rule[0]) ? $rule[0] : null, $rules);

        $schema = [];
        $notes = [];
        $type = null;

        foreach ($rules as [$name, $params]) {
            if ($name instanceof Enum) {
                $schema['enum'] = $this->enumValues($name);
                continue;
            }

            if ($name instanceof File) {
                $type = 'file';
                continue;
            }

            if (! is_string($name)) {
                continue;
            }

            $type ??= self::TYPES[$name] ?? null;

            if (isset(self::FORMATS[$name])) {
                $type ??= 'string';
                $schema['format'] = self::FORMATS[$name];
            }

            $note = $this->note($name, $params);
            if ($note !== null) {
                $notes[] = $note;
            }

            if ($name === 'In') {
                $schema['enum'] = $params;
            }
        }

        if ($type === 'file') {
            $this->hasFiles = true;
            $schema = ['type' => 'string', 'format' => 'binary'] + $schema;
        } elseif ($type === 'array' && $children && ! isset($children['*'])) {
            // An "array" rule with named children is an associative array, i.e. an object.
            $schema['type'] = 'object';
        } elseif ($type !== null) {
            $schema['type'] = $type;
        } elseif ($children) {
            $schema['type'] = isset($children['*']) ? 'array' : 'object';
        }

        // A custom date_format (e.g. d/m/Y) contradicts the ISO "date" format.
        if (in_array('DateFormat', $names, true)) {
            unset($schema['format']);
        }

        $this->applySizes($schema, $rules, $type);

        if (isset($schema['enum']) && in_array($schema['type'] ?? null, ['integer', 'number'], true)) {
            $schema['enum'] = array_map(fn (mixed $value) => is_numeric($value) ? $value + 0 : $value, $schema['enum']);
        }

        // "items.0.name" style rules describe array positions, not object keys.
        $positions = array_filter($children, 'is_int', ARRAY_FILTER_USE_KEY);
        if ($positions) {
            $first = reset($positions);
            $children = array_diff_key($children, $positions);
            $children['*'] ??= $first;
            $children['*']['children'] ??= $first['children'] ?? null;
            $children['*'] = array_filter($children['*'], fn (mixed $value) => $value !== null);
        }

        // Empty schemas are emitted as stdClass so they encode as {} (any value), not [].
        if (isset($children['*'])) {
            $schema['type'] = 'array';
            $schema['items'] = $this->schemaFor($children['*']) ?: new stdClass();
            unset($children['*']);
        }

        $required = [];
        foreach ($children as $key => $child) {
            $schema['properties'][$key] = $this->schemaFor($child) ?: new stdClass();

            $childNames = array_map(fn (array $rule) => is_string($rule[0]) ? $rule[0] : null, $child['rules'] ?? []);
            if (in_array('Required', $childNames, true)) {
                $required[] = (string) $key;
            }
            if (in_array('Confirmed', $childNames, true)) {
                $schema['properties']["{$key}_confirmation"] = ['type' => 'string', 'description' => "Must match `{$key}`."];
            }
        }

        if ($required) {
            $schema['required'] = $required;
        }

        if (in_array('Nullable', $names, true) && isset($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        if ($notes) {
            $schema['description'] = implode(' ', $notes);
        }

        return $schema;
    }

    /**
     * Apply min, max, size and between to the matching schema keywords.
     */
    private function applySizes(array &$schema, array $rules, ?string $type): void
    {
        [$minKey, $maxKey] = match ($type) {
            'integer', 'number' => ['minimum', 'maximum'],
            'array'             => ['minItems', 'maxItems'],
            'file'              => [null, null],
            default             => ['minLength', 'maxLength'],
        };

        if ($minKey === null) {
            return;
        }

        foreach ($rules as [$name, $params]) {
            if (! is_string($name) || ! isset($params[0]) || ! is_numeric($params[0])) {
                continue;
            }

            match ($name) {
                'Min'     => $schema[$minKey] = $params[0] + 0,
                'Max'     => $schema[$maxKey] = $params[0] + 0,
                'Size'    => [$schema[$minKey], $schema[$maxKey]] = [$params[0] + 0, $params[0] + 0],
                'Between' => [$schema[$minKey], $schema[$maxKey]] = [$params[0] + 0, ($params[1] ?? $params[0]) + 0],
                default   => null,
            };
        }
    }

    /**
     * Describe rules that JSON Schema can't express, e.g. exists or date_format.
     */
    private function note(string $name, array $params): ?string
    {
        // Empty rule parameters, e.g. "not_in:" from Rule::notIn([]), parse as [null].
        $params = array_values(array_filter($params, fn (mixed $param) => $param !== null && $param !== ''));
        $list = implode(', ', array_map(fn (mixed $param) => "`{$param}`", $params));
        $first = $params[0] ?? '';

        return match ($name) {
            'Exists'        => "Must exist in `{$first}`.",
            'Unique'        => "Must be unique in `{$first}`.",
            'DateFormat'    => "Format: `{$first}`.",
            'RequiredIf'    => "Required when `{$first}` is " . implode(', ', array_slice($params, 1)) . '.',
            'RequiredUnless' => "Required unless `{$first}` is " . implode(', ', array_slice($params, 1)) . '.',
            'RequiredWith', 'RequiredWithAll' => "Required with {$list}.",
            'RequiredWithout', 'RequiredWithoutAll' => "Required without {$list}.",
            'After', 'AfterOrEqual', 'Before', 'BeforeOrEqual',
            'Gt', 'Gte', 'Lt', 'Lte', 'Same', 'Different' => Str::ucfirst(Str::lower(Str::headline($name))) . " `{$first}`.",
            'Digits'        => "Exactly {$first} digits.",
            'DigitsBetween' => "Between {$first} and " . ($params[1] ?? '') . ' digits.',
            'Mimes', 'Extensions' => "Allowed types: {$list}.",
            'Regex'         => "Pattern: `{$first}`.",
            'Json'          => 'JSON encoded string.',
            'Distinct'      => 'Values must be distinct.',
            'Prohibited'    => 'Must not be sent.',
            default         => null,
        };
    }

    /**
     * Get the allowed values of an enum rule.
     */
    private function enumValues(Enum $rule): array
    {
        $type = (new ReflectionProperty($rule, 'type'))->getValue($rule);

        if (! enum_exists($type)) {
            return [];
        }

        return array_map(
            fn (UnitEnum $case) => $case instanceof BackedEnum ? $case->value : $case->name,
            $type::cases(),
        );
    }
}
