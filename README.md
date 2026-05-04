# Laravel Integrations

[![CI](https://github.com/pocketarc/laravel-integrations/actions/workflows/ci.yml/badge.svg)](https://github.com/pocketarc/laravel-integrations/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/pocketarc/laravel-integrations)](https://packagist.org/packages/pocketarc/laravel-integrations)
[![Total Downloads](https://img.shields.io/packagist/dt/pocketarc/laravel-integrations)](https://packagist.org/packages/pocketarc/laravel-integrations)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-8892BF?logo=php)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A Laravel 11-13 package for production-ready third-party integrations. Provides the connection layer between your app and external APIs.

* Credential management (encrypted at rest)
* API request logging
* Rate limiting
* Retry logic
* Idempotency
* Sync scheduling
* OAuth2
* Health monitoring
* Webhook handling
* ID mapping

## Installation

```bash
composer require pocketarc/laravel-integrations
```

```bash
php artisan vendor:publish --tag=integrations-config
php artisan vendor:publish --tag=integrations-migrations
php artisan migrate
```

## Documentation

Full documentation is available at **[laravel-integrations docs](docs/getting-started/introduction.md)**.

## Official adapters

The companion package [`pocketarc/laravel-integrations-adapters`](https://github.com/pocketarc/laravel-integrations-adapters) provides ready-to-use adapters for GitHub, Zendesk, Stripe, and Postmark.

## Contributing

Bug fixes and maintenance PRs are welcome. For new features, please open an issue first so we can discuss the approach before you put in the work.

## License

MIT. See [LICENSE](LICENSE) for details.
