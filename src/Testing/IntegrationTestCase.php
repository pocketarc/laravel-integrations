<?php

declare(strict_types=1);

namespace Integrations\Testing;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Integrations\IntegrationsServiceProvider;
use Orchestra\Testbench\TestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class IntegrationTestCase extends TestCase
{
    use CreatesIntegration;

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(
            [
                LaravelDataServiceProvider::class,
                IntegrationsServiceProvider::class,
            ],
            $this->getAdapterProviders($app),
        );
    }

    /**
     * Return additional service providers needed by the adapter under test.
     *
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getAdapterProviders($app): array
    {
        return [];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    protected function tearDown(): void
    {
        IntegrationRequestFake::deactivate();
        parent::tearDown();
    }
}
