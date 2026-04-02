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

        $stale = IntegrationWebhook::query()
            ->staleProcessing($timeout)
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
