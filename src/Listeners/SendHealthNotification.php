<?php

declare(strict_types=1);

namespace Integrations\Listeners;

use Illuminate\Support\Facades\Notification;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Notifications\IntegrationHealthStatusNotification;

class SendHealthNotification
{
    /**
     * @var list<mixed>
     */
    protected array $notifiables = [];

    /**
     * @param  list<mixed>  $notifiables  Notifiable instances or routing (e.g. AnonymousNotifiable).
     */
    public function __construct(array $notifiables = [])
    {
        $this->notifiables = $notifiables;
    }

    public function handle(IntegrationHealthChanged $event): void
    {
        if ($this->notifiables === []) {
            return;
        }

        Notification::send(
            $this->notifiables,
            new IntegrationHealthStatusNotification($event->integration, $event->previousStatus, $event->newStatus),
        );
    }
}
