<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Feature;

use Chrismou\LaravelSymfonyNotifier\Tests\TestCase;
use Chrismou\LaravelSymfonyNotifier\TransportFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Notifier\Bridge\Discord\DiscordOptions;
use Symfony\Component\Notifier\Bridge\Discord\Embeds\DiscordEmbed;
use Symfony\Component\Notifier\Bridge\MicrosoftTeams\MicrosoftTeamsOptions;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;

/**
 * Sends a message through each real Symfony bridge with the Laravel HTTP client, faking the
 * provider's API, to prove the requests the bridges build and the responses they read work.
 */
final class BridgeCompatibilityTest extends TestCase
{
    /**
     * Build a transport from a DSN with the package's factory.
     */
    private function send(string $dsn, ChatMessage|SmsMessage $message): ?string
    {
        return resolve(TransportFactory::class)->fromDsn($dsn)->send($message)?->getMessageId();
    }

    public function testSlack(): void
    {
        Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C0123', 'ts' => '1700000000.000100'])]);

        $message = (new ChatMessage('Site down'))->options((new SlackOptions())->block((new SlackSectionBlock())->text('*example.com* is down')));
        $messageId = $this->send('slack://xoxb-token@default?channel=C0123', $message);

        $this->assertSame('1700000000.000100', $messageId);
        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->header('Authorization') === ['Bearer xoxb-token']
                && $request['channel'] === 'C0123'
                && $request['blocks'][0]['text']['text'] === '*example.com* is down';
        });
    }

    public function testSlackErrorsAreReportedByTheBridge(): void
    {
        Http::fake(['https://slack.com/*' => Http::response(['ok' => false, 'error' => 'invalid_auth'])]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to post the Slack message: "invalid_auth".');

        $this->send('slack://xoxb-revoked@default?channel=C0123', new ChatMessage('Site down'));
    }

    public function testAConnectionFailureIsReportedByTheBridgeAsUnreachable(): void
    {
        Http::fake(['https://slack.com/*' => $this->connectionFailure()]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not reach the remote Slack server.');

        $this->send('slack://xoxb-token@default?channel=C0123', new ChatMessage('Site down'));
    }

    public function testDiscord(): void
    {
        Http::fake(['https://discord.com/api/webhooks/123/webhook-token' => Http::response('', 204)]);

        $message = (new ChatMessage('Site down'))->options((new DiscordOptions())->addEmbed((new DiscordEmbed())->title('example.com is down')->color(0xDC2626)));
        $this->send('discord://webhook-token@default?webhook_id=123', $message);

        Http::assertSent(static fn (Request $request): bool => $request['content'] === 'Site down'
            && $request['embeds'][0]['title'] === 'example.com is down');
    }

    public function testTelegram(): void
    {
        Http::fake(['https://api.telegram.org/bot123:ABC/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);

        $message = (new ChatMessage('<b>example.com</b> is down'))->options((new TelegramOptions())->chatId('-100123')->parseMode(TelegramOptions::PARSE_MODE_HTML));
        $messageId = $this->send('telegram://123:ABC@default', $message);

        $this->assertSame('42', (string) $messageId);
        Http::assertSent(static fn (Request $request): bool => $request['chat_id'] === '-100123'
            && $request['parse_mode'] === 'HTML'
            && $request['text'] === '<b>example.com</b> is down');
    }

    public function testMicrosoftTeamsWorkflowsWebhook(): void
    {
        $url = 'https://default123.45.environment.api.powerplatform.com/powerautomate/automations/direct/workflows/678/triggers/manual/paths/invoke?api-version=1&sig=SIGNATURE';

        Http::fake([$url => Http::response('', 202, ['x-ms-service-request-id' => 'req-1'])]);

        $message = (new ChatMessage('Site down'))->options((new MicrosoftTeamsOptions())->title('example.com is down')->text('It stopped responding at 09:30.'));
        $messageId = $this->send('microsoftteams://default123.45.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/678/triggers/manual/paths/invoke?api-version=1&sig=SIGNATURE', $message);

        $this->assertSame('req-1', $messageId);
        Http::assertSent(static fn (Request $request): bool => $request['@type'] === 'MessageCard' && $request['title'] === 'example.com is down');
    }

    public function testRocketChat(): void
    {
        Http::fake(['https://chat.example.com/hooks/hook-id/hook-token' => Http::response(['success' => true])]);

        $this->send('rocketchat://hook-id%2Fhook-token@chat.example.com?channel=alerts', new ChatMessage('Site down'));

        Http::assertSent(static fn (Request $request): bool => $request['text'] === 'Site down' && $request['channel'] === 'alerts');
    }

    public function testTwilioSms(): void
    {
        Http::fake(['https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json' => Http::response(['sid' => 'SM123'], 201)]);

        $messageId = $this->send('twilio://AC123:auth-token@default?from=%2B447700900001', new SmsMessage('+447700900000', 'example.com is down'));

        $this->assertSame('SM123', $messageId);
        Http::assertSent(static fn (Request $request): bool => $request->header('Authorization') === ['Basic '.base64_encode('AC123:auth-token')]
            && $request->header('Content-Type') === ['application/x-www-form-urlencoded']
            && $request['To'] === '+447700900000'
            && $request['From'] === '+447700900001'
            && $request['Body'] === 'example.com is down');
    }
}
