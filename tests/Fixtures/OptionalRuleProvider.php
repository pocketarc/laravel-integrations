<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\IntegrationProvider;

// No Data class; rules-only fields. `note` is declared without `required`
// or `nullable`, so it should be treated as optional. The installer must
// not prompt for it, matching what the provider actually declared.
class OptionalRuleProvider implements IntegrationProvider
{
    public function name(): string
    {
        return 'Optional Rule';
    }

    public function credentialRules(): array
    {
        return [
            'api_key' => 'required|string',
            'note' => 'string',
        ];
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
