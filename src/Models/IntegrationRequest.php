<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Integrations\Support\Config;
use Integrations\Testing\IntegrationRequestFake;

/**
 * @property int $id
 * @property int $integration_id
 * @property string|null $related_type
 * @property string|null $related_id
 * @property string $endpoint
 * @property string $method
 * @property string|null $request_data
 * @property string|null $request_data_hash
 * @property int|null $retry_of
 * @property int|null $response_code
 * @property string|null $response_data
 * @property bool $response_success
 * @property array<string, mixed>|null $error
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property int $cache_hits
 * @property int $stale_hits
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builders\IntegrationRequestBuilder<static>|IntegrationRequest newModelQuery()
 * @method static Builders\IntegrationRequestBuilder<static>|IntegrationRequest newQuery()
 * @method static Builders\IntegrationRequestBuilder<static>|IntegrationRequest query()
 * @method static Builders\IntegrationRequestBuilder<static>|IntegrationRequest successful()
 * @method static Builders\IntegrationRequestBuilder<static>|IntegrationRequest failed()
 * @method static Builders\IntegrationRequestBuilder<static>|IntegrationRequest forEndpoint(string $endpoint)
 *
 * @property-read Integration|null $integration
 * @property-read IntegrationRequest|null $originalRequest
 * @property-read Model $related
 * @property-read Collection<int, IntegrationRequest> $retries
 * @property-read int|null $retries_count
 *
 * @method static Builders\IntegrationRequestBuilder<static>|IntegrationRequest recent(int $hours = 24)
 *
 * @mixin \Eloquent
 */
class IntegrationRequest extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::tablePrefix().'_requests';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'response_success' => 'boolean',
            'error' => 'json',
            'metadata' => 'json',
            'expires_at' => 'datetime',
            'cache_hits' => 'integer',
            'stale_hits' => 'integer',
            'duration_ms' => 'integer',
            'response_code' => 'integer',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<self, $this> */
    public function originalRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of');
    }

    /** @return HasMany<self, $this> */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of');
    }

    /**
     * @param  Builder  $query
     * @return Builders\IntegrationRequestBuilder<IntegrationRequest>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationRequestBuilder
    {
        return new Builders\IntegrationRequestBuilder($query);
    }

    /**
     * @param  array<string, mixed>  $fakeResponses
     */
    public static function fake(array $fakeResponses = []): IntegrationRequestFake
    {
        return IntegrationRequestFake::activate($fakeResponses);
    }

    public static function stopFaking(): void
    {
        IntegrationRequestFake::deactivate();
    }

    public static function assertRequested(string $endpoint, ?int $times = null): void
    {
        IntegrationRequestFake::assertRequested($endpoint, $times);
    }

    public static function assertNotRequested(string $endpoint): void
    {
        IntegrationRequestFake::assertNotRequested($endpoint);
    }

    /**
     * @param  \Closure(string|null): bool  $callback
     */
    public static function assertRequestedWith(string $endpoint, \Closure $callback): void
    {
        IntegrationRequestFake::assertRequestedWith($endpoint, $callback);
    }

    public static function assertRequestCount(int $expected): void
    {
        IntegrationRequestFake::assertRequestCount($expected);
    }

    public static function assertNothingRequested(): void
    {
        IntegrationRequestFake::assertNothingRequested();
    }
}
