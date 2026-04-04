<?php

declare(strict_types=1);

namespace Integrations\Models;

use Carbon\CarbonInterface;
use Closure;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Integrations\Casts\IntegrationCredentialCast;
use Integrations\Casts\IntegrationMetadataCast;
use Integrations\Contracts\HasOAuth2;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Enums\HealthStatus;
use Integrations\Events\IntegrationCreated;
use Integrations\Events\IntegrationDisabled;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Events\IntegrationSynced;
use Integrations\Events\OperationCompleted;
use Integrations\Events\OperationFailed;
use Integrations\Events\RequestCompleted;
use Integrations\Events\RequestFailed;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\IntegrationManager;
use Integrations\PendingRequest;
use Integrations\RetryHandler;
use Integrations\Support\Config;
use Integrations\Support\Redactor;
use Integrations\Testing\IntegrationRequestFake;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
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
 *
 * @mixin \Eloquent
 */
class Integration extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    private ?int $lastCreatedRequestId = null;

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

    public function to(string $endpoint): PendingRequest
    {
        return new PendingRequest($this, $endpoint);
    }

    /**
     * Execute a callback against this integration, logging the request.
     *
     * @param  (Closure(): mixed)|null  $callback
     * @param  string|array<string, mixed>|null  $requestData
     */
    public function request(
        string $endpoint,
        string $method,
        ?Closure $callback = null,
        ?Model $relatedTo = null,
        string|array|null $requestData = null,
        ?CarbonInterface $cacheFor = null,
        bool $serveStale = false,
        ?int $retryOfId = null,
        ?int $maxRetries = null,
    ): mixed {
        $maxRetries ??= mb_strtoupper($method) === 'GET' ? 3 : 1;

        $fake = IntegrationRequestFake::active();
        if ($fake !== null) {
            $encodedData = is_array($requestData) ? json_encode($requestData, JSON_THROW_ON_ERROR) : $requestData;

            return $fake->record($this, $endpoint, $method, $encodedData);
        }

        $this->enforceRateLimit();

        $encodedRequestData = is_array($requestData) ? json_encode($requestData, JSON_THROW_ON_ERROR) : $requestData;

        // Check cache
        if ($cacheFor !== null) {
            $cached = $this->findCachedResponse($endpoint, $method, $encodedRequestData);
            if ($cached !== null) {
                try {
                    $decoded = json_decode($cached->response_data ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    $cached->increment('cache_hits');

                    return $decoded;
                } catch (\JsonException) {
                    // Corrupt cached data - treat as cache miss and re-request
                }
            }
        }

        if ($callback === null) {
            return null;
        }

        if ($maxRetries > 1) {
            return $this->requestWithRetries(
                $endpoint, $method, $callback, $relatedTo,
                $encodedRequestData, $cacheFor, $serveStale, $maxRetries, $retryOfId,
            );
        }

        return $this->executeRequest(
            $endpoint, $method, $callback, $relatedTo,
            $encodedRequestData, $cacheFor, $serveStale, $retryOfId,
        );
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function requestWithRetries(
        string $endpoint,
        string $method,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        int $maxRetries,
        ?int $retryOfId = null,
    ): mixed {
        $firstRequestId = $retryOfId;
        $lastException = null;

        $this->lastCreatedRequestId = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $isLastAttempt = $attempt >= $maxRetries;
            $allowStale = $serveStale && $isLastAttempt;

            try {
                $result = $this->executeRequest(
                    $endpoint, $method, $callback, $relatedTo,
                    $encodedRequestData, $cacheFor, $allowStale,
                    retryOfId: $firstRequestId,
                );

                $firstRequestId ??= $this->lastCreatedRequestId;

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;

                $firstRequestId ??= $this->lastCreatedRequestId;

                $willRetry = ! $isLastAttempt && RetryHandler::isRetryable($e);

                if (! $willRetry) {
                    // Not retrying — try stale cache as last resort before giving up
                    if ($serveStale && ! $allowStale) {
                        $stale = $this->findStaleCachedResponse($endpoint, $method, $encodedRequestData);
                        if ($stale !== null) {
                            try {
                                $decoded = json_decode($stale->response_data ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                                $stale->increment('stale_hits');

                                return $decoded;
                            } catch (\JsonException) {
                                // Fall through to throw
                            }
                        }
                    }

                    throw $e;
                }

                $delayMs = RetryHandler::calculateDelayMs($e, $attempt);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Retry logic exhausted without result.');
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function executeRequest(
        string $endpoint,
        string $method,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        ?int $retryOfId = null,
    ): mixed {
        $startTime = microtime(true);
        $responseSuccess = false;
        $responseCode = null;
        $responseData = null;
        $error = null;
        $result = null;

        try {
            $result = $callback();
            $responseSuccess = true;

            [$responseCode, $responseData, $result] = $this->normalizeResponse($result);
        } catch (\Throwable $e) {
            $error = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => mb_strcut($e->getTraceAsString(), 0, 2000),
            ];

            $responseCode = $this->extractStatusCodeFromException($e);

            if ($serveStale) {
                $stale = $this->findStaleCachedResponse($endpoint, $method, $encodedRequestData);
                if ($stale !== null) {
                    try {
                        $result = json_decode($stale->response_data ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                        $stale->increment('stale_hits');
                    } catch (\JsonException) {
                    }
                }
            }

            if ($result === null) {
                $this->recordFailure();
                $durationMs = (int) ((microtime(true) - $startTime) * 1_000);

                $request = $this->persistRequest(
                    $endpoint, $method, $encodedRequestData, $retryOfId,
                    $relatedTo, $responseCode, $responseData, false,
                    $error, $durationMs, $cacheFor,
                );
                $this->lastCreatedRequestId = is_int($request->getKey()) ? $request->getKey() : null;

                RequestFailed::dispatch($this, $request);

                throw $e;
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1_000);

        $request = $this->persistRequest(
            $endpoint, $method, $encodedRequestData, $retryOfId,
            $relatedTo, $responseCode, $responseData, $responseSuccess,
            $error, $durationMs, $cacheFor,
        );
        $this->lastCreatedRequestId = is_int($request->getKey()) ? $request->getKey() : null;

        if ($responseSuccess) {
            RequestCompleted::dispatch($this, $request);
        } else {
            RequestFailed::dispatch($this, $request);
        }

        if ($responseSuccess) {
            $this->recordSuccess();
        } else {
            $this->recordFailure();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    private function persistRequest(
        string $endpoint,
        string $method,
        ?string $requestData,
        ?int $retryOfId,
        ?Model $relatedTo,
        ?int $responseCode,
        ?string $responseData,
        bool $responseSuccess,
        ?array $error,
        int $durationMs,
        ?CarbonInterface $cacheFor,
    ): IntegrationRequest {
        $provider = $this->provider();

        if ($provider instanceof RedactsRequestData) {
            if ($requestData !== null) {
                $requestData = Redactor::redact($requestData, $provider->sensitiveRequestFields());
            }
            if ($responseData !== null) {
                $responseData = Redactor::redact($responseData, $provider->sensitiveResponseFields());
            }
        }

        $truncatedRequestData = $requestData !== null ? mb_strcut($requestData, 0, 65530) : null;

        /** @var IntegrationRequest */
        $request = $this->requests()->create([
            'endpoint' => $endpoint,
            'method' => $method,
            'request_data' => $truncatedRequestData,
            'request_data_hash' => $truncatedRequestData !== null ? hash('xxh128', $truncatedRequestData) : null,
            'retry_of' => $retryOfId,
            'related_type' => $relatedTo !== null ? $relatedTo->getMorphClass() : null,
            'related_id' => $relatedTo !== null ? self::keyToString($relatedTo->getKey()) : null,
            'response_code' => $responseCode,
            'response_data' => $responseData,
            'response_success' => $responseSuccess,
            'error' => $error,
            'duration_ms' => $durationMs,
            'expires_at' => $cacheFor,
        ]);

        if ($this->activeSyncLogId !== null) {
            $this->syncRequestIds[] = $request->id;
        }

        return $request;
    }

    /**
     * @return array{int|null, string|null, mixed}
     */
    private function normalizeResponse(mixed $response): array
    {
        if ($response instanceof Response) {
            return [
                $response->status(),
                $response->body(),
                $response->json() ?? $response->body(),
            ];
        }

        if ($response instanceof ResponseInterface) {
            $body = (string) $response->getBody();

            return [
                $response->getStatusCode(),
                $body,
                json_decode($body, true) ?? $body,
            ];
        }

        if ($response instanceof JsonResponse) {
            return [
                $response->getStatusCode(),
                $response->getContent() !== false ? $response->getContent() : null,
                $response->getData(true),
            ];
        }

        if (is_array($response)) {
            return [
                null,
                json_encode($response, JSON_THROW_ON_ERROR),
                $response,
            ];
        }

        if (is_object($response)) {
            $encoded = json_encode($response, JSON_THROW_ON_ERROR);

            return [null, $encoded, $response];
        }

        if (is_string($response)) {
            return [null, $response, $response];
        }

        return [null, null, $response];
    }

    private function extractStatusCodeFromException(\Throwable $e): ?int
    {
        if ($e instanceof \Illuminate\Http\Client\RequestException) {
            return $e->response->status();
        }

        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    private function findCachedResponse(string $endpoint, string $method, ?string $requestData): ?IntegrationRequest
    {
        $hash = $requestData !== null ? hash('xxh128', mb_strcut($requestData, 0, 65530)) : null;

        return $this->requests()
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('response_success', true)
            ->where('expires_at', '>', now())
            ->when($hash !== null, fn (Builder $q) => $q->where('request_data_hash', $hash))
            ->when($hash === null, fn (Builder $q) => $q->whereNull('request_data'))
            ->latest()
            ->first();
    }

    private function findStaleCachedResponse(string $endpoint, string $method, ?string $requestData): ?IntegrationRequest
    {
        $hash = $requestData !== null ? hash('xxh128', mb_strcut($requestData, 0, 65530)) : null;

        return $this->requests()
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('response_success', true)
            ->when($hash !== null, fn (Builder $q) => $q->where('request_data_hash', $hash))
            ->when($hash === null, fn (Builder $q) => $q->whereNull('request_data'))
            ->latest()
            ->first();
    }

    private function enforceRateLimit(): void
    {
        $provider = $this->provider();

        $limit = null;
        if ($provider instanceof HasScheduledSync) {
            $limit = $provider->defaultRateLimit();
        }

        if ($limit === null) {
            return;
        }

        $maxWait = Config::rateLimitMaxWaitSeconds();
        $waited = 0;

        while (true) {
            $requestsThisMinute = $this->requests()
                ->where('created_at', '>=', now()->subMinute())
                ->count();

            if ($requestsThisMinute < $limit) {
                return;
            }

            if ($waited >= $maxWait) {
                throw new RateLimitExceededException($this, $requestsThisMinute, $limit);
            }

            sleep(1);
            $waited++;
        }
    }

    public function recordSuccess(): void
    {
        $previousStatus = $this->health_status;

        if ($previousStatus === HealthStatus::Disabled) {
            return;
        }

        $this->update([
            'consecutive_failures' => 0,
            'health_status' => HealthStatus::Healthy,
        ]);

        if ($previousStatus !== HealthStatus::Healthy) {
            IntegrationHealthChanged::dispatch($this, $previousStatus, HealthStatus::Healthy);
        }
    }

    public function recordFailure(): void
    {
        $previousStatus = null;
        $newStatus = null;

        DB::transaction(function () use (&$previousStatus, &$newStatus): void {
            /** @var Integration|null $locked */
            $locked = Integration::lockForUpdate()->find($this->id);

            if ($locked === null) {
                return;
            }

            $previousStatus = $locked->health_status;
            $failures = $locked->consecutive_failures + 1;

            $disabledAfter = Config::disabledAfter();

            $newStatus = match (true) {
                $disabledAfter !== null && $failures >= $disabledAfter => HealthStatus::Disabled,
                $failures >= Config::failingAfter() => HealthStatus::Failing,
                $failures >= Config::degradedAfter() => HealthStatus::Degraded,
                default => $previousStatus,
            };

            $updates = [
                'consecutive_failures' => $failures,
                'last_error_at' => now(),
                'health_status' => $newStatus,
            ];

            if ($newStatus === HealthStatus::Disabled) {
                $updates['is_active'] = false;
            }

            $locked->update($updates);

            $this->fill($locked->only([
                'consecutive_failures',
                'last_error_at',
                'health_status',
                'is_active',
            ]));
            $this->syncOriginal();
        });

        if ($previousStatus === null || $newStatus === null) {
            return;
        }

        if ($newStatus === HealthStatus::Disabled && $previousStatus !== HealthStatus::Disabled) {
            IntegrationDisabled::dispatch($this);
        }

        if ($newStatus !== $previousStatus) {
            IntegrationHealthChanged::dispatch($this, $previousStatus, $newStatus);
        }
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
    ): IntegrationLog {
        $log = $this->logs()->create([
            'parent_id' => $parentId,
            'operation' => $operation,
            'direction' => $direction,
            'status' => $status,
            'external_id' => $externalId,
            'summary' => $summary,
            'metadata' => $metadata,
            'error' => $error,
            'duration_ms' => $durationMs,
        ]);

        if ($status === 'success') {
            OperationCompleted::dispatch($this, $log);
        } elseif ($status === 'failed') {
            OperationFailed::dispatch($this, $log);
        }

        return $log;
    }

    public function mapExternalId(string $externalId, Model $internalModel): IntegrationMapping
    {
        /** @var IntegrationMapping */
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
     * @param  class-string<Model>  $internalType
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

        return $mapping->internalModel()->first();
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
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builders\IntegrationBuilder<Integration>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationBuilder
    {
        return new Builders\IntegrationBuilder($query);
    }
}
