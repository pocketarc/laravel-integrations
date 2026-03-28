<?php

declare(strict_types=1);

namespace Integrations;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Integrations\Console\HealthCommand;
use Integrations\Console\ListCommand;
use Integrations\Console\PruneCommand;
use Integrations\Console\ReplayWebhookCommand;
use Integrations\Console\SyncCommand;
use Integrations\Console\TestCommand;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Http\OAuthController;
use Integrations\Http\WebhookController;
use Integrations\Support\Config;
use InvalidArgumentException;

class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/integrations.php', 'integrations');

        $this->app->singleton(IntegrationManager::class, function (): IntegrationManager {
            $manager = new IntegrationManager($this->app);

            foreach (Config::providers() as $key => $class) {
                if (! class_exists($class)) {
                    throw new InvalidArgumentException("Integration provider class '{$class}' for key '{$key}' does not exist.");
                }

                if (! is_subclass_of($class, IntegrationProvider::class)) {
                    throw new InvalidArgumentException("Integration provider class '{$class}' for key '{$key}' must implement ".IntegrationProvider::class.'.');
                }

                $manager->register($key, $class);
            }

            return $manager;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/integrations.php' => config_path('integrations.php'),
            ], 'integrations-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'integrations-migrations');

            $this->publishes([
                __DIR__.'/../stubs/Listeners/SendHealthNotification.php' => app_path('Listeners/SendHealthNotification.php'),
                __DIR__.'/../stubs/Notifications/IntegrationHealthStatusNotification.php' => app_path('Notifications/IntegrationHealthStatusNotification.php'),
            ], 'integrations-notifications');

            $this->commands([
                SyncCommand::class,
                ListCommand::class,
                HealthCommand::class,
                TestCommand::class,
                PruneCommand::class,
                ReplayWebhookCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        Route::middleware(Config::webhookMiddleware())
            ->prefix(Config::webhookPrefix())
            ->group(function (): void {
                Route::match(['get', 'post'], '{provider}/webhook', [WebhookController::class, 'handle'])
                    ->name('integrations.webhook');
                Route::match(['get', 'post'], '{provider}/{id}/webhook', [WebhookController::class, 'handleForIntegration'])
                    ->where('id', '[0-9]+')
                    ->name('integrations.webhook.specific');
            });

        Route::middleware(Config::oauthMiddleware())
            ->prefix(Config::oauthRoutePrefix())
            ->group(function (): void {
                Route::get('{integration}/oauth/authorize', [OAuthController::class, 'authorize'])
                    ->where('integration', '[0-9]+')
                    ->name('integrations.oauth.authorize');
                Route::post('{integration}/oauth/revoke', [OAuthController::class, 'revoke'])
                    ->where('integration', '[0-9]+')
                    ->name('integrations.oauth.revoke');
            });

        Route::middleware(Config::oauthCallbackMiddleware())
            ->prefix(Config::oauthRoutePrefix())
            ->group(function (): void {
                Route::get('oauth/callback', [OAuthController::class, 'callback'])
                    ->name('integrations.oauth.callback');
            });
    }
}
