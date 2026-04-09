<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\IntegrationProvider;
use Throwable;

class RetryTestProvider implements CustomizesRetry, IntegrationProvider
{
    public static ?bool $isRetryable = null;

    public static ?int $delayMs = null;

    public static mixed $capturedStatusCode = 'not-called';

    public static int $delayCallCount = 0;

    public function name(): string
    {
        return 'Retry Test Provider';
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
        self::$capturedStatusCode = $statusCode;
        self::$delayCallCount++;

        return self::$delayMs;
    }
}
