# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`chrismou/laravel-symfony-notifier` integrates Symfony Notifier with Laravel 12/13 on PHP 8.2+:
`chat` and `sms` notification channels, config-named transports, Chatter/Texter bindings, and a
Symfony `HttpClientInterface` backed by Laravel's HTTP client so every bridge's requests can be
faked with `Http::fake()`. Not yet tagged; breaking changes are allowed but go in the CHANGELOG.

## Commands

```bash
composer install

composer test              # phpunit (Unit + Feature suites, Orchestra Testbench)
composer lint              # pint --test (PSR-12 + ordered/imported symbols)
composer lint:fix          # pint
composer analyse           # phpstan level 8 over src/ and tests/, no baseline, no ignores

vendor/bin/phpunit --filter testSlack
```

CI runs lint, analyse and test on PHP 8.2-8.5 against Laravel 12 and 13 (Laravel 13 from PHP 8.3)
and Symfony 7.4 and 8 (Symfony 8 from PHP 8.4), plus one `--prefer-lowest` job. To check the
lowest stack locally, copy the package to a scratch directory and run
`php8.2 composer update --prefer-lowest --prefer-stable` there. The Teams bridge is pinned to
releases that keep a Workflows URL's query string (7.4.14, 8.0.14, 8.1.1).

## Architecture

- `NotifierServiceProvider` merges `config/notifier.php`, registers singletons for
  `Http\LaravelHttpClient`, `LaravelEventDispatcher`, `TransportFactory`, `TransportRegistry`,
  `ChatterInterface` and `TexterInterface`, and extends the `ChannelManager` with `chat` and `sms`.
  It deliberately does not bind Symfony's `HttpClientInterface` globally.
- `TransportFactory::fromDsn()` splits failover (`||`) and round-robin (`&&`) DSNs itself and
  builds each part with Symfony's static `Transport::fromDsn()`, wrapping them in the public
  `FailoverTransport` / `RoundRobinTransport` (Symfony's own split fails with "Cannot rewind a
  generator"). It enforces `notifier.allowed_schemes` and rethrows Symfony's DSN exceptions as
  `InvalidDsnException` with credentials removed (`Support\Redactor`), without chaining them.
- `TransportRegistry` wraps each configured DSN in a `LazyTransport`, so a transport is only built
  when a message uses it; empty DSNs are skipped, non-strings throw `InvalidDsnException`, and
  `MissingTransportException` is thrown (when sending) if none are configured. The Chatter and
  Texter bindings also wrap the registry in a `LazyTransport`.
- `Channels\NotifierChannel` resolves a route (TransportInterface, DSN containing `://`, a
  configured transport name, or null for the configured transports). A notifiable that defines
  its own route method (or an on-demand route) but returns null or '' is skipped, so tenants never
  fall back to the app's transports. `ChatChannel` and `SmsChannel` validate the message from
  `toChat()` / `toSms()` and send it, returning Notifier's `SentMessage`.
- `LaravelEventDispatcher` resolves Laravel's dispatcher per event, so `Event::fake()` applies
  after transports were built.
- DSN arguments carry `#[SensitiveParameter]`; keep it on any new method that receives one.
- The HTTP adapter deliberately covers only what Notifier bridges use, not the whole Symfony
  HttpClient contract. Check a new bridge's `client->request()` options before relying on it.
- `Http\RequestOptions` validates and translates the supported options (`auth_basic`,
  `auth_bearer`, `query`, `headers`, `body` as a string or form array, `json`, `user_data`,
  `max_redirects`, `timeout`, `max_duration`) into a `PreparedRequest`. Only options that were
  passed are translated, so Laravel's defaults and `Http::globalOptions()` otherwise apply. Unknown
  options, and any other option at a non-default value, throw
  `Http\Exception\InvalidArgumentException` (a TransportExceptionInterface).
- `Http\LaravelHttpClient` sends synchronously through a `PendingRequest` from the container's HTTP
  `Factory` (resolved per request so fakes apply). Headers are set by request middleware on the
  PSR-7 request, because Laravel defaults to a JSON content type and merges header names
  case-sensitively; only Laravel's default/placeholder Content-Type is stripped. Connection
  failures, Guzzle errors and HttpClient TransportExceptions thrown by `withPendingRequest()`
  callbacks are deferred into the response (StrayRequestException is rethrown), with URLs in the
  message redacted. `stream()` throws `BadMethodCallException`.
- `Http\LaravelHttpResponse` implements Symfony's response methods over Laravel's response. It
  does not throw unchecked statuses from `__destruct()`: bridges always check the status. Redirect
  info comes from Guzzle's redirect history, which is tracked when `max_redirects` is passed.
  Exception messages show URLs as scheme and host only.

## Tests

- `tests/Unit/Http` covers option translation, request building, responses and errors.
- `tests/Feature/BridgeCompatibilityTest` sends through the real Slack, Discord, Telegram, Teams,
  Rocket.Chat and Twilio bridges (dev dependencies) against `Http::fake()`.
- `tests/Feature/ChannelsTest` and `ServiceProviderTest` cover routing, config, bindings and events.
- `tests/TestCase` calls `Http::preventStrayRequests()`, so every request must be faked.
- Assert headers via `$request->toPsrRequest()->getHeader()`: Laravel 12's `Request::header()` is
  case-sensitive.

## Conventions

- `declare(strict_types=1)`, `final` classes, constructor promotion, docblocks on every method,
  array shape PHPDoc for arrays. No typed class constants (PHP 8.2 support).
- Pint config: PSR-12, alphabetical imports, global classes imported, no unused imports.
- Use a plain hyphen `-` in all text; never Unicode dashes.
- README.md and CHANGELOG.md (Keep a Changelog, `[Unreleased]` section above dated releases)
  document the public API and must be updated alongside API changes.
