# Health notifications

The package includes a `SendHealthNotification` listener that dispatches an `IntegrationHealthStatusNotification` when health status changes.

## Publishing notification stubs

```bash
php artisan vendor:publish --tag=integrations-notifications
```

This publishes the notification class to your app, where you can customize the channels (Slack, email, etc.) and message content.

## How it works

The `SendHealthNotification` listener is automatically registered and listens for `IntegrationHealthChanged` events. When triggered, it sends the `IntegrationHealthStatusNotification` to the configured notifiable.

## Customization

After publishing, modify the notification class to:

- Change notification channels (mail, Slack, database, etc.)
- Customize the message content and formatting
- Add conditional logic (e.g. only notify on `failing`, not `degraded`)
- Route to different recipients based on the integration or provider
