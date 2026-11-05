<?php

namespace Souqbox\ApiDocs;

use ReflectionClass;
use ReflectionMethod;

/**
 * Reads a controller method's source to find request inputs, query-builder options, pagination and
 * downloads.
 */
class SourceInspector
{
    /**
     * Names that are not request inputs, or that are documented elsewhere.
     */
    private const IGNORED_INPUTS = [
        'headers', 'query', 'request', 'attributes', 'files', 'server', 'cookies', 'json',
        'user', 'route', 'filter', 'sort', 'include', 'page',
    ];

    /**
     * How deep $this->helper() calls are followed.
     */
    private const HELPER_DEPTH = 2;

    /**
     * Source files already read, as lines.
     */
    private static array $files = [];

    /**
     * Combined source of the method and the helpers it calls.
     */
    private string $source;

    /**
     * Create a new inspector for the controller method; "ignoredInputs" are documented elsewhere.
     */
    public function __construct(ReflectionMethod $method, private readonly array $ignoredInputs = [])
    {
        $this->source = $this->collect($method, $method->getDeclaringClass(), self::HELPER_DEPTH, []);
    }

    /**
     * Get the filter[...] names the endpoint accepts.
     */
    public function filters(): array
    {
        preg_match_all('/request\((?:\)->filter|\s*[\'"]filter[\'"]\s*\))\s*\[\s*[\'"]([\w-]+)[\'"]/', $this->source, $matches);

        return array_values(array_unique([...$this->allowed('allowedFilters'), ...$matches[1]]));
    }

    /**
     * Get the fields the endpoint can be sorted by.
     */
    public function sorts(): array
    {
        return $this->allowed('allowedSorts');
    }

    /**
     * Get the relations the endpoint can include.
     */
    public function includes(): array
    {
        return $this->allowed('allowedIncludes');
    }

    /**
     * Get the default sort, if any.
     */
    public function defaultSort(): ?string
    {
        return preg_match('/defaultSorts?\(\s*[\'"]([^\'"]+)/', $this->source, $match) ? $match[1] : null;
    }

    /**
     * Get the pagination parameters the endpoint accepts: those of the first method found in
     * its source, plus the size parameter when the source reads it by name.
     */
    public function pagination(array $methods, ?string $sizeParameter = null): array
    {
        foreach ($methods as $code => $parameters) {
            if (! str_contains($this->source, $code)) {
                continue;
            }

            $sized = $sizeParameter !== null && preg_match('/[\'"]' . preg_quote($sizeParameter, '/') . '[\'"]/', $this->source);

            return array_values(array_unique($sized ? [...$parameters, $sizeParameter] : $parameters));
        }

        return [];
    }

    /**
     * Get the media type of the file the endpoint returns, or null for JSON.
     */
    public function fileResponse(): ?string
    {
        if (! preg_match('/->(download|stream)\w*\(|Excel::download\(|response\(\)->(download|file|streamDownload)\(/', $this->source)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/pdf|Browsershot/i', $this->source) => 'application/pdf',
            (bool) preg_match('/\.csv\b/i', $this->source) => 'text/csv',
            (bool) preg_match('/\.zip\b|ZipArchive/i', $this->source) => 'application/zip',
            (bool) preg_match('/\.docx\b/i', $this->source) => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /**
     * Get the input names read directly from the request.
     */
    public function inputs(): array
    {
        $patterns = [
            '/\brequest\(\s*[\'"]([\w.]+)[\'"]/',
            '/\brequest\(\)\s*\[\s*[\'"](\w+)[\'"]/',
            '/\brequest\(\)->(\w+)\b(?!\s*\()/',
            '/\$request->(?:input|query|get|post|boolean|integer|float|string|str|date|has|filled|missing)\(\s*[\'"]([\w.]+)[\'"]/',
            '/\$request\s*\[\s*[\'"](\w+)[\'"]/',
            '/\$request->(\w+)\b(?!\s*\()/',
            '/\brequest\(\)->(?:input|query|get|boolean|integer|has|filled)\(\s*[\'"]([\w.]+)[\'"]/',
        ];

        $names = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $this->source, $matches);
            $names = array_merge($names, $matches[1]);
        }

        return array_values(array_diff(array_unique($names), self::IGNORED_INPUTS, $this->ignoredInputs));
    }

    /**
     * Collect the source of the method and the same-class helpers it calls.
     */
    private function collect(ReflectionMethod $method, ReflectionClass $class, int $depth, array $seen): string
    {
        $source = $this->methodSource($method);

        if ($depth === 0) {
            return $source;
        }

        $seen[] = $method->getName();
        preg_match_all('/\$this->(\w+)\(/', $source, $matches);

        foreach (array_diff(array_unique($matches[1]), $seen) as $helper) {
            if ($class->hasMethod($helper) && $class->getMethod($helper)->getFileName() === $class->getFileName()) {
                $source .= "\n" . $this->collect($class->getMethod($helper), $class, $depth - 1, $seen);
            }
        }

        return $source;
    }

    /**
     * Get the source code of a method.
     */
    private function methodSource(ReflectionMethod $method): string
    {
        $file = $method->getFileName();

        if (! $file) {
            return '';
        }

        $lines = self::$files[$file] ??= file($file);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /**
     * Get the names passed to an allowedFilters / allowedSorts / allowedIncludes call.
     */
    private function allowed(string $call): array
    {
        $names = [];
        $offset = 0;

        while (($position = strpos($this->source, "{$call}(", $offset)) !== false) {
            $arguments = $this->balanced($this->source, $position + strlen($call));
            $offset = $position + strlen($call) + strlen($arguments);

            foreach ($this->splitTopLevel(trim($arguments, '()[] ')) as $argument) {
                $argument = trim($argument, " \t\r\n[]");

                if (preg_match('/^(?:Allowed\w+::\w+\(\s*)?[\'"]([^\'"]+)[\'"]/', $argument, $match)) {
                    $names[] = $match[1];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Get the bracketed expression that starts at the given offset.
     */
    private function balanced(string $source, int $start): string
    {
        $depth = 0;

        for ($i = $start, $length = strlen($source); $i < $length; $i++) {
            $depth += match ($source[$i]) {
                '(', '[' => 1,
                ')', ']' => -1,
                default  => 0,
            };

            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }

        return substr($source, $start);
    }

    /**
     * Split arguments on commas that are not inside brackets.
     */
    private function splitTopLevel(string $arguments): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($arguments) as $char) {
            $depth += match ($char) {
                '(', '[' => 1,
                ')', ']' => -1,
                default  => 0,
            };

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        return [...$parts, $current];
    }
}
