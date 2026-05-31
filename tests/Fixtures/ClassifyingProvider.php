<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Throwable;

class ClassifyingProvider implements ClassifiesFailures, IntegrationProvider
{
    public static ?FailureClass $result = null;

    public static function reset(): void
    {
        self::$result = null;
    }

    public function classifyFailure(Throwable $e): ?FailureClass
    {
        return self::$result;
    }

    public function name(): string
    {
        return 'Classifying Provider';
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
}
