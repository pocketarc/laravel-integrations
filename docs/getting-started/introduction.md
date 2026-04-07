# Introduction

If you're integrating with third-party APIs, you've probably dealt with the same problems over and over: where do credentials live, how do you log requests, what happens when the API goes down, how do you retry safely without hammering their rate limits?

Laravel Integrations is a Laravel 11-13 package that handles all of that. You define a provider class, create an integration record with credentials, and every API call you make through it gets logging, caching, rate limiting, retries, and health tracking for free.

## How it works

You define a **provider** class that describes how your app talks to an external service (credentials, validation rules, optional capabilities). Then you create an **integration** record that holds a specific set of credentials for that provider.

```php
// Every API call is logged, rate-limited, retried, and health-tracked
$tickets = $integration->requestAs(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    responseClass: TicketListResponse::class,
    callback: fn () => Http::get($url),
);
```

Providers opt into additional capabilities by implementing interfaces: `HasScheduledSync` for automated sync scheduling, `HasOAuth2` for OAuth2 flows, `HandlesWebhooks` for inbound webhooks, and more. See [Providers](/core-concepts/providers) for the full list.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- [Spatie Laravel Data](https://spatie.be/docs/laravel-data/v4/introduction) v4+ (for typed credentials and responses)

## Official adapters

The companion package [`pocketarc/laravel-integrations-adapters`](https://github.com/pocketarc/laravel-integrations-adapters) has ready-to-use adapters for GitHub and Zendesk, with more planned. See the [Adapters](/adapters/overview) section.
