# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `chat` and `sms` notification channels that send `toChat()` / `toSms()` messages through any
  Symfony Notifier bridge. A route can be a DSN, the name of a configured transport or a
  `TransportInterface`; SMS routes are a phone number, optionally with a transport.
- `config/notifier.php` with named `chatter_transports` and `texter_transports`, and
  `ChatterInterface` / `TexterInterface` bindings that send through them.
- `TransportFactory`, which builds transports from DSNs, including failover (`||`) and round-robin
  (`&&`) DSNs, which Symfony's `Transport::fromDsn()` cannot build. DSN errors are rethrown as
  `InvalidDsnException` without the DSN's credentials.
- `notifier.allowed_schemes`, to limit which bridges DSNs (including users' own) can use.
- Configured transports, and the `ChatterInterface` / `TexterInterface` bindings, are built when a
  message first needs them, so one wrong DSN only affects messages sent through it.
- A notifiable whose own chat or SMS route is empty is skipped instead of falling back to the
  configured transports.
- `Http\LaravelHttpClient`, a Symfony `HttpClientInterface` that sends through Laravel's HTTP client,
  so `Http::fake()`, stray request prevention, global middleware and options, and Laravel's HTTP
  client events apply to every bridge. It covers the request options and response methods the
  bridges use; other options throw, as the contract requires. `withPendingRequest()` gives access
  to each request's `PendingRequest`. URLs in its error messages are reduced to the scheme and
  host, as chat apps put tokens in them.
- Notifier's `MessageEvent`, `SentMessageEvent` and `FailedMessageEvent` are dispatched through
  Laravel's event dispatcher.
