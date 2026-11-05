<?php

namespace Souqbox\ApiDocs;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Merges the hand-written docs in docs/api (intro Markdown and YAML fragments) into a spec.
 */
class OverlayMerger
{
    /**
     * Create a new merger for the overlay folder.
     */
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Apply the intro and YAML fragments of the given portal.
     */
    public function apply(array $spec, string $portal, array $replacements = []): array
    {
        $intro = collect([$this->basePath . "/{$portal}/_intro.md", $this->basePath . '/_intro.md'])
            ->filter(fn (string $path) => File::exists($path))
            ->map(fn (string $path) => trim(File::get($path)))
            ->implode("\n\n");

        if ($intro !== '') {
            $spec['info']['description'] = strtr($intro, $replacements);
        }

        foreach ($this->fragments($portal) as $file) {
            $spec = $this->merge($spec, $this->normalize((array) Yaml::parseFile($file)));
        }

        return $spec;
    }

    /**
     * Get the YAML files of a portal, shared ones first.
     */
    private function fragments(string $portal): array
    {
        return collect(['shared', $portal])
            ->map(fn (string $dir) => $this->basePath . '/' . $dir)
            ->filter(fn (string $dir) => File::isDirectory($dir))
            ->flatMap(fn (string $dir) => collect(File::allFiles($dir))
                ->filter(fn (SplFileInfo $file) => in_array($file->getExtension(), ['yaml', 'yml'], true))
                ->map(fn (SplFileInfo $file) => $file->getPathname())
                ->sort()
                ->values())
            ->all();
    }

    /**
     * Allow overlay paths with or without a leading slash.
     */
    private function normalize(array $fragment): array
    {
        if (isset($fragment['paths'])) {
            $fragment['paths'] = collect($fragment['paths'])
                ->mapWithKeys(fn (mixed $operations, string $path) => [Str::start($path, '/') => $operations])
                ->all();
        }

        return $fragment;
    }

    /**
     * Deep-merge an overlay; lists and $ref objects are replaced.
     */
    private function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $mergeable = is_array($value) && ! array_is_list($value)
                && is_array($base[$key] ?? null) && ! isset($base[$key]['$ref']);

            $base[$key] = $mergeable ? $this->merge($base[$key], $value) : $value;
        }

        return $base;
    }
}
