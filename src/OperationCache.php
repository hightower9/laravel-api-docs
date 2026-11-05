<?php

namespace Souqbox\ApiDocs;

use Illuminate\Support\Facades\File;
use stdClass;

/**
 * Caches generated operations per route; an entry is reused while its source files are unchanged.
 * Stored with serialize() rather than JSON so empty schemas stay {}.
 */
class OperationCache
{
    /**
     * Entries loaded from disk.
     */
    private array $entries = [];

    /**
     * Entries used in this run; only these are saved.
     */
    private array $used = [];

    /**
     * Load the cache, ignoring it if it was written with a different salt.
     */
    public function __construct(private readonly string $path, private readonly string $salt)
    {
        if (! File::exists($path)) {
            return;
        }

        $data = @unserialize(File::get($path), ['allowed_classes' => [stdClass::class]]);

        if (is_array($data) && ($data['salt'] ?? null) === $salt) {
            $this->entries = $data['entries'];
        }
    }

    /**
     * Get a route's cached entry, or null when it is missing or out of date.
     */
    public function get(string $key): ?array
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null || ! $this->fresh($entry['dependencies'])) {
            return null;
        }

        return $this->used[$key] = $entry;
    }

    /**
     * Store an entry and return it.
     */
    public function put(string $key, array $entry): array
    {
        return $this->used[$key] = $entry;
    }

    /**
     * Save the entries used in this run, dropping routes that no longer exist.
     */
    public function save(): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, serialize(['salt' => $this->salt, 'entries' => $this->used]));
    }

    /**
     * Determine whether the dependencies are unchanged.
     */
    private function fresh(array $dependencies): bool
    {
        foreach ($dependencies as $file => $mtime) {
            if (@filemtime($file) !== $mtime) {
                return false;
            }
        }

        return true;
    }
}
