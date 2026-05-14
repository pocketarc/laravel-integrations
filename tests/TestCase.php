<?php

declare(strict_types=1);

namespace Integrations\Tests;

use Integrations\IntegrationsServiceProvider;
use Integrations\Testing\IntegrationRequestFake;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            IntegrationsServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // Brings in the framework's jobs / job_batches / failed_jobs tables.
        // The sync flow dispatches a Bus batch, which needs job_batches.
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Bus::batch and the failed-jobs store otherwise resolve their own
        // connection, which under Testbench points at a non-existent file.
        $app['config']->set('queue.batching.database', 'testing');
        $app['config']->set('queue.failed.database', 'testing');

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    protected function tearDown(): void
    {
        IntegrationRequestFake::deactivate();
        parent::tearDown();
    }
}
