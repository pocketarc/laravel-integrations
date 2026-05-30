<?php

declare(strict_types=1);

namespace Integrations\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Integrations\Models\Integration;

/**
 * Sent when an integration's circuit breaker opens or closes. Wired up by the
 * publishable App\Listeners\SendCircuitNotification stub; the package ships this
 * notification so the stub has something to reference out of the box.
 */
class CircuitStateNotification extends Notification
{
    use Queueable;

    /**
     * @param  'opened'|'closed'  $state
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly string $state,
        public readonly string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = sprintf(
            "Circuit %s for integration '%s'",
            mb_strtoupper($this->state),
            $this->integration->name,
        );

        return (new MailMessage)
            ->subject($subject)
            ->line(sprintf(
                "The circuit breaker for '%s' (%s) is now %s.",
                $this->integration->name,
                $this->integration->provider,
                $this->state,
            ))
            ->line('Reason: '.$this->reason);
    }
}
