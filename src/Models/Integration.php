<?php

declare(strict_types=1);

namespace Integrations\Models;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Integrations\Casts\IntegrationCredentialCast;
use Integrations\Casts\IntegrationMetadataCast;
use Integrations\Concerns\ManagesResilienceOverrides;
use Integrations\Concerns\TracksHealth;
use Integrations\Contracts\HasOAuth2;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\SupportsIdempotency;
use Integrations\Enums\CircuitOverride;
use Integrations\Enums\HealthStatus;
use Integrations\Events\IntegrationCreated;
use Integrations\Events\IntegrationSynced;
use Integrations\Events\OperationCompleted;
use Integrations\Events\OperationFailed;
use Integrations\Events\OperationStarted;
use Integrations\Exceptions\IdempotencyConflict;
use Integrations\IntegrationManager;
use Integrations\PendingRequest;
use Integrations\RequestContext;
use Integrations\RequestExecutor;
use Integrations\Support\Config;
use Integrations\Testing\IntegrationRequestFake;
use InvalidArgumentException;
use JsonException;
use Spatie\LaravelData\Data;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * @property int $id
 * @property string $provider
 * @property string $name
 * @property Data|array<string, mixed>|null $credentials
 * @property Data|array<string, mixed>|null $metadata
 * @property bool $is_active
 * @property HealthStatus $health_status
 * @property int $consecutive_failures
 * @property Carbon|null $last_error_at
 * @property CircuitOverride|null $circuit_override
 * @property Carbon|null $circuit_override_until
 * @property array{limit: int, windowSeconds: int, window: string}|null $rate_limit_override
 * @property Carbon|null $rate_limit_override_until
 * @property Carbon|null $last_synced_at
 * @property int|null $sync_interval_minutes
 * @property Carbon|null $next_sync_at
 * @property mixed $sync_cursor
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builders\IntegrationBuilder<static>|Integration newModelQuery()
 * @method static Builders\IntegrationBuilder<static>|Integration newQuery()
 * @method static Builders\IntegrationBuilder<static>|Integration query()
 * @method static Builders\IntegrationBuilder<static>|Integration active()
 * @method static Builders\IntegrationBuilder<static>|Integration forProvider(string $provider)
 * @method static Builders\IntegrationBuilder<static>|Integration dueForSync()
 * @method static Builders\IntegrationBuilder<static>|Integration ownedBy(\Illuminate\Database\Eloquent\Model $owner)
 *
 * @property-read Collection<int, IntegrationLog> $logs
 * @property-read int|null $logs_count
 * @property-read Collection<int, IntegrationMapping> $mappings
 * @property-read int|null $mappings_count
 * @property-read Model $owner
 * @property-read Collection<int, IntegrationRequest> $requests
 * @property-read int|null $requests_count
 * @property-read Collection<int, IntegrationSyncItem> $syncItems
 * @property-read int|null $sync_items_count
 * @property-read Collection<int, IntegrationWebhook> $webhooks
 * @property-read int|null $webhooks_count
 *
 * @mixin \Eloquent
 */
class Integration extends Model
{
    use ManagesResilienceOverrides;
    use TracksHealth;

    /** @var array<string> */
    protected $guarded = [];

    private ?RequestExecutor $executor = null;

    /**
     * Thread-local-ish slot for the active RequestContext during a closure
     * call. Set by RequestExecutor before invoking the user closure and
     * cleared in a `finally`. Closures that can't take an argument (because
     * they're wrapped behind another layer, for example) can read this via
     * Integration::currentContext() instead.
     *
     * Single static slot, like Laravel's Auth: accurate under sync PHP,
     * shared across coroutines under Swoole/RoadRunner.
     */
    private static ?RequestContext $currentContext = null;

    #[\Override]
    protected static function booted(): void
    {
        static::created(function (Integration $integration): void {
            IntegrationCreated::dispatch($integration);
        });
    }

