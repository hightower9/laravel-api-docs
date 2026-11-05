<?php

namespace Hightower9\ApiDocs\Console;

use Illuminate\Console\Command;
use Hightower9\ApiDocs\ApiDocsBuilder;

/**
 * Deletes the built API docs and their build cache.
 */
class ApiDocsClearCommand extends Command
{
    /**
     * The console command signature.
     */
    protected $signature = 'api-docs:clear';

    /**
     * The console command description.
     */
    protected $description = 'Delete the built API docs and their build cache';

    /**
     * Delete the published site and the build cache, forcing a full rebuild next time.
     */
    public function handle(ApiDocsBuilder $builder): int
    {
        $builder->clean();

        $this->components->info('API docs and build cache deleted.');

        return self::SUCCESS;
    }
}
