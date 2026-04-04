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
use Integrations\Casts\IntegrationCredentialCast;
use Integrations\Casts\IntegrationMetadataCast;
use Integrations\Contracts\HasOAuth2;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\HealthStatus;
use Integrations\Events\IntegrationCreated;
use Integrations\Events\IntegrationDisabled;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Events\IntegrationSynced;
use Integrations\Events\OperationCompleted;
use Integrations\Events\OperationFailed;
use Integrations\IntegrationManager;
use Integrations\PendingRequest;
use Integrations\RequestExecutor;
use Integrations\Support\Config;
use Integrations\Testing\IntegrationRequestFake;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

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

    private ?RequestExecutor $executor = null;

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

        $encodedRequestData = is_array($requestData) ? json_encode($requestData, JSON_THROW_ON_ERROR) : $requestData;

        return $this->executor()->execute(
            $endpoint, $method, $callback, $relatedTo,
            $encodedRequestData, $cacheFor, $serveStale, $retryOfId, $maxRetries,
        );
    }

    private function executor(): RequestExecutor
    {
        return $this->executor ??= new RequestExecutor($this);
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
     * @param  Builder  $query
     * @return Builders\IntegrationBuilder<Integration>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationBuilder
    {
        return new Builders\IntegrationBuilder($query);
    }
}
