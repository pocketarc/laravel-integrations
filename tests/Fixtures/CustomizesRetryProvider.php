<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\IntegrationProvider;
use Throwable;

class CustomizesRetryProvider implements CustomizesRetry, IntegrationProvider
{
    public static ?bool $isRetryable = null;

    public static ?int $delayMs = null;

    public static function reset(): void
    {
        self::$isRetryable = null;
        self::$delayMs = null;
    }

    public function name(): string
    {
        return 'Customizes Retry Provider';
    }

    public function credentialRules(): array
    {
        return [];
    }

    public function metadataRules(): array
    {
        return [];
    }

    public function credentialDataClass(): ?string
    {
        return null;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }

    public function isRetryable(Throwable $e): ?bool
    {
        return self::$isRetryable;
    }

    public function retryDelayMs(Throwable $e, int $attempt, ?int $statusCode): ?int
    {
        return self::$delayMs;
    }
}
