<?php

declare(strict_types=1);

namespace Integrations\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Integrations\IntegrationManager;
use Override;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Handles encryption at rest and optional typed casting via Spatie LaravelData.
 *
 * When the provider's credentialDataClass() returns a Data class, the decrypted
 * JSON is cast to that class. Otherwise, returns a plain array.
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
        } catch (DecryptException $e) {
            report($e);

            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($decrypted, true);

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $provider = $attributes['provider'] ?? null;

        if (is_string($provider)) {
            $dataClass = $this->resolveDataClass($provider);

            if ($dataClass !== null && is_subclass_of($dataClass, Data::class)) {
                return $dataClass::from($decoded);
            }
        }

        return $decoded;
    }

    private function resolveDataClass(string $provider): ?string
    {
        try {
            return app(IntegrationManager::class)->resolveCredentialDataClass($provider);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
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
