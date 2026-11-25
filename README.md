# Laravel Symfony Notifier

[Symfony Notifier](https://symfony.com/doc/current/notifier.html) for Laravel. You get `chat` and `sms` notification channels that work with Notifier's bridges, each configured with a single DSN string. The Slack, Discord, Telegram, Microsoft Teams, Rocket.Chat and Twilio bridges are tested with this package. Other bridges work if they only use the request options the HTTP client supports (see [What the HTTP client supports](#what-the-http-client-supports)).

Every request the bridges make goes through **Laravel's HTTP client** rather than Symfony's. That means:

- `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` work for notifications, the same as for the rest of your app.
- Global HTTP middleware and `Http::globalOptions()` apply.
- Laravel's `RequestSending`, `ResponseReceived` and `ConnectionFailed` events fire, so tools like Telescope, Pulse and Nightwatch see the requests.

```php
class SiteDown extends Notification
{
    public function via(object $notifiable): array
    {
        return ['chat'];
    }

    public function toChat(object $notifiable): ChatMessage
    {
        return new ChatMessage('example.com is down');
    }
}

Notification::route('chat', 'slack://xoxb-token@default?channel=C0123')->notify(new SiteDown);
```

## Requirements

- PHP 8.2 or later
- Laravel 12 or 13
- Symfony Notifier 7.4 or 8.x, plus a bridge for each service you send to
- For Microsoft Teams Workflows webhooks, `symfony/microsoft-teams-notifier` 7.4.14, 8.0.14 or 8.1.1 or later. Earlier releases drop the URL's query string, which holds the workflow's signature.

## Installation

```bash
composer require chrismou/laravel-symfony-notifier
```

Then install a bridge for each service you use:

```bash
composer require symfony/slack-notifier symfony/telegram-notifier symfony/twilio-notifier
```

The service provider is auto-discovered. To publish the config file:

```bash
php artisan vendor:publish --tag=notifier-config
```

> The bridges depend on `symfony/http-client`, so Composer installs it. This package never uses it: every transport it builds sends through Laravel's client instead (see [What the HTTP client supports](#what-the-http-client-supports)).

The [Symfony Notifier documentation](https://symfony.com/doc/current/notifier.html#chat-channel) lists every bridge, its package and its DSN format.

## Configuration

`config/notifier.php` names transports for the whole app, as DSNs:

```php
'chatter_transports' => [
    'slack' => env('SLACK_DSN'),         // slack://xoxb-TOKEN@default?channel=C0123
    'telegram' => env('TELEGRAM_DSN'),   // telegram://BOT_TOKEN@default?channel=CHAT_ID
],

'texter_transports' => [
    'twilio' => env('TWILIO_DSN'),       // twilio://SID:TOKEN@default?from=FROM
],
```

Entries with an empty DSN are skipped, so an unset environment variable just leaves that transport out. These features from Symfony also work:
- failover DSNs (`slack://... || telegram://...`) and round-robin DSNs (`slack://... && telegram://...`)
- `null://null`, which sends nothing and is useful for local development

You don't need any config at all if every notifiable routes to its own DSN (see below).

When DSNs come from your users, limit which bridges they can use with `allowed_schemes`. Some bridges send requests to a host the DSN chooses.

```php
'allowed_schemes' => ['slack', 'discord', 'telegram'],
```

A DSN with any other scheme throws an `InvalidDsnException`.

## Chat notifications

Send on the `chat` channel. `toChat()` returns either a plain string or a `ChatMessage`. A `ChatMessage` can carry the bridge's own options, such as Slack blocks, Discord embeds, Telegram buttons or a Teams card:

```php
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\Message\ChatMessage;

public function via(object $notifiable): array
{
    return ['mail', 'chat'];
}

public function toChat(object $notifiable): ChatMessage
{
    return (new ChatMessage('example.com is down'))
        ->options((new SlackOptions)->block((new SlackSectionBlock)->text('*example.com* is down')));
}
```

If `toChat()` returns `null`, nothing is sent on the channel for that notifiable.

### Where chat messages go

The channel asks the notifiable for its `chat` route, which can be any of these:

| Route | What happens |
|---|---|
| A DSN, e.g. `slack://xoxb-...@default?channel=C0123` | A transport is built from it and the message is sent there |
| A transport name, e.g. `'telegram'` | The transport with that name in `notifier.chatter_transports` is used |
| A `TransportInterface` | Used as it is |
| `null` or `''`, from a notifiable with no `routeNotificationForChat()` method | The configured transports are used: the one named by the message's `->transport('name')`, otherwise the first that supports the message |
| `null` or `''`, from a `routeNotificationForChat()` method or an on-demand route | Nothing is sent. A tenant that hasn't connected a channel never falls back to the app's own transports. |

Per-user or per-team DSNs fit naturally. For example, each user can connect their own Slack or Discord:

```php
class Team extends Model
{
    use Notifiable;

    protected $casts = ['chat_dsn' => 'encrypted'];

    public function routeNotificationForChat(): ?string
    {
        return $this->chat_dsn;
    }
}
```

On-demand notifications work the same way:

```php
Notification::route('chat', 'discord://TOKEN@default?webhook_id=ID')->notify(new SiteDown);
```

## SMS notifications

Send on the `sms` channel. `toSms()` returns the text, or an `SmsMessage` for bridge options. The route is the phone number:

```php
public function via(object $notifiable): array
{
    return ['sms'];
}

public function toSms(object $notifiable): string
{
    return 'example.com is down';
}

public function routeNotificationForSms(): ?string
{
    return $this->phone;
}
```

The message goes through the configured `texter_transports`. To choose the transport per notifiable, return an array with a DSN, a transport name or a `TransportInterface`:

```php
public function routeNotificationForSms(): array
{
    return ['phone' => $this->phone, 'transport' => $this->tenant->sms_dsn];
}
```

When `toSms()` returns an `SmsMessage`, the route's phone number, if there is one, replaces the message's.

A notifiable whose `routeNotificationForSms()` returns no phone number is skipped when `toSms()` returns text, as with Laravel's own SMS channels. A route whose `transport` is set but empty, such as a tenant with no SMS provider, is skipped too.

## Chatter and Texter

To send without a notification, inject Symfony's `ChatterInterface` or `TexterInterface`. They send through the configured transports:

```php
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

public function __invoke(ChatterInterface $chatter): void
{
    $chatter->send((new ChatMessage('Deploy finished'))->transport('slack'));
}
```

Use `TransportFactory` to build a transport from any DSN:

```php
use Chrismou\LaravelSymfonyNotifier\TransportFactory;

app(TransportFactory::class)->fromDsn($dsn)->send(new ChatMessage('Connected'));
```

## Events

Notifier's own events are dispatched through Laravel's event dispatcher:
- `MessageEvent` before each send
- `SentMessageEvent` after a successful send
- `FailedMessageEvent` when a send fails

You can listen to them like any other Laravel event:

```php
use Symfony\Component\Notifier\Event\FailedMessageEvent;

Event::listen(function (FailedMessageEvent $event) {
    Log::warning('Chat message failed', ['error' => $event->getError()->getMessage()]);
});
```

Keep these listeners synchronous (not `ShouldQueue`). The events hold the message and, for failures, the exception, which don't serialise cleanly and can contain credentials.

The channels also return Notifier's `SentMessage`, which becomes the `response` on Laravel's `NotificationSent` event. Its `getMessageId()` gives the provider's ID for the message.

## Testing

Requests go through Laravel's HTTP client, so you can fake the provider's API and assert on what the bridge sent:

```php
Http::fake([
    'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C0123', 'ts' => '1.1']),
]);

$team->notify(new SiteDown);

Http::assertSent(fn (Request $request) => $request['channel'] === 'C0123'
    && $request->header('Authorization') === ['Bearer xoxb-token']);
```

With `Http::preventStrayRequests()`, a test that forgets to fake a provider fails instead of posting to a real Slack channel. `Notification::fake()` still works as usual when you only want to assert that a notification was sent.

## Errors

When a provider rejects a message or can't be reached, the bridge throws a Notifier `TransportExceptionInterface`. That covers a revoked token, a deleted channel or a timeout. Queued notifications are retried as usual. `$exception->getResponse()` gives the HTTP response, so you can tell a permanent failure (such as a 401 or 404) from a temporary one.

The package's own exceptions all implement `Chrismou\LaravelSymfonyNotifier\Exception\ExceptionInterface`:

- `InvalidRouteException`: a route that isn't a DSN, a configured transport name or a transport, or SMS text with no phone number for a notifiable without a `routeNotificationForSms()` method.
- `InvalidDsnException`: a DSN that's malformed, uses a scheme `allowed_schemes` doesn't allow, or needs a bridge that isn't installed. Its message shows only the DSN's scheme and host, never its credentials.
- `InvalidMessageException`: no `toChat()`/`toSms()` method, or one that returns something other than a message.
- `MissingTransportException`: a message would use the configured transports, but none are configured. This is thrown when sending, so injecting `ChatterInterface` or `TexterInterface` never fails.

Configured transports are built the first time a message uses them, so a wrong DSN only breaks messages sent through that transport.

## Customising requests

The bridges send through `Chrismou\LaravelSymfonyNotifier\Http\LaravelHttpClient`. Its `withPendingRequest()` gives you each request's `PendingRequest` before it's sent, for anything Symfony's options can't express: retries, middleware or raw Guzzle options. To apply it to every transport the package builds, extend the container binding:

```php
use Chrismou\LaravelSymfonyNotifier\Http\LaravelHttpClient;
use Illuminate\Http\Client\PendingRequest;

$this->app->extend(LaravelHttpClient::class, fn (LaravelHttpClient $client) => $client->withPendingRequest(
    fn (PendingRequest $request, string $method, string $url, array $options) => $request->retry(2, 500)
));
```

Register this in a service provider's `register()` method, before anything resolves `TransportFactory`, which keeps the client it was built with.

### Protecting against requests to private addresses (SSRF)

You need this when users can enter their own hosts, such as a Teams Workflows URL, or a Rocket.Chat or Mattermost server. The package doesn't add any itself. Refuse private and reserved addresses, pin the connection to the addresses you checked so DNS can't change in between, and don't follow redirects:

```php
use Chrismou\LaravelSymfonyNotifier\Http\Exception\TransportException;

$this->app->extend(LaravelHttpClient::class, fn (LaravelHttpClient $client) => $client->withPendingRequest(
    function (PendingRequest $request, string $method, string $url) {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? (str_starts_with($url, 'https:') ? 443 : 80);
        $addresses = gethostbynamel($host) ?: [];

        foreach ($addresses ?: [''] as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new TransportException("{$host} does not resolve to a public address.");
            }
        }

        return $request->withoutRedirecting()->withOptions([
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $addresses)]],
        ]);
    }
));
```

`gethostbynamel()` only returns IPv4 addresses. Use `dns_get_record()` as well if your servers make IPv6 requests. The exception is reported through the response, so the bridge reports the host as unreachable.

`allowed_schemes` matters here too: bridges that don't use the HTTP client, such as ones built on AsyncAws, aren't covered by this check.

### What the HTTP client supports

`LaravelHttpClient` implements Symfony's `HttpClientInterface`, but it covers what Notifier bridges use rather than everything the contract allows:

- **Request options:** `auth_basic`, `auth_bearer`, `query`, `headers`, `body` (a string or a form array), `json`, `user_data`, `max_redirects`, `timeout` and `max_duration`. Any other option set to a non-default value throws a `TransportExceptionInterface`; it's never silently ignored. That includes file uploads, so bridge features that upload files (such as Telegram photos or Mastodon media from a local path) aren't supported, nor are bridges that set `base_uri`.
- **Requests are synchronous.** The response is complete when `request()` returns, though a connection failure is still thrown from the response's methods, as with Symfony's lazy responses. `stream()` isn't supported.
- **Laravel's defaults apply to options you don't pass:** a 30 second timeout, a 10 second connect timeout, Guzzle's limit of 5 redirects, and any `Http::globalOptions()`.
- **Redirects are only reported when `max_redirects` is passed.** Then `getInfo('url')` is the final URL and `getInfo('redirect_count')` counts the redirects; otherwise they report the requested URL and 0.
- **Error statuses are only thrown when checked.** Unlike Symfony's client, a 3xx/4xx/5xx response that nothing checks isn't thrown when the response is destroyed. Every bridge checks the status, so this only matters if you use the client directly.

The package doesn't bind `HttpClientInterface` itself, so other code that expects Symfony's client is unaffected.

## Security notes

- DSNs contain credentials. Store them encrypted, for example with the `encrypted` cast, and keep them out of logs. The package marks DSN arguments with `#[SensitiveParameter]` and removes credentials from the exception messages it builds.
- Several chat APIs put tokens in the request URL (Telegram, Discord and Teams webhooks) or headers (Slack). Laravel's HTTP client events carry the full request, so tools that record them, such as Telescope, see the tokens. With Telescope, add `Authorization` to `hidden_request_headers` and keep it off in production, or filter out requests to these hosts.
- Don't use a `TransportInterface` as the route of a queued notification. It would be serialised into the queue with its credentials and HTTP client.
- An on-demand route (`Notification::route('chat', $dsn)`) is serialised into the queue payload when the notification is queued. Use `ShouldBeEncrypted` on queued notifications sent this way, or route through a notifiable model so the DSN is only read when the notification is sent.

## Channel names

The package registers the `chat` and `sms` notification channels, replacing any channels already registered under those names, such as another package's `sms` channel. Use the class names (`ChatChannel::class` and `SmsChannel::class`) in `via()` if you need to keep both.

## Licence

MIT. See [LICENSE](LICENSE).
