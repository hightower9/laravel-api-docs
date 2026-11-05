<?php

namespace Souqbox\ApiDocs;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Builds the static docs site in public/docs: index.html, manifest.json, assets and one spec per portal.
 */
class ApiDocsBuilder
{
    /**
     * The docs page assets shipped with the package.
     */
    private const ASSETS = ['css' => 'docs.css', 'js' => 'docs.js'];

    /**
     * Create a new builder.
     */
    public function __construct(private readonly OpenApiGenerator $generator)
    {
    }

    /**
     * Build the specs of the given portals (all by default) and rewrite the manifest and page.
     */
    public function build(array $portals = [], bool $live = false): array
    {
        $portals = $portals ?: $this->portalKeys();
        $manifest = $this->manifest();
        $results = [];

        File::ensureDirectoryExists($this->outputPath('specs'));
        File::ensureDirectoryExists($this->outputPath('assets'));

        foreach ($portals as $portal) {
            $spec = $this->generator->generate($portal);
            $json = $this->encode($spec);
            $operations = collect($spec['paths'])->flatten(1)->count();

            $this->write("specs/{$portal}.json", $json);
            $manifest['portals'][$portal] = ['hash' => substr(md5($json), 0, 12), 'operations' => $operations];
            $results[$portal] = ['operations' => $operations, 'warnings' => array_values(array_unique($this->generator->warnings()))];
        }

        $manifest = [
            'title'       => config('api-docs.title'),
            'version'     => (string) config('api-docs.version'),
            'commit'      => $this->commit(),
            'generatedAt' => now()->toIso8601String(),
            'live'        => $live,
            'portals'     => array_intersect_key($manifest['portals'] ?? [], array_flip($this->portalKeys())),
        ];

        $this->write('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->write('index.html', $this->renderIndex($manifest));

        return $results;
    }

    /**
     * Compare freshly generated specs with the built ones, per portal.
     */
    public function check(array $portals = []): array
    {
        return collect($portals ?: $this->portalKeys())->mapWithKeys(fn (string $portal) => [
            $portal => File::exists($path = $this->outputPath("specs/{$portal}.json"))
                && File::get($path) === $this->encode($this->generator->generate($portal)),
        ])->all();
    }

    /**
     * Determine whether the docs have been built.
     */
    public function isBuilt(): bool
    {
        return File::exists($this->outputPath('index.html'));
    }

    /**
     * Remove the published site but keep the build cache.
     */
    public function unpublish(): void
    {
        File::deleteDirectory($this->outputPath());
    }

    /**
     * Remove the published site and the build cache.
     */
    public function clean(): void
    {
        $this->unpublish();
        File::deleteDirectory(storage_path('framework/cache/api-docs'));
    }

    /**
     * Get the path of the published site, or of a file inside it.
     */
    public function outputPath(string $file = ''): string
    {
        return public_path(trim(config('api-docs.path'), '/') . ($file === '' ? '' : "/{$file}"));
    }

    /**
     * Fingerprint of the watched files, used by --watch to detect changes.
     */
    public function fingerprint(): string
    {
        $count = 0;
        $latest = 0;

        foreach (config('api-docs.watch') as $pattern) {
            foreach (File::glob(base_path($pattern)) as $path) {
                $files = is_dir($path)
                    ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
                    : [new SplFileInfo($path)];

                foreach ($files as $file) {
                    $count++;
                    $latest = max($latest, $file->getMTime());
                }
            }
        }

        return md5("{$count}|{$latest}");
    }

    /**
     * Get the configured portal keys.
     */
    public function portalKeys(): array
    {
        return array_keys(config('api-docs.portals'));
    }

    /**
     * Render the docs page with the portals, servers and hashed asset URLs.
     */
    private function renderIndex(array $manifest): string
    {
        $base = '/' . trim(config('api-docs.path'), '/');

        $portals = collect(config('api-docs.portals'))
            ->filter(fn (array $portal, string $key) => isset($manifest['portals'][$key]))
            ->map(fn (array $portal, string $key) => [
                'slug'      => $key,
                'title'     => $portal['title'],
                'url'       => "{$base}/specs/{$key}.json?v={$manifest['portals'][$key]['hash']}",
                'hash'      => $manifest['portals'][$key]['hash'],
                'scheme'    => "{$key}Auth",
                'prefixes'  => $portal['prefixes'],
                'login'     => '/' . $portal['login'],
                'logout'    => '/' . $portal['logout'],
                'tokenPath' => $portal['token_path'] ?? 'data.token',
            ])
            ->values();

        return view('api-docs::index', [
            'title'   => config('api-docs.title'),
            'favicon' => $this->favicon(),
            'assets'  => $this->publishAssets($base),
            'config'  => [
                'title'       => config('api-docs.title'),
                'portals'     => $portals,
                'servers'     => config('api-docs.servers'),
                'manifestUrl' => "{$base}/manifest.json",
                'live'        => $manifest['live'],
                'build'       => array_intersect_key($manifest, array_flip(['version', 'commit', 'generatedAt'])),
            ],
        ])->render();
    }

    /**
     * Get the favicon as a data URI: the first letter of the title, matching the sidebar mark.
     */
    private function favicon(): string
    {
        $letter = htmlspecialchars(mb_strtoupper(mb_substr((string) config('api-docs.title'), 0, 1)));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="32" height="32" rx="8" fill="#0284c7"/>'
            . '<text x="16" y="23" fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="21" font-weight="700" text-anchor="middle">'
            . $letter . '</text></svg>';

        return 'data:image/svg+xml,' . rawurlencode($svg);
    }

    /**
     * Copy the package assets into the site and return their hashed URLs, so an updated package
     * reaches the page with the next build.
     */
    private function publishAssets(string $base): array
    {
        return collect(self::ASSETS)->map(function (string $file) use ($base) {
            $contents = File::get(__DIR__ . "/../resources/assets/{$file}");
            $this->write("assets/{$file}", $contents);

            return "{$base}/assets/{$file}?v=" . substr(md5($contents), 0, 12);
        })->all();
    }

    /**
     * Get the previously written manifest.
     */
    private function manifest(): array
    {
        $path = $this->outputPath('manifest.json');

        return File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
    }

    /**
     * Get the short git commit hash, or null when git is unavailable.
     */
    private function commit(): ?string
    {
        $result = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Writes through a temporary file + rename so a page never reads a half-written file.
     */
    private function write(string $file, string $contents): void
    {
        $path = $this->outputPath($file);
        File::put("{$path}.tmp", $contents);
        rename("{$path}.tmp", $path);
    }

    /**
     * Encode a spec as JSON; fixed flags keep the output byte-identical for --check.
     */
    private function encode(array $spec): string
    {
        return json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
