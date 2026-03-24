<?php

declare(strict_types=1);

namespace Integrations\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Override;
use Spatie\LaravelData\Data;

/**
 * Handles optional typed casting via Spatie LaravelData for metadata.
 *
 * When a Data class is configured for the integration's provider in
 * `integrations.metadata_data_classes`, the JSON is cast to that Data class.
 * Otherwise, returns a plain array.
 *
 * @implements CastsAttributes<Data|array<string, mixed>|null, mixed>
 */
class IntegrationMetadataCast implements CastsAttributes
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

        /** @var array<string, mixed>|null $decoded */
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $provider = $attributes['provider'] ?? null;

        if (is_string($provider)) {
            /** @var array<string, class-string<Data>> $mapping */
            $mapping = config('integrations.metadata_data_classes', []);

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

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
