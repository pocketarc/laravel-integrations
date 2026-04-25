# Postmark adapter

Wraps the [wildbit/postmark-php](https://github.com/ActiveCampaign/postmark-php) SDK. Three jobs: bridges Postmark credentials into Laravel's mail config so `Mail::send()` works without `.env` tokens; ingests Postmark webhooks with both a catch-all event and one typed event per `RecordType`; covers the bounces, suppressions, messages, server-stats, and webhook-endpoint APIs.

Part of the [`pocketarc/laravel-integrations-adapters`](https://github.com/pocketarc/laravel-integrations-adapters) package.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

The adapters package's service provider auto-registers `'postmark'`, so you do not need to add it to `config/integrations.php` unless you want to override the class.

## Setup

```php
$integration = Integration::create([
    'provider' => 'postmark',
    'name' => 'Acme transactional',
    'credentials' => [
        'server_token' => '00000000-0000-0000-0000-000000000000',
        'webhook_username' => 'hook-user',
        'webhook_password' => 'hook-pass',
    ],
    'metadata' => ['message_stream' => 'outbound'],
]);
```

| Credentials                                | Metadata                                                                            |
|--------------------------------------------|-------------------------------------------------------------------------------------|
| `server_token` (string, required) - Server-scoped API token (`X-Postmark-Server-Token`) | `message_stream` (string, optional) - Default stream for sends and stream-scoped resources. Defaults to `outbound`. |
| `webhook_username` (?string) - HTTP Basic username Postmark uses to authenticate webhook deliveries | `server_name` (?string) - Display label for admin UIs; Postmark itself ignores it. |
| `webhook_password` (?string) - HTTP Basic password, paired with `webhook_username`. Validation requires both halves together. | |
| `account_token` (?string) - Account-wide admin API token. Reserved for future admin resources; no v1 method uses it. | |

`webhook_username` and `webhook_password` must be set together, or both left null. `verifyWebhookSignature()` rejects deliveries when either side is missing.

## Mailer credentials bridge

This is the headline feature: Laravel's built-in Postmark mail driver normally reads `services.postmark.token` from `.env`. The adapter's service provider hooks into the container's `mail.manager` resolution and lazily writes the integration's `server_token` and `message_stream` into config the first time the host app actually sends mail.

The auto-wire path applies when **exactly one** active Postmark integration exists. Zero integrations leaves config alone. Two or more integrations skip the auto-wire silently, since the host app needs to pick one per request via the runtime helper.

```php
// Single-integration apps: nothing to do. The next Mail::send() works.
Mail::raw('Hello', fn ($m) => $m->to('user@example.com')->subject('Hi'));
```

### `useForMail()` for multi-tenant apps

When several Postmark integrations exist (one per tenant, one per environment, etc.), call `useForMail()` before sending to point the mailer at a specific integration for the rest of the request:

```php
app(\Integrations\Adapters\Postmark\PostmarkProvider::class)
    ->useForMail($integration);

Mail::to($recipient)->send(new InvoiceMail($order));
```

The helper invalidates the container singleton, the Mail facade's cached resolved instance, and `MailManager`'s per-mailer cache, so anything that already touched `Mail::*` earlier in the request gets a fresh `MailManager` on the next call.

## Webhooks

Postmark does not sign webhooks with HMAC like Stripe. The documented security model is HTTP Basic Auth on the webhook URL (or IP allowlisting). Configure the webhook in Postmark with a username and password and store the same pair in the integration's credentials; `verifyWebhookSignature()` then matches them in constant time on every delivery.

After verification, every webhook fires the catch-all `PostmarkWebhookReceived` event followed by the typed event matching the payload's `RecordType`. Listen for whichever level fits the consumer:

```php
// Catch-all: a single sink for audit/log/debug across every record type.
Event::listen(PostmarkWebhookReceived::class, function (PostmarkWebhookReceived $event) {
    Log::info('postmark webhook', [
        'integration' => $event->integration->id,
        'type' => $event->recordType,
        'message_id' => $event->messageId,
    ]);
});

// Typed: only the records you care about, with a typed payload.
Event::listen(PostmarkBounceReceived::class, function (PostmarkBounceReceived $event) {
    SuppressUser::dispatch($event->bounce->Email);
});
```

Generic always fires first. An unrecognised `RecordType` (a future Postmark addition we have not modelled yet) fires only the generic event and logs a warning, so nothing is silently dropped.

| Event                                  | Payload Data class                          | RecordType            |
|----------------------------------------|---------------------------------------------|-----------------------|
| `PostmarkWebhookReceived`              | raw `array<string, mixed>` payload          | any                   |
| `PostmarkDeliveryReceived`             | `PostmarkDeliveryWebhookData`               | `Delivery`            |
| `PostmarkBounceReceived`               | `PostmarkBounceWebhookData`                 | `Bounce`              |
| `PostmarkOpenReceived`                 | `PostmarkOpenWebhookData`                   | `Open`                |
| `PostmarkClickReceived`                | `PostmarkClickWebhookData`                  | `Click`               |
| `PostmarkSpamComplaintReceived`        | `PostmarkSpamComplaintWebhookData`          | `SpamComplaint`       |
| `PostmarkSubscriptionChangeReceived`   | `PostmarkSubscriptionChangeWebhookData`     | `SubscriptionChange`  |
| `PostmarkInboundReceived`              | `PostmarkInboundWebhookData`                | `Inbound`             |

`webhookDeliveryId()` returns null so the core deduplicates on a payload hash; using just `MessageID` would collapse legitimate distinct events for the same message (multiple opens, multiple clicks).

## Resources

```php
$client = new PostmarkClient($integration);
```

| Resource                          | Method                                                                                 | Description                                                                                                             |
|-----------------------------------|----------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| `$client->bounces()`              | `->get($bounceId)`                                                                     | Get a bounce by id. Returns `?PostmarkBounceData`.                                                                       |
|                                   | `->list($count, $offset, $filters)`                                                    | Page through bounces. Filters: `type`, `inactive`, `emailFilter`, `tag`, `messageId`, `fromdate`, `todate`, `messagestream`. Returns `?PostmarkBounceListResponse`. |
|                                   | `->activate($bounceId)`                                                                | Reactivate a suppressed address (only when Postmark sets `CanActivate=true`). Returns `bool`.                            |
|                                   | `->dump($bounceId)`                                                                    | Raw SMTP source for forensic debugging. Returns `?string`.                                                              |
| `$client->suppressions()`         | `->list($messageStream?, $filters)`                                                    | Per-stream suppression dump. Filters: `suppressionReason`, `origin`, `fromDate`, `toDate`, `emailAddress`. Returns `?PostmarkSuppressionListResponse`. |
|                                   | `->create($emails, $messageStream?)`                                                   | Manually suppress recipients. Returns `bool` after inspecting Postmark's per-recipient `Status`; rejected addresses log a warning and the call returns `false`. |
|                                   | `->delete($emails, $messageStream?)`                                                   | Lift suppressions where Postmark allows it (Customer-origin manual, Recipient-origin hard bounces). Same per-recipient result handling as `create()`. |
| `$client->messages()`             | `->listOutbound($count, $offset, $filters)`                                            | Search outbound messages. Filters: `recipient`, `fromEmail`, `tag`, `subject`, `status`, `fromdate`, `todate`, `metadata`, `messagestream`. Returns `?PostmarkOutboundMessageListResponse`. |
|                                   | `->getOutbound($messageId)`                                                            | Full outbound message details. Returns `?PostmarkOutboundMessageData`.                                                  |
|                                   | `->listInbound($count, $offset, $filters)`                                             | Search inbound messages. Filters: `recipient`, `fromEmail`, `tag`, `subject`, `mailboxHash`, `status`, `fromdate`, `todate`. Returns `?PostmarkInboundMessageListResponse`. |
|                                   | `->getInbound($messageId)`                                                             | Full inbound message details. Returns `?PostmarkInboundMessageData`.                                                    |
| `$client->serverStats()`          | `->overview($fromDate?, $toDate?, $tag?, $messageStream?)`                             | Aggregate outbound stats. Validates dates against `YYYY-MM-DD` locally before forwarding. Returns `?PostmarkOutboundStatsData`. |
| `$client->webhookEndpoints()`     | `->list($messageStream?)`                                                              | List webhook subscriptions. Returns `?PostmarkWebhookEndpointListResponse`. Bypasses the SDK's listing because it strips Triggers/HttpAuth blocks. |
|                                   | `->get($id)`                                                                           | Get a single webhook subscription. Returns `?PostmarkWebhookEndpointData`.                                              |
|                                   | `->create($url, $messageStream?, $httpAuth?, $httpHeaders?, $triggers?)`               | Register a new webhook. Returns `?PostmarkWebhookEndpointData`.                                                         |
|                                   | `->delete($id)`                                                                        | Delete a webhook subscription. Returns `bool`.                                                                          |

All methods route through `Integration::request()` / `requestAs()`, so every call is logged, rate-limited, retried, and tracked against the integration's health. Date filters (`fromdate`/`todate` on stats, etc.) are validated locally with `Carbon::hasFormat()` before the SDK sees them, giving a clear `InvalidArgumentException` instead of a 422 round-trip.

When `messagestream` is omitted from a list call, the resource falls back to the integration's metadata default (`outbound` unless overridden), not to the SDK's hardcoded default. This matters for tenants on a non-default stream like `broadcasts`.

## Health check

`healthCheck()` calls `GET https://api.postmarkapp.com/server` with the integration's `server_token` and returns `false` on any non-2xx response, missing credentials, or transport error.

## Data classes

| Class                                          | Description                                                                                                                                                            |
|------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `PostmarkBounceData`                           | A single bounce. Same field set as `PostmarkBounceWebhookData` but kept separate so the API and webhook representations can drift independently.                       |
| `PostmarkBounceListResponse`                   | Paged bounce list with `TotalCount` and `Bounces` collection.                                                                                                          |
| `PostmarkSuppressionData`                      | One per-stream suppression entry: address, reason, origin, created-at.                                                                                                 |
| `PostmarkSuppressionListResponse`              | Wraps the full per-stream suppression dump (Postmark returns the entire list in one shot).                                                                              |
| `PostmarkOutboundMessageData`                  | Outbound message summary or details with status, recipients, tag.                                                                                                      |
| `PostmarkOutboundMessageListResponse`          | Paged outbound message list.                                                                                                                                           |
| `PostmarkInboundMessageData`                   | Inbound message summary. `To` and `Cc` are raw header strings (parsed `ToFull`/`CcFull` arrays only show up on the per-message details endpoint).                       |
| `PostmarkInboundMessageListResponse`           | Paged inbound message list.                                                                                                                                            |
| `PostmarkOutboundStatsData`                    | Aggregate outbound metrics (sent, bounced, opens, clicks, rates). Rate fields are `float` to preserve fractional values like `10.406`.                                  |
| `PostmarkWebhookEndpointData`                  | A single webhook subscription with `Url`, `MessageStream`, `HttpAuth`, `HttpHeaders`, `Triggers`. Triggers/HttpAuth/HttpHeaders are surfaced as plain arrays.            |
| `PostmarkWebhookEndpointListResponse`          | Wraps the webhook subscriptions list.                                                                                                                                  |
| `PostmarkDeliveryWebhookData`                  | `Delivery` webhook payload: MessageID, Recipient, DeliveredAt, MessageStream, Tag, Details.                                                                            |
| `PostmarkBounceWebhookData`                    | `Bounce` webhook payload: ID, Type (enum), MessageID, Email, BouncedAt, Inactive, CanActivate, etc.                                                                    |
| `PostmarkOpenWebhookData`                      | `Open` webhook payload: MessageID, Recipient, ReceivedAt, FirstOpen, ReadSeconds, UserAgent, Platform.                                                                 |
| `PostmarkClickWebhookData`                     | `Click` webhook payload: MessageID, Recipient, ReceivedAt, OriginalLink, ClickLocation.                                                                                 |
| `PostmarkSpamComplaintWebhookData`             | `SpamComplaint` webhook payload. Same shape as a bounce because Postmark treats spam complaints as bounce-type 100 internally.                                          |
| `PostmarkSubscriptionChangeWebhookData`        | `SubscriptionChange` webhook payload: MessageID, Recipient, ChangedAt, Origin, SuppressSending, SuppressionReason.                                                     |
| `PostmarkInboundWebhookData`                   | `Inbound` webhook payload: MessageID, From, To, Subject, Date (RFC 2822 normalised to ISO in `prepareForPipeline()`), MailboxHash, body fields.                          |

Every Data class stores the original API/webhook response in an `original` array, so consumers can dig into fields the typed properties don't surface (Headers, Attachments, FromFull/ToFull, Geo, Client/OS detail, etc.) without us having to anticipate every use case.

## Enums

| Enum                            | Values                                                                                                                                                                                                                                              |
|---------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `PostmarkBounceType`            | All 22 of Postmark's bounce types: `HardBounce`, `SoftBounce`, `Transient`, `SpamComplaint`, `SpamNotification`, `Unsubscribe`, `Subscribe`, `AutoResponder`, `AddressChange`, `DnsError`, `OpenRelayTest`, `Unknown`, `VirusNotification`, `ChallengeVerification`, `BadEmailAddress`, `ManuallyDeactivated`, `Unconfirmed`, `Blocked`, `SMTPApiError`, `InboundError`, `DMARCPolicy`, `TemplateRenderingFailed`. Names match the API's `Type` field exactly. |
| `PostmarkWebhookRecordType`     | `Delivery`, `Bounce`, `Open`, `Click`, `SpamComplaint`, `SubscriptionChange`, `Inbound`. The seven values Postmark sends on webhook payloads. The provider matches exhaustively over this enum, so adding a new case here will surface the missing dispatch arm at static-analysis time. |
