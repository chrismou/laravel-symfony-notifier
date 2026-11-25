<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Feature;

use Chrismou\LaravelSymfonyNotifier\Exception\InvalidDsnException;
use Chrismou\LaravelSymfonyNotifier\Exception\MissingTransportException;
use Chrismou\LaravelSymfonyNotifier\Http\LaravelHttpClient;
use Chrismou\LaravelSymfonyNotifier\Tests\TestCase;
use Chrismou\LaravelSymfonyNotifier\TransportFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Event\MessageEvent;
use Symfony\Component\Notifier\Event\SentMessageEvent;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ServiceProviderTest extends TestCase
{
    public function testTheConfigIsMergedWithEmptyTransportLists(): void
    {
        $this->assertSame([], config('notifier.chatter_transports'));
        $this->assertSame([], config('notifier.texter_transports'));
    }

    public function testTheChatterSendsThroughTheConfiguredTransports(): void
    {
        config(['notifier.chatter_transports' => ['slack' => 'slack://xoxb-app@default?channel=C1']]);
        Http::fake(['https://slack.com/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '2.2'])]);

        $sent = resolve(ChatterInterface::class)->send(new ChatMessage('Hello'));

        $this->assertSame('2.2', $sent?->getMessageId());
        Http::assertSent(static fn (Request $request): bool => $request['channel'] === 'C1' && $request['text'] === 'Hello');
    }

    public function testTheTexterSendsThroughTheConfiguredTransports(): void
    {
        config(['notifier.texter_transports' => ['twilio' => 'twilio://AC1:token@default?from=%2B447700900001']]);
        Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM9'], 201)]);

        $sent = resolve(TexterInterface::class)->send(new SmsMessage('+447700900000', 'Hello'));

        $this->assertSame('SM9', $sent?->getMessageId());
    }

    public function testTheChatterCanBeResolvedWithoutTransportsButSendingExplainsWhatIsMissing(): void
    {
        $chatter = resolve(ChatterInterface::class);

        $this->expectException(MissingTransportException::class);
        $this->expectExceptionMessage('No transports are configured in notifier.chatter_transports');

        $chatter->send(new ChatMessage('Hello'));
    }

    public function testAFailoverDsnFallsBackToTheNextTransportWhenTheFirstFails(): void
    {
        Http::fake([
            'https://slack.com/*' => Http::response(['ok' => false, 'error' => 'channel_not_found']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
        ]);

        $sent = resolve(TransportFactory::class)
            ->fromDsn('slack://xoxb-token@default?channel=C1 || telegram://123:ABC@default?channel=-100')
            ->send(new ChatMessage('Hello'));

        $this->assertSame('5', (string) $sent?->getMessageId());
        Http::assertSentCount(2);
    }

    public function testTheNullTransportSendsNothing(): void
    {
        config(['notifier.chatter_transports' => ['local' => 'null://null']]);

        resolve(ChatterInterface::class)->send(new ChatMessage('Hello'));

        Http::assertNothingSent();
    }

    public function testTransportsSendThroughLaravelsHttpClientSoStrayRequestsArePrevented(): void
    {
        $transport = resolve(TransportFactory::class)->fromDsn('slack://xoxb-token@default?channel=C1');

        $this->expectException(StrayRequestException::class);

        $transport->send(new ChatMessage('Hello'));
    }

    public function testSymfonysHttpClientInterfaceIsNotBoundGlobally(): void
    {
        $this->assertFalse(app()->bound(HttpClientInterface::class));
        $this->assertInstanceOf(LaravelHttpClient::class, resolve(LaravelHttpClient::class));
    }

    public function testTheConfigCanBePublished(): void
    {
        $this->assertArrayHasKey(
            dirname(__DIR__, 2).'/src/../config/notifier.php',
            ServiceProvider::pathsToPublish(null, 'notifier-config'),
        );
    }

    public function testARoundRobinDsnAlternatesBetweenItsTransports(): void
    {
        Http::fake([
            'https://slack.com/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '1.1']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
        ]);

        $transport = resolve(TransportFactory::class)->fromDsn('slack://xoxb-token@default?channel=C1 && telegram://123:ABC@default?channel=-100');
        $sent = [(string) $transport->send(new ChatMessage('One'))?->getMessageId(), (string) $transport->send(new ChatMessage('Two'))?->getMessageId()];

        sort($sent);
        $this->assertSame(['1.1', '5'], $sent);
    }

    public function testAnInvalidDsnIsReportedWithoutItsCredentials(): void
    {
        try {
            resolve(TransportFactory::class)->fromDsn('twilio://ACsecretaccount@default?from=%2B447700900001');
            $this->fail('No exception was thrown.');
        } catch (InvalidDsnException $exception) {
            $this->assertStringContainsString('twilio://default/[redacted]', $exception->getMessage());
            $this->assertStringNotContainsString('ACsecretaccount', (string) $exception);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testADsnForABridgeThatIsNotInstalledIsReported(): void
    {
        $this->expectException(InvalidDsnException::class);
        $this->expectExceptionMessage('"notinstalled" scheme is not supported');

        resolve(TransportFactory::class)->fromDsn('notinstalled://token@default');
    }

    public function testOnlyAllowedSchemesCanBeBuilt(): void
    {
        config(['notifier.allowed_schemes' => ['slack']]);
        app()->forgetInstance(TransportFactory::class);

        resolve(TransportFactory::class)->fromDsn('slack://xoxb-token@default?channel=C1');

        $this->expectException(InvalidDsnException::class);
        $this->expectExceptionMessage('The "telegram" scheme is not allowed by notifier.allowed_schemes.');

        resolve(TransportFactory::class)->fromDsn('telegram://123:ABC@default?channel=-100');
    }

    public function testAWrongDsnOnlyBreaksMessagesSentThroughIt(): void
    {
        config(['notifier.chatter_transports' => [
            'broken' => 'notinstalled://token@default',
            'slack' => 'slack://xoxb-app@default?channel=C1',
        ]]);
        Http::fake(['https://slack.com/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '2.2'])]);

        $sent = resolve(ChatterInterface::class)->send((new ChatMessage('Hello'))->transport('slack'));

        $this->assertSame('2.2', $sent?->getMessageId());
    }

    public function testAConfiguredDsnThatIsNotAStringIsReportedByName(): void
    {
        config(['notifier.chatter_transports' => ['slack' => ['not', 'a', 'dsn'], 'unset' => false]]);

        $this->expectException(InvalidDsnException::class);
        $this->expectExceptionMessage('The "slack" transport in notifier.chatter_transports must be a DSN string, "array" given.');

        resolve(ChatterInterface::class)->send(new ChatMessage('Hello'));
    }

    public function testNotifierEventsReachAnEventFakeMadeAfterTheTransportsWereBuilt(): void
    {
        $transport = resolve(TransportFactory::class)->fromDsn('slack://xoxb-token@default?channel=C1');
        Http::fake(['https://slack.com/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '1.1'])]);
        Event::fake([MessageEvent::class, SentMessageEvent::class]);

        $transport->send(new ChatMessage('Hello'));

        Event::assertDispatched(MessageEvent::class);
        Event::assertDispatched(SentMessageEvent::class);
    }

}
