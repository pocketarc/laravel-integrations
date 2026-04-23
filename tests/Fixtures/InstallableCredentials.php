<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Spatie\LaravelData\Data;

// Mixes the shapes the InstallCommand needs to handle: required + optional,
// string + int + bool, and a sensitive-named field (api_secret) that should
// prompt masked.
class InstallableCredentials extends Data
{
    public function __construct(
        public string $api_key,
        public string $api_secret,
        public ?string $region = null,
        public int $timeout = 30,
        public bool $sandbox = false,
    ) {}
}
