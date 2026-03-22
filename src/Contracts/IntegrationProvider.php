<?php

declare(strict_types=1);

namespace Integrations\Contracts;

interface IntegrationProvider
{
    /**
     * Human-readable provider name.
     */
    public function name(): string;

    /**
     * Validation rules for the credentials array.
     *
     * @return array<string, mixed>
     */
    public function credentialRules(): array;

    /**
     * Validation rules for the metadata array.
     *
     * @return array<string, mixed>
     */
    public function metadataRules(): array;
}
