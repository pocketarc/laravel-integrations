<?php

declare(strict_types=1);

namespace Integrations\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Integrations\Enums\HealthStatus;
use Integrations\Models\Integration;

class IntegrationHealthStatusNotification extends Notification
{
    public function __construct(
        public readonly Integration $integration,
        public readonly HealthStatus $previousStatus,
        public readonly HealthStatus $newStatus,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = new MailMessage;
        $label = "'{$this->integration->name}' ({$this->integration->provider})";

        if ($this->newStatus === HealthStatus::Healthy) {
            $message->subject("Integration {$label} recovered")
                ->line("The integration {$label} has recovered and is now healthy.")
                ->line("Previous status: {$this->previousStatus->value}.");
        } else {
            $message->subject("Integration {$label} is {$this->newStatus->value}")
                ->line("The integration {$label} health has changed from {$this->previousStatus->value} to {$this->newStatus->value}.")
                ->line("Consecutive failures: {$this->integration->consecutive_failures}.");
        }

        return $message;
    }
}
