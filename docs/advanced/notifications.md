# Health notifications

The package includes a `SendHealthNotification` listener that dispatches an `IntegrationHealthStatusNotification` when health status changes.

## Publishing notification stubs

```bash
php artisan vendor:publish --tag=integrations-notifications
```

This publishes the notification class to your app, where you can customize the channels (Slack, email, etc.) and message content.

## How it works

`SendHealthNotification` listens for `IntegrationHealthChanged` and sends `IntegrationHealthStatusNotification` to the notifiables you pass it. Register it yourself after publishing, with the recipients you want. The package publishes the stub without registering it, because the recipients are specific to your application.

```php
$listener = new SendHealthNotification([$opsTeam]);

Event::listen(IntegrationHealthChanged::class, $listener->handle(...));
```

The listener takes its notifiables as a constructor argument, so it is built first and its `handle` method registered against the event. `Event::listen()` infers the event from a closure's type hint, but not from a listener instance.

## Notifying on sync staleness

`IntegrationHealthChanged` covers the provider's API failing. It does not cover an integration that has stopped syncing while its API calls still succeed, because its health stays `healthy` throughout. For that, listen for [`SyncBecameStale`](/reference/events#syncbecamestale) and its counterpart [`SyncStalenessRecovered`](/reference/events#syncstalenessrecovered):

```php
Event::listen(function (SyncBecameStale $event): void {
    Notification::send($opsTeam, new SyncStaleNotification($event->integration));
});
```

Each fires once per episode rather than on every scheduler tick, so one alert is raised and one resolution follows it. See [sync staleness](/core-concepts/health-monitoring#sync-staleness).

## Customization

After publishing, modify the notification class to:

- Change notification channels (mail, Slack, database, etc.)
- Customize the message content and formatting
- Add conditional logic (e.g. only notify on `failing`, not `degraded`)
- Route to different recipients based on the integration or provider
