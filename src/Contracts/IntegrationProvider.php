<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Spatie\LaravelData\Data;

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

    /**
     * Spatie LaravelData class for typed credential access, or null for plain array.
     *
     * @return class-string<Data>|null
     */
    public function credentialDataClass(): ?string;

    /**
     * Spatie LaravelData class for typed metadata access, or null for plain array.
     *
     * @return class-string<Data>|null
     */
    public function metadataDataClass(): ?string;
}
