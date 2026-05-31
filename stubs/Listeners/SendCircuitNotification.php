<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Integrations\Events\CircuitClosed;
use Integrations\Events\CircuitOpened;
use Integrations\Models\Integration;
use Integrations\Notifications\CircuitStateNotification;

class SendCircuitNotification
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

    public function handleCircuitOpened(CircuitOpened $event): void
    {
        $this->notify($event->integration, 'opened', $event->reason);
    }

    public function handleCircuitClosed(CircuitClosed $event): void
    {
        $this->notify($event->integration, 'closed', $event->reason);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            CircuitOpened::class => 'handleCircuitOpened',
            CircuitClosed::class => 'handleCircuitClosed',
        ];
    }

    private function notify(Integration $integration, string $state, string $reason): void
    {
        if ($this->notifiables === []) {
            return;
        }

        Notification::send(
            $this->notifiables,
            new CircuitStateNotification($integration, $state, $reason),
        );
    }
}
