<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Contracts\HasHealthCheck;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;

class TestCommand extends Command
{
    protected $signature = 'integrations:test';

    protected $description = 'Run health checks on all integrations that support it.';

    public function handle(IntegrationManager $manager): int
    {
        $integrations = Integration::active()->get();
        $tested = 0;
        $passed = 0;
        $failed = 0;

        foreach ($integrations as $integration) {
            if (! $manager->has($integration->provider)) {
                continue;
            }

            $provider = $manager->provider($integration->provider);

            if (! $provider instanceof HasHealthCheck) {
                continue;
            }

            $tested++;

            try {
                $healthy = $provider->healthCheck($integration);

                if ($healthy) {
                    $this->info("  [PASS] {$integration->name} ({$integration->provider})");
                    $integration->recordSuccess();
                    $passed++;
                } else {
                    $this->error("  [FAIL] {$integration->name} ({$integration->provider})");
                    $integration->recordFailure();
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("  [FAIL] {$integration->name} ({$integration->provider}): {$e->getMessage()}");
                $integration->recordFailure();
                $failed++;
            }
        }

        if ($tested === 0) {
            $this->info('No integrations with health check support found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Tested: {$tested}, Passed: {$passed}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
