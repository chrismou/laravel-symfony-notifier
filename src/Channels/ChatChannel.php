<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Channels;

use Chrismou\LaravelSymfonyNotifier\Exception\InvalidMessageException;
use Chrismou\LaravelSymfonyNotifier\Exception\InvalidRouteException;
use Chrismou\LaravelSymfonyNotifier\Exception\MissingTransportException;
use Illuminate\Notifications\Notification;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SentMessage;

/**
 * The "chat" notification channel: sends the ChatMessage from a notification's toChat() through a
 * Notifier chat transport (Slack, Discord, Telegram, Microsoft Teams and so on).
 *
 * The transport comes from the notifiable's routeNotificationForChat() (or an on-demand
 * Notification::route('chat', ...)): a DSN, the name of a transport in notifier.chatter_transports,
 * or a TransportInterface. Without a route, the configured chatter transports are used.
 */
final class ChatChannel extends NotifierChannel
{
    /**
     * Send the notification. Returns null when toChat() returns null, so a notification can skip
     * the channel for some notifiables.
     *
     * @throws InvalidMessageException|InvalidRouteException|MissingTransportException|TransportExceptionInterface
     */
    public function send(object $notifiable, Notification $notification): ?SentMessage
    {
        if (! method_exists($notification, 'toChat')) {
            throw new InvalidMessageException(sprintf('%s needs a toChat() method to be sent on the chat channel.', $notification::class));
        }

        $message = $notification->toChat($notifiable);

        if ($message === null) {
            return null;
        }

        if (is_string($message)) {
            $message = new ChatMessage($message);
        }

        if (! $message instanceof ChatMessage) {
            throw new InvalidMessageException(sprintf('%s::toChat() must return a %s, a string or null, "%s" returned.', $notification::class, ChatMessage::class, get_debug_type($message)));
        }

        $route = $this->route($notifiable, 'chat', $notification);

        if ($route === null && $this->definesRoute($notifiable, 'chat')) {
            return null;
        }

        return $this->transportFor($route, $message, $this->registry->chatter(...), $this->registry->hasChatter(...), 'chat')->send($message);
    }
}
