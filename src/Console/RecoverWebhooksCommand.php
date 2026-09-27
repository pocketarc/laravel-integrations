<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Jobs\ProcessWebhook;
use Integrations\Models\IntegrationWebhook;
use Integrations\Support\Config;

class RecoverWebhooksCommand extends Command
{
    protected $signature = 'integrations:recover-webhooks';

    protected $description = 'Re-dispatch webhooks stuck in processing or pending, and retry failed webhooks that have attempts left.';

    public function handle(): int
    {
        $recovered = $this->recoverStaleProcessing();
        $redispatched = $this->redispatchStalePending();
        $retried = $this->retryFailed();

        if ($recovered + $redispatched + $retried === 0) {
            $this->info('No webhooks to recover.');

            return self::SUCCESS;
        }

        if ($recovered > 0) {
            $this->info("Re-dispatched {$recovered} webhook(s) stuck in 'processing'.");
        }

        if ($redispatched > 0) {
            $this->info("Re-dispatched {$redispatched} webhook(s) stuck in 'pending'.");
        }

        if ($retried > 0) {
            $this->info("Re-dispatched {$retried} failed webhook(s).");
        }

        return self::SUCCESS;
    }

    private function recoverStaleProcessing(): int
    {
        $recovered = 0;

        $stale = IntegrationWebhook::query()
            ->staleProcessing(Config::webhookProcessingTimeout())
            ->select('id')
            ->lazyById();

        foreach ($stale as $webhook) {
            if ($webhook->resetToPending()) {
                $this->dispatchJob($webhook);
                $recovered++;
            }
        }

        return $recovered;
    }

    private function redispatchStalePending(): int
    {
        $redispatched = 0;
        $timeout = Config::webhookPendingTimeout();
        $staleBefore = now()->subSeconds($timeout);

        $lost = IntegrationWebhook::query()
            ->stalePending($timeout)
            ->select('id')
            ->lazyById();

        foreach ($lost as $webhook) {
            if ($webhook->reclaimPending($staleBefore)) {
                $this->dispatchJob($webhook);
                $redispatched++;
            }
        }

        return $redispatched;
    }

    private function retryFailed(): int
    {
        $retried = 0;
        $backoff = Config::webhookRetryBackoff();

        $failed = IntegrationWebhook::query()
            ->retryable(Config::webhookMaxAttempts())
            ->select(['id', 'attempts', 'processed_at'])
            ->lazyById();

        foreach ($failed as $webhook) {
            $wait = $backoff[$webhook->attempts - 1] ?? $backoff[array_key_last($backoff)];

            if ($webhook->processed_at?->greaterThan(now()->subSeconds($wait)) === true) {
                continue;
            }

            if ($webhook->retry()) {
                $this->dispatchJob($webhook);
                $retried++;
            }
        }

        return $retried;
    }

    private function dispatchJob(IntegrationWebhook $webhook): void
    {
        ProcessWebhook::dispatch($webhook->id)->onQueue(Config::webhookQueue());
    }
}
