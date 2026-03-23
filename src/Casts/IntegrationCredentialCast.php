<?php

declare(strict_types=1);

namespace Integrations\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Override;
use Spatie\LaravelData\Data;

/**
 * Handles encryption at rest and optional typed casting via Spatie LaravelData.
 *
 * When a Data class is configured for the integration's provider in
 * `integrations.credential_data_classes`, the decrypted JSON is cast to that
 * Data class. Otherwise, returns a plain array.
 *
 * @implements CastsAttributes<Data|array<string, mixed>|null, mixed>
 */
class IntegrationCredentialCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): Data|array|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($decrypted, true);

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $provider = $attributes['provider'] ?? null;

        if (is_string($provider)) {
            /** @var array<string, class-string<Data>> $mapping */
            $mapping = config('integrations.credential_data_classes', []);

            if (isset($mapping[$provider]) && is_subclass_of($mapping[$provider], Data::class)) {
                return $mapping[$provider]::from($decoded);
            }
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Data) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
