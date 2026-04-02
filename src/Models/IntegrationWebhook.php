<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Integrations\Support\Config;

/**
 * @property int $id
 * @property int $integration_id
 * @property string $delivery_id
 * @property string|null $event_type
 * @property string $payload
 * @property array<string, mixed> $headers
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook newModelQuery()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook newQuery()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook query()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook pending()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook failed()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook forEventType(string $eventType)
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook recent(int $hours = 24)
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook staleProcessing(int $timeoutSeconds)
 *
 * @property-read Integration|null $integration
 *
 * @mixin \Eloquent
 */
class IntegrationWebhook extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    public function getTable(): string
    {
        return Config::tablePrefix().'_webhooks';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'json',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function markProcessing(): bool
    {
        $now = now();

        $claimed = $this->newQuery()
            ->where('id', $this->id)
            ->where('status', 'pending')
            ->update(['status' => 'processing', 'error' => null, 'processed_at' => null, 'updated_at' => $now]);

        if ($claimed > 0) {
            $this->fill(['status' => 'processing', 'error' => null, 'processed_at' => null, 'updated_at' => $now]);

            return true;
        }

        return false;
    }

    public function resetToPending(): bool
    {
        $now = now();

        $reset = $this->newQuery()
            ->where('id', $this->id)
            ->where('status', 'processing')
            ->update(['status' => 'pending', 'error' => null, 'processed_at' => null, 'updated_at' => $now]);

        if ($reset > 0) {
            $this->fill(['status' => 'pending', 'error' => null, 'processed_at' => null, 'updated_at' => $now]);

            return true;
        }

        return false;
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'error' => null,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'processed_at' => now(),
        ]);
    }

    /**
     * @param  Builder  $query
     * @return Builders\IntegrationWebhookBuilder<IntegrationWebhook>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationWebhookBuilder
    {
        return new Builders\IntegrationWebhookBuilder($query);
    }
}
