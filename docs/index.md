---
layout: home

hero:
  name: Laravel Integrations
  text: API integrations without the boilerplate
  tagline: Credentials, logging, retries, rate limiting, health monitoring, OAuth2, webhooks, and sync scheduling. Handled.
  actions:
    - theme: brand
      text: Get Started
      link: /getting-started/introduction
    - theme: alt
      text: View on GitHub
      link: https://github.com/pocketarc/laravel-integrations

features:
  - title: Credential management
    details: Store API tokens and secrets safely. Encrypted at rest, with optional typed access via Spatie Data classes.
    link: /core-concepts/credentials
  - title: API request logging
    details: Every API call is logged automatically with request/response data, timing, status codes, and errors.
    link: /core-concepts/making-requests
  - title: Rate limiting
    details: Don't get blocked by API providers. Per-provider sliding window counters with configurable wait-or-throw behavior.
    link: /core-concepts/rate-limiting
  - title: Retry logic
    details: Transient failures are retried automatically. Respects Retry-After headers and walks SDK exception chains.
    link: /core-concepts/retries
  - title: Sync scheduling
    details: Keep your data in sync. Queued jobs with health-based backoff, incremental sync, and overlap protection.
    link: /features/scheduled-syncs
  - title: OAuth2
    details: Authorization flows without the pain. Automatic token refresh with concurrent worker protection.
    link: /features/oauth2
  - title: Health monitoring
    details: Failing APIs don't take your app down. Circuit breaker with degraded, failing, and disabled states.
    link: /core-concepts/health-monitoring
  - title: Webhook handling
    details: Receive webhooks reliably. Signature verification, event routing, deduplication, and async processing.
    link: /features/webhooks
  - title: ID mapping
    details: Track relationships between external provider IDs and your internal models. Scoped per-integration.
    link: /features/id-mapping
---
