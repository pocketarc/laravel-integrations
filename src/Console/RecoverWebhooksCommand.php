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

    protected $description = 'Recover webhooks stuck in processing status and re-dispatch them.';

    public function handle(): int
    {
        $timeout = Config::webhookProcessingTimeout();

        // Ids only. resetToPending() re-checks status in its own WHERE and the
        // job is dispatched by id, so nothing here reads the payload or
        // headers. This command runs when those are backed up in bulk.
        $stale = IntegrationWebhook::query()
            ->staleProcessing($timeout)
            ->select('id')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale webhooks found.');

            return self::SUCCESS;
        }

        $recovered = 0;

        foreach ($stale as $webhook) {
            if ($webhook->resetToPending()) {
                ProcessWebhook::dispatch($webhook->id)->onQueue(Config::webhookQueue());
                $recovered++;
            }
        }

        $this->info("Recovered {$recovered} stale webhook(s).");

        return self::SUCCESS;
    }
}