    #[\Override]
    public function getTable(): string
    {
        return Config::tablePrefix().'s';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'credentials' => IntegrationCredentialCast::class,
            'metadata' => IntegrationMetadataCast::class,
            'is_active' => 'boolean',
            'health_status' => HealthStatus::class,
            'consecutive_failures' => 'integer',
            'last_error_at' => 'datetime',
            'circuit_override' => CircuitOverride::class,
            'circuit_override_until' => 'datetime',
            'rate_limit_override' => 'array',
            'rate_limit_override_until' => 'datetime',
            'last_synced_at' => 'datetime',
            'sync_interval_minutes' => 'integer',
            'next_sync_at' => 'datetime',
            'sync_cursor' => 'json',
        ];
    }

    /** @return HasMany<IntegrationRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(IntegrationRequest::class);
    }

    /**
     * Replay the response body of the prior, successful keyed call for the
     * supplied idempotency key. Returns `null` when nothing recoverable is
     * on file: no row, the row was logged before the call landed, the row
     * is from a failed attempt, or the persisted JSON is unparseable.
     *
     * Intended for the {@see IdempotencyConflict}
     * recovery path documented in `docs/core-concepts/idempotency.md`
     * § "Partial failure": when a prior attempt succeeded upstream but
     * threw before the local follow-up write completed, the next attempt
     * trips the conflict and the caller needs the original response to
     * finish the local bookkeeping. The same array is also attached to
     * the thrown {@see IdempotencyConflict} via
     * `$e->priorResponse`, so most callers don't need to invoke this
     * method directly.
     *
     * The returned value is the parsed response array, not a Data object,
     * because hydrating into a domain DTO is the caller's choice (the
     * package doesn't know which class the response should land in).
     *
     * @return array<array-key, mixed>|null
     */
    public function getIdempotencyResponse(string $key): ?array
    {
        $prior = $this->requests()
            ->where('idempotency_key', $key)
            ->where('response_success', true)
            ->latest('id')
            ->first();

        if ($prior === null || $prior->response_data === null) {
            return null;
        }

        try {
            $decoded = json_decode($prior->response_data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return HasMany<IntegrationLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(IntegrationLog::class);
    }

    /** @return HasMany<IntegrationMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(IntegrationMapping::class);
    }

    /** @return HasMany<IntegrationWebhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(IntegrationWebhook::class);
    }

    /** @return HasMany<IntegrationSyncItem, $this> */
    public function syncItems(): HasMany
    {
        return $this->hasMany(IntegrationSyncItem::class);
    }

    /**
     * Count of sync items still in flight (pending or processing). A non-zero
     * count means a sync run's batch hasn't finished; `SyncIntegration` uses
     * this to avoid piling a second batch on top of an in-progress one.
     */
    public function pendingSyncItemCount(): int
    {
        return $this->syncItems()->inFlight()->count();
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function provider(): IntegrationProvider
    {
        return app(IntegrationManager::class)->provider($this->provider);
    }

    private static function keyToString(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        throw new InvalidArgumentException('Model key must be a string or integer.');
    }

    /**
     * Read the active request context from inside a callback. Returns null
     * when called outside of an in-flight request, so wrapped closures can
     * defensively check before reading. Most callers should accept the
     * context as a typed first parameter on the closure instead. This is
     * the escape hatch for layered/wrapped invocations.
     */
    public static function currentContext(): ?RequestContext
    {
        return self::$currentContext;
    }

    /**
     * @internal Called by RequestExecutor around the closure invocation.
     */
    public static function setCurrentContext(?RequestContext $context): void
    {
        self::$currentContext = $context;
    }

    private ?int $activeSyncLogId = null;

    /** @var list<int> */
    private array $syncRequestIds = [];

    public function setSyncContext(int $logId): void
    {
        $this->activeSyncLogId = $logId;
        $this->syncRequestIds = [];
    }

    /**
     * @return list<int>
     */
    public function clearSyncContext(): array
    {
        $ids = $this->syncRequestIds;
        $this->activeSyncLogId = null;
        $this->syncRequestIds = [];

        return $ids;
    }

    /** @internal */
    public function activeSyncLogId(): ?int
    {
        return $this->activeSyncLogId;
    }

    /** @internal */
    public function trackSyncRequestId(int $id): void
    {
        if ($this->activeSyncLogId !== null) {
            $this->syncRequestIds[] = $id;
        }
    }

    /**
     * Start a fluent request against this integration's endpoint. Chain
     * `->as(SomeData::class)` to type the response, then call `->get()` /
     * `->post()` / etc. with the closure that performs the SDK call.
     */
    public function at(string $endpoint): PendingRequest
    {
        return new PendingRequest($this, $endpoint);
    }

    /**
     * Execute a callback against this integration, logging the request and
     * (when `$responseClass` is set) hydrating the result through Spatie
     * Data. Both live and cached paths run the result through `Data::from()`,
     * so typed responses are type-consistent regardless of cache-hit state.
     *
     * Most callers should use the fluent `at()->...->get()` builder; this
     * method is the underlying executor exposed for direct use when you
     * already have a method/callback in hand.
     *
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)  $callback
     * @param  class-string<Data>|null  $responseClass
     * @param  string|array<string, mixed>|null  $requestData
     */
    public function request(
        string $endpoint,
        string $method,
        Closure $callback,
        ?string $responseClass = null,
        ?Model $relatedTo = null,
        string|array|null $requestData = null,
        ?CarbonInterface $cacheFor = null,
        bool $serveStale = false,
        ?int $retryOfId = null,
        ?int $maxAttempts = null,
        ?string $idempotencyKey = null,
    ): mixed {
        $maxAttempts ??= mb_strtoupper($method) === 'GET' ? 3 : 1;

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('$maxAttempts must be at least 1.');
        }

        if ($responseClass !== null && ! is_subclass_of($responseClass, Data::class, true)) {
            throw new InvalidArgumentException(sprintf(
                '$responseClass must be a class-string of %s or null, got %s.',
                Data::class,
                $responseClass,
            ));
        }

        $encodedRequestData = is_array($requestData) ? json_encode($requestData, JSON_THROW_ON_ERROR) : $requestData;

        if ($idempotencyKey !== null && ! ($this->provider() instanceof SupportsIdempotency)) {
            Log::warning(
                "Idempotency key set on {$this->provider} request to '{$endpoint}', but the provider does not implement SupportsIdempotency. The key is persisted on integration_requests for searchability, but the provider will not deduplicate the call on its end."
            );
        }

        $fake = IntegrationRequestFake::active();
        if ($fake !== null) {
            return $fake->record($this, $endpoint, $method, $encodedRequestData, $responseClass);
        }

        return $this->executor()->execute(
            $endpoint, $method, $responseClass, $callback, $relatedTo,
            $encodedRequestData, $cacheFor, $serveStale, $retryOfId, $maxAttempts,
            $idempotencyKey,
        );
    }

    private function executor(): RequestExecutor
    {
        return $this->executor ??= new RequestExecutor($this);
    }

    public function getAccessToken(): ?string
    {
        $this->refreshTokenIfNeeded();

        $token = $this->credentialsArray()['access_token'] ?? null;

        return is_string($token) ? $token : null;
    }

    public function tokenExpiresSoon(): bool
    {
        $expiresAt = $this->credentialsArray()['token_expires_at'] ?? null;
        if (! is_string($expiresAt)) {
            return false;
        }

        $provider = $this->provider();
        $threshold = $provider instanceof HasOAuth2 ? $provider->refreshThreshold() : 300;

        return Carbon::parse($expiresAt)->subSeconds($threshold)->isPast();
    }

    public function refreshTokenIfNeeded(): void
    {
        if (! $this->tokenExpiresSoon()) {
            return;
        }

        $provider = $this->provider();
        if (! $provider instanceof HasOAuth2) {
            return;
        }

        $lock = Cache::lock(
            Config::cachePrefix().":oauth:refresh:{$this->id}",
            Config::oauthRefreshLockTtl(),
        );

        try {
            $lock->block(Config::oauthRefreshLockWait());

            // Another process may have refreshed while we waited for the lock
            $freshCopy = $this->fresh();
            if ($freshCopy === null || ! $freshCopy->tokenExpiresSoon()) {
                if ($freshCopy !== null) {
                    $this->fill(['credentials' => $freshCopy->credentialsArray()]);
                }

                return;
            }

            $newCredentials = $provider->refreshToken($this);
            $mergedCredentials = array_merge($freshCopy->credentialsArray(), $newCredentials);

            $freshCopy->update(['credentials' => $mergedCredentials]);

            $this->fill(['credentials' => $mergedCredentials]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialsArray(): array
    {
        $credentials = $this->credentials;

        if ($credentials instanceof Data) {
            /** @var array<string, mixed> */
            return $credentials->toArray();
        }

        return is_array($credentials) ? $credentials : [];
    }

    public function markSynced(?Carbon $syncedAt = null): void
    {
        $syncedAt ??= now();

        $nextSync = $this->sync_interval_minutes !== null
            ? $syncedAt->copy()->addMinutes($this->sync_interval_minutes)
            : null;

        $this->update([
            'last_synced_at' => $syncedAt,
            'next_sync_at' => $nextSync,
        ]);

        IntegrationSynced::dispatch($this);
    }

    public function syncedSince(): ?Carbon
    {
        return $this->last_synced_at;
    }

    public function updateSyncCursor(mixed $cursor): void
    {
        $this->update(['sync_cursor' => $cursor]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $resultData
     */
    public function logOperation(
        string $operation,
        string $direction,
        string $status,
        ?string $externalId = null,
        ?string $summary = null,
        ?array $metadata = null,
        ?string $error = null,
        ?int $durationMs = null,
        ?int $parentId = null,
        ?array $resultData = null,
    ): IntegrationLog {
        $log = $this->logs()->create([
            'parent_id' => $parentId,
            'operation' => $operation,
            'direction' => $direction,
            'status' => $status,
            'external_id' => $externalId,
            'summary' => $summary,
            'metadata' => $metadata,
            'result_data' => $resultData,
            'error' => $error,
            'duration_ms' => $durationMs,
        ]);

        if ($status === 'success') {
            OperationCompleted::dispatch($this, $log);
        } elseif ($status === 'failed') {
            OperationFailed::dispatch($this, $log);
        } elseif ($status === 'processing') {
            OperationStarted::dispatch($this, $log);
        }

        return $log;
    }

    public function mapExternalId(string $externalId, Model $internalModel): IntegrationMapping
    {
        return $this->mappings()->updateOrCreate(
            [
                'external_id' => $externalId,
                'internal_type' => $internalModel->getMorphClass(),
            ],
            [
                'internal_id' => self::keyToString($internalModel->getKey()),
            ],
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $internalType
     * @return TModel|null
     */
    public function resolveMapping(string $externalId, string $internalType): ?Model
    {
        $mapping = $this->mappings()
            ->where('external_id', $externalId)
            ->where('internal_type', (new $internalType)->getMorphClass())
            ->first();

        if ($mapping === null) {
            return null;
        }

        $model = (new $internalType)->newQuery()->find($mapping->internal_id);

        if (! $model instanceof $internalType) {
            return null;
        }

        return $model;
    }

    public function findExternalId(Model $internalModel): ?string
    {
        $mapping = $this->mappings()
            ->where('internal_type', $internalModel->getMorphClass())
            ->where('internal_id', self::keyToString($internalModel->getKey()))
            ->first();

        return $mapping?->external_id;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function upsertByExternalId(string $externalId, string $modelClass, array $attributes): Model
    {
        $existing = $this->resolveMapping($externalId, $modelClass);

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        DB::beginTransaction();

        try {
            $model = new $modelClass($attributes);
            $model->save();
            $this->mapExternalId($externalId, $model);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $model;
    }

    /**
     * @template TModel of Model
     *
     * @param  list<string>  $externalIds
     * @param  class-string<TModel>  $internalType
     * @return \Illuminate\Support\Collection<string, TModel|null>
     */
    public function resolveMappings(array $externalIds, string $internalType): \Illuminate\Support\Collection
    {
        /** @var array<string, TModel|null> $result */
        $result = [];

        if ($externalIds === []) {
            return collect($result);
        }

        $morphClass = (new $internalType)->getMorphClass();

        $mappings = $this->mappings()
            ->whereIn('external_id', $externalIds)
            ->where('internal_type', $morphClass)
            ->get();

        $internalIds = $mappings->pluck('internal_id')->unique()->values()->all();

        $instance = new $internalType;
        $modelsByKey = $instance->newQuery()
            ->whereIn($instance->getKeyName(), $internalIds)
            ->get()
            ->keyBy(fn (Model $model): string => self::keyToString($model->getKey()));

        foreach ($externalIds as $externalId) {
            $mapping = $mappings->firstWhere('external_id', $externalId);
            $model = $mapping !== null ? $modelsByKey->get($mapping->internal_id) : null;
            $result[$externalId] = $model instanceof $internalType ? $model : null;
        }

        return collect($result);
    }

    /**
     * @param  Builder  $query
     * @return Builders\IntegrationBuilder<Integration>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationBuilder
    {
        return new Builders\IntegrationBuilder($query);
    }
}
