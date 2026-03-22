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

class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/integrations.php', 'integrations');

        $this->app->singleton(IntegrationManager::class, function (): IntegrationManager {
            $manager = new IntegrationManager($this->app);

            /** @var array<string, class-string> $providers */
            $providers = config('integrations.providers', []);

            foreach ($providers as $key => $class) {
                /** @var class-string<IntegrationProvider> $class */
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
        /** @var string $webhookPrefix */
        $webhookPrefix = config('integrations.webhook.prefix', 'integrations');
        /** @var list<string> $webhookMiddleware */
        $webhookMiddleware = config('integrations.webhook.middleware', []);

        Route::middleware($webhookMiddleware)
            ->prefix($webhookPrefix)
            ->group(function (): void {
                Route::match(['get', 'post'], '{provider}/webhook', [WebhookController::class, 'handle'])
                    ->name('integrations.webhook');
                Route::match(['get', 'post'], '{provider}/{id}/webhook', [WebhookController::class, 'handleForIntegration'])
                    ->where('id', '[0-9]+')
                    ->name('integrations.webhook.specific');
            });

        /** @var string $oauthPrefix */
        $oauthPrefix = config('integrations.oauth.route_prefix', 'integrations');
        /** @var list<string> $oauthMiddleware */
        $oauthMiddleware = config('integrations.oauth.middleware', ['web']);

        Route::middleware($oauthMiddleware)
            ->prefix($oauthPrefix)
            ->group(function (): void {
                Route::get('{integration}/oauth/authorize', [OAuthController::class, 'authorize'])
                    ->where('integration', '[0-9]+')
                    ->name('integrations.oauth.authorize');
                Route::get('oauth/callback', [OAuthController::class, 'callback'])
                    ->name('integrations.oauth.callback');
                Route::post('{integration}/oauth/revoke', [OAuthController::class, 'revoke'])
                    ->where('integration', '[0-9]+')
                    ->name('integrations.oauth.revoke');
            });
    }
}
