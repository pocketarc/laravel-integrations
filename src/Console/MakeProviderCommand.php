<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeProviderCommand extends GeneratorCommand
{
    protected $signature = 'make:integration-provider {name : The provider class name}
        {--oauth : Include OAuth2 support}
        {--sync : Include scheduled sync support}
        {--webhooks : Include webhook handling}
        {--health-check : Include health check support}
        {--all : Include all optional interfaces}';

    protected $description = 'Create a new integration provider class.';

    protected $type = 'Integration Provider';

    #[\Override]
    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/integration-provider.stub';
    }

    #[\Override]
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Integrations';
    }

    /**
     * @param  string  $stub
     * @param  string  $name
     */
    #[\Override]
    protected function replaceClass($stub, $name): string
    {
        $stub = parent::replaceClass($stub, $name);

        $capabilities = $this->resolveCapabilities();

        $stub = str_replace('{{ uses }}', $this->buildUseStatements($capabilities), $stub);
        $stub = str_replace('{{ implements }}', $this->buildImplements($capabilities), $stub);
        $stub = str_replace('{{ methods }}', $this->buildMethods($capabilities), $stub);
        $stub = str_replace('{{ name }}', Str::headline(class_basename($name)), $stub);

        return $stub;
    }

    /**
     * @return list<string>
     */
    private function resolveCapabilities(): array
    {
        if ((bool) $this->option('all')) {
            return ['oauth', 'sync', 'webhooks', 'health-check'];
        }

        $capabilities = [];

        $options = ['oauth', 'sync', 'webhooks', 'health-check'];
        $hasAnyFlag = false;

        foreach ($options as $option) {
            if ((bool) $this->option($option)) {
                $hasAnyFlag = true;
                $capabilities[] = $option;
            }
        }

        if (! $hasAnyFlag && $this->input->isInteractive()) {
            foreach ($options as $option) {
                if ($this->confirm("Include {$option} support?")) {
                    $capabilities[] = $option;
                }
            }
        }

        return $capabilities;
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function buildUseStatements(array $capabilities): string
    {
        $uses = ['use Integrations\\Contracts\\IntegrationProvider;'];

        $map = [
            'oauth' => 'use Integrations\\Contracts\\HasOAuth2;',
            'sync' => "use Integrations\\Contracts\\HasScheduledSync;\nuse Integrations\\Models\\Integration;\nuse Integrations\\Sync\\SyncResult;",
            'webhooks' => "use Illuminate\\Http\\Request;\nuse Integrations\\Contracts\\HandlesWebhooks;\nuse Integrations\\Models\\Integration;",
            'health-check' => "use Integrations\\Contracts\\HasHealthCheck;\nuse Integrations\\Models\\Integration;",
        ];

        foreach ($capabilities as $capability) {
            if (array_key_exists($capability, $map)) {
                $uses[] = $map[$capability];
            }
        }

        // Deduplicate use statements
        $lines = [];
        foreach ($uses as $block) {
            foreach (explode("\n", $block) as $line) {
                $lines[$line] = true;
            }
        }

        $sorted = array_keys($lines);
        sort($sorted);

        return implode("\n", $sorted);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function buildImplements(array $capabilities): string
    {
        $interfaces = ['IntegrationProvider'];

        $map = [
            'oauth' => 'HasOAuth2',
            'sync' => 'HasScheduledSync',
            'webhooks' => 'HandlesWebhooks',
            'health-check' => 'HasHealthCheck',
        ];

        foreach ($capabilities as $capability) {
            if (array_key_exists($capability, $map)) {
                $interfaces[] = $map[$capability];
            }
        }

        sort($interfaces);

        return implode(', ', $interfaces);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function buildMethods(array $capabilities): string
    {
        $methods = '';

        if (in_array('sync', $capabilities, true)) {
            $methods .= <<<'PHP'

    public function sync(Integration $integration): SyncResult
    {
        // TODO: Implement sync logic.
        return SyncResult::empty();
    }

    public function defaultSyncInterval(): int
    {
        return 60;
    }

    public function defaultRateLimit(): ?int
    {
        return null;
    }

PHP;
        }

        if (in_array('webhooks', $capabilities, true)) {
            $methods .= <<<'PHP'

    public function handleWebhook(Integration $integration, Request $request): mixed
    {
        // TODO: Implement webhook handling.
        return ['status' => 'ok'];
    }

    public function verifyWebhookSignature(Integration $integration, Request $request): bool
    {
        // TODO: Implement signature verification.
        return false;
    }

    public function resolveWebhookEvent(Request $request): ?string
    {
        return null;
    }

    public function webhookHandlers(): array
    {
        return [];
    }

    public function webhookDeliveryId(Request $request): ?string
    {
        return null;
    }

PHP;
        }

        if (in_array('oauth', $capabilities, true)) {
            $methods .= <<<'PHP'

    public function authorizationUrl(Integration $integration, string $redirectUri, string $state): string
    {
        // TODO: Build authorization URL.
        return '';
    }

    public function exchangeCode(Integration $integration, string $code, string $redirectUri): array
    {
        // TODO: Exchange authorization code for tokens.
        return [];
    }

    public function refreshToken(Integration $integration): array
    {
        // TODO: Refresh the access token.
        return [];
    }

    public function revokeToken(Integration $integration): void
    {
        // TODO: Revoke the token.
    }

    public function refreshThreshold(): int
    {
        return 300;
    }

PHP;
        }

        if (in_array('health-check', $capabilities, true)) {
            $methods .= <<<'PHP'

    public function healthCheck(Integration $integration): bool
    {
        // TODO: Implement health check.
        return true;
    }

PHP;
        }

        return $methods;
    }
}
