<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Feature;

use Chrismou\LaravelSymfonyNotifier\Exception\InvalidMessageException;
use Chrismou\LaravelSymfonyNotifier\Exception\InvalidRouteException;
use Chrismou\LaravelSymfonyNotifier\Exception\MissingTransportException;
use Chrismou\LaravelSymfonyNotifier\Tests\Support\Notifications;
use Chrismou\LaravelSymfonyNotifier\Tests\Support\RoutedUser;
use Chrismou\LaravelSymfonyNotifier\Tests\Support\UnroutedUser;
use Chrismou\LaravelSymfonyNotifier\Tests\TestCase;
use Chrismou\LaravelSymfonyNotifier\TransportFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Notifier\Event\FailedMessageEvent;
use Symfony\Component\Notifier\Event\SentMessageEvent;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;

final class ChannelsTest extends TestCase
{
    private const SLACK_DSN = 'slack://xoxb-user-token@default?channel=C0123';

    /**
     * Fake the Slack and Twilio APIs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://slack.com/*' => Http::response(['ok' => true, 'channel' => 'C0123', 'ts' => '1.1']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
            'https://api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
        ]);
    }

    public function testAnOnDemandChatRouteCanBeADsn(): void
    {
        Notification::route('chat', self::SLACK_DSN)->notify(new Notifications(['chat']));

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request->header('Authorization') === ['Bearer xoxb-user-token']
            && $request['text'] === 'Site down');
    }

    public function testANotifiableCanRouteToItsOwnDsn(): void
    {
        (new RoutedUser(chatRoute: self::SLACK_DSN))->notify(new Notifications(['chat']));

        Http::assertSentCount(1);
    }

    public function testARouteCanNameAConfiguredTransport(): void
    {
        config(['notifier.chatter_transports' => [
            'slack' => 'slack://xoxb-app-token@default?channel=CAPP',
            'telegram' => 'telegram://123:ABC@default?channel=-100',
        ]]);

        (new RoutedUser(chatRoute: 'telegram'))->notify(new Notifications(['chat']));

        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/bot123:ABC/'));
        Http::assertSentCount(1);
    }

    public function testWithoutARouteTheMessagesTransportOrTheFirstConfiguredOneIsUsed(): void
    {
        config(['notifier.chatter_transports' => [
            'unset' => null,
            'slack' => 'slack://xoxb-app-token@default?channel=CAPP',
            'telegram' => 'telegram://123:ABC@default?channel=-100',
        ]]);

        (new UnroutedUser())->notify(new Notifications(['chat']));
        (new UnroutedUser())->notify(new Notifications(['chat'], static fn (): ChatMessage => (new ChatMessage('Down'))->transport('telegram')));

        Http::assertSentInOrder([
            static fn (Request $request): bool => $request->header('Authorization') === ['Bearer xoxb-app-token'],
            static fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'),
        ]);
    }

    public function testATransportInstanceCanBeTheRoute(): void
    {
        $transport = resolve(TransportFactory::class)->fromDsn(self::SLACK_DSN);

        (new RoutedUser(chatRoute: $transport))->notify(new Notifications(['chat']));

        Http::assertSentCount(1);
    }

    public function testSendingWithNoRouteAndNoConfiguredTransportsExplainsWhatIsMissing(): void
    {
        $this->expectException(MissingTransportException::class);
        $this->expectExceptionMessage('No transports are configured in notifier.chatter_transports');

        (new UnroutedUser())->notify(new Notifications(['chat']));
    }

    public function testAnInvalidRouteIsRejected(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('The chat route must be a DSN, the name of a configured transport or a');

        (new RoutedUser(chatRoute: ['not', 'a', 'route']))->notify(new Notifications(['chat']));
    }

    public function testToChatReturningNullSkipsTheChannel(): void
    {
        Notification::route('chat', self::SLACK_DSN)->notify(new Notifications(['chat'], static fn (): null => null));

        Http::assertNothingSent();
    }

    public function testToChatMustReturnAMessage(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('::toChat() must return a Symfony\Component\Notifier\Message\ChatMessage, a string or null, "int" returned.');

        Notification::route('chat', self::SLACK_DSN)->notify(new Notifications(['chat'], static fn (): int => 1));
    }

    public function testANotificationWithoutToChatCannotUseTheChannel(): void
    {
        $notification = new class () extends BaseNotification {
            /**
             * @return list<string>
             */
            public function via(object $notifiable): array
            {
                return ['chat'];
            }
        };

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('needs a toChat() method to be sent on the chat channel.');

        Notification::route('chat', self::SLACK_DSN)->notify($notification);
    }

    public function testTheSentMessageIsTheChannelsResponse(): void
    {
        $responses = [];
        Event::listen(NotificationSent::class, static function (NotificationSent $event) use (&$responses): void {
            $responses[] = $event->response;
        });

        Notification::route('chat', self::SLACK_DSN)->notify(new Notifications(['chat']));

        $this->assertCount(1, $responses);
        $this->assertInstanceOf(SentMessage::class, $responses[0]);
        $this->assertSame('1.1', $responses[0]->getMessageId());
    }

    public function testNotifierEventsAreDispatchedThroughLaravel(): void
    {
        $events = [];
        Event::listen([SentMessageEvent::class, FailedMessageEvent::class], static function (object $event) use (&$events): void {
            $events[] = $event::class;
        });

        Notification::route('chat', self::SLACK_DSN)->notify(new Notifications(['chat']));

        Http::fake(['https://discord.com/*' => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);

        try {
            Notification::route('chat', 'discord://bad@default?webhook_id=1')->notify(new Notifications(['chat']));
            $this->fail('The failed send did not throw.');
        } catch (TransportException) {
        }

        $this->assertSame([SentMessageEvent::class, FailedMessageEvent::class], $events);
    }

    public function testAnSmsRouteIsThePhoneNumberForTextFromToSms(): void
    {
        config(['notifier.texter_transports' => ['twilio' => 'twilio://AC1:token@default?from=%2B447700900001']]);

        (new RoutedUser(smsRoute: '+447700900000'))->notify(new Notifications(['sms']));

        Http::assertSent(static fn (Request $request): bool => $request['To'] === '+447700900000' && $request['Body'] === 'Site down');
    }

    public function testAnSmsRouteCanChooseTheTransportAsWellAsThePhone(): void
    {
        Notification::route('sms', ['phone' => '+447700900000', 'transport' => 'twilio://AC2:token@default?from=%2B447700900001'])
            ->notify(new Notifications(['sms']));

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC2/Messages.json');
    }

    public function testAnSmsMessageFromToSmsIsSentToTheRoutesPhone(): void
    {
        config(['notifier.texter_transports' => ['twilio' => 'twilio://AC1:token@default?from=%2B447700900001']]);

        (new RoutedUser(smsRoute: '+447700900000'))->notify(new Notifications(['sms'], sms: static fn (): SmsMessage => new SmsMessage('+15550000000', 'Down')));
        Notification::route('sms', null)->notify(new Notifications(['sms'], sms: static fn (): SmsMessage => new SmsMessage('+15550000000', 'Down')));

        Http::assertSentInOrder([
            static fn (Request $request): bool => $request['To'] === '+447700900000',
            static fn (Request $request): bool => $request['To'] === '+15550000000',
        ]);
    }

    public function testSmsTextNeedsAPhoneNumber(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('returned text, so the notifiable needs a phone number from routeNotificationForSms().');

        (new UnroutedUser())->notify(new Notifications(['sms']));
    }

    public function testAnInvalidSmsRouteIsRejected(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('The sms route must be a phone number or an array with "phone" and/or "transport" keys');

        (new RoutedUser(smsRoute: ['number' => '+447700900000']))->notify(new Notifications(['sms']));
    }

    public function testANotifiableWhoseRouteMethodReturnsNothingIsSkippedRatherThanSentToTheConfiguredTransports(): void
    {
        config(['notifier.chatter_transports' => ['ops' => 'slack://xoxb-ops-token@default?channel=COPS']]);

        (new RoutedUser(chatRoute: null))->notify(new Notifications(['chat']));
        (new RoutedUser(chatRoute: ''))->notify(new Notifications(['chat']));
        Notification::route('chat', null)->notify(new Notifications(['chat']));

        Http::assertNothingSent();
    }

    public function testARouteNamingATransportThatIsNotConfiguredIsRejected(): void
    {
        config(['notifier.chatter_transports' => ['slack' => self::SLACK_DSN]]);

        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('The chat route names the "slak" transport, which is not configured in notifier.chatter_transports.');

        (new RoutedUser(chatRoute: 'slak'))->notify(new Notifications(['chat']));
    }

    public function testSmsTextForANotifiableWithoutAPhoneNumberIsSkipped(): void
    {
        config(['notifier.texter_transports' => ['twilio' => 'twilio://AC1:token@default?from=%2B447700900001']]);

        (new RoutedUser(smsRoute: null))->notify(new Notifications(['sms']));
        (new RoutedUser(smsRoute: ''))->notify(new Notifications(['sms']));

        Http::assertNothingSent();
    }

    public function testAnSmsRouteWithAnEmptyTransportIsSkipped(): void
    {
        config(['notifier.texter_transports' => ['twilio' => 'twilio://AC1:token@default?from=%2B447700900001']]);

        (new RoutedUser(smsRoute: ['phone' => '+447700900000', 'transport' => '']))->notify(new Notifications(['sms']));
        (new RoutedUser(smsRoute: ['phone' => '+447700900000', 'transport' => null]))->notify(new Notifications(['sms']));

        Http::assertNothingSent();
    }

    public function testAnSmsRouteCanNameAConfiguredTransport(): void
    {
        config(['notifier.texter_transports' => [
            'first' => 'twilio://AC1:token@default?from=%2B447700900001',
            'second' => 'twilio://AC2:token@default?from=%2B447700900002',
        ]]);

        (new RoutedUser(smsRoute: ['phone' => '+447700900000', 'transport' => 'second']))->notify(new Notifications(['sms']));

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/Accounts/AC2/') && $request['From'] === '+447700900002');
    }

}
