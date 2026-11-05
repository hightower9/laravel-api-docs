<?php

namespace Souqbox\ApiDocs\Console;

use Illuminate\Console\Command;
use Souqbox\ApiDocs\ApiDocsBuilder;
use Illuminate\Support\Facades\Process;

/**
 * Builds the static API docs site served at /docs.
 */
class ApiDocsGenerateCommand extends Command
{
    /**
     * The console command signature.
     */
    protected $signature = 'api-docs:generate
        {--portal=* : Only rebuild these portals}
        {--check : Exit with an error when the built docs are out of date (for CI)}
        {--watch : Rebuild whenever routes, controllers, requests or docs files change}
        {--live : Make open docs pages reload when this build changes (set by --watch)}
        {--force : Generate even when API_DOCS_ENABLED is false}';

    /**
     * The console command description.
     */
    protected $description = 'Build the static API docs site served at /docs';

    /**
     * Build, check or watch the docs; where docs are disabled, remove any published site.
     */
    public function handle(ApiDocsBuilder $builder): int
    {
        if (! config('api-docs.enabled') && ! $this->option('force')) {
            $builder->unpublish();
            $this->components->info('API docs are disabled (API_DOCS_ENABLED=false). Nothing was generated.');

            return self::SUCCESS;
        }

        $portals = $this->portals($builder);
        if ($portals === null) {
            return self::FAILURE;
        }

        return match (true) {
            $this->option('check') => $this->check($builder, $portals),
            $this->option('watch') => $this->watch($builder, $portals),
            default                => $this->build($builder, $portals),
        };
    }

    /**
     * Build the docs and report the operation count and warnings per portal.
     */
    private function build(ApiDocsBuilder $builder, array $portals): int
    {
        $started = microtime(true);

        foreach ($builder->build($portals, (bool) $this->option('live')) as $portal => $result) {
            $this->components->twoColumnDetail("<fg=green>{$portal}</>", "{$result['operations']} operations");

            foreach ($result['warnings'] as $warning) {
                $this->components->warn("Request body skipped — {$warning}");
            }
        }

        $this->components->info(sprintf('API docs built in %.1fs → %s', microtime(true) - $started, $builder->outputPath()));

        return self::SUCCESS;
    }

    /**
     * Fail when the built specs no longer match the code.
     */
    private function check(ApiDocsBuilder $builder, array $portals): int
    {
        $outdated = array_keys(array_filter($builder->check($portals), fn (bool $fresh) => ! $fresh));

        if ($outdated === []) {
            $this->components->info('API docs are up to date.');

            return self::SUCCESS;
        }

        $this->components->error('API docs are out of date for: ' . implode(', ', $outdated) . '. Run "php artisan api-docs:generate".');

        return self::FAILURE;
    }

    /**
     * Rebuild whenever a watched file changes. Each build runs in a fresh process, because a
     * running PHP process cannot reload changed controllers or route files.
     */
    private function watch(ApiDocsBuilder $builder, array $portals): int
    {
        $this->components->info('Watching for changes. Open pages reload automatically. Press Ctrl+C to stop.');

        $command = [PHP_BINARY, 'artisan', 'api-docs:generate', '--live', ...array_map(fn (string $portal) => "--portal={$portal}", $portals)];
        $last = null;

        while (true) {
            $fingerprint = $builder->fingerprint();

            if ($fingerprint !== $last) {
                $last = $fingerprint;
                $this->line('<fg=gray>' . now()->format('H:i:s') . '</> Rebuilding…');

                Process::forever()->path(base_path())->run($command, fn (string $type, string $output) => $this->output->write($output));
            }

            usleep(750_000);
        }
    }

    /**
     * Get the requested portals ([] means all), or null after reporting an unknown one.
     */
    private function portals(ApiDocsBuilder $builder): ?array
    {
        $requested = $this->option('portal');
        $unknown = array_diff($requested, $builder->portalKeys());

        if ($unknown !== []) {
            $this->components->error('Unknown portal: ' . implode(', ', $unknown) . '. Available: ' . implode(', ', $builder->portalKeys()) . '.');

            return null;
        }

        return $requested;
    }
}
