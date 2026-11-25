<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Support;

use Closure;
use Illuminate\Notifications\Notification;

/**
 * A notification whose toChat()/toSms() return whatever the test gives it.
 */
final class Notifications extends Notification
{
    /**
     * Create a notification.
     *
     * @param  list<string>  $channels
     * @param  Closure(object): mixed|null  $chat
     * @param  Closure(object): mixed|null  $sms
     */
    public function __construct(
        private readonly array $channels,
        private readonly ?Closure $chat = null,
        private readonly ?Closure $sms = null,
    ) {
    }

    /**
     * Get the channels to send on.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * Build the chat message.
     */
    public function toChat(object $notifiable): mixed
    {
        return $this->chat === null ? 'Site down' : ($this->chat)($notifiable);
    }

    /**
     * Build the SMS.
     */
    public function toSms(object $notifiable): mixed
    {
        return $this->sms === null ? 'Site down' : ($this->sms)($notifiable);
    }
}
