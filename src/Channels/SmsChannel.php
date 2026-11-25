<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Channels;

use Chrismou\LaravelSymfonyNotifier\Exception\InvalidMessageException;
use Chrismou\LaravelSymfonyNotifier\Exception\InvalidRouteException;
use Chrismou\LaravelSymfonyNotifier\Exception\MissingTransportException;
use Illuminate\Notifications\Notification;
use SensitiveParameter;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;

/**
 * The "sms" notification channel: sends the text or SmsMessage from a notification's toSms()
 * through a Notifier SMS transport (Twilio, Vonage, Amazon SNS and so on).
 *
 * The notifiable's routeNotificationForSms() (or an on-demand Notification::route('sms', ...))
 * returns the phone number, or ['phone' => ..., 'transport' => ...] to also choose the transport
 * with a DSN, a transport name or a TransportInterface. Otherwise the configured texter
 * transports are used.
 */
final class SmsChannel extends NotifierChannel
{
    /**
     * Send the notification. Returns null when toSms() returns null, so a notification can skip
     * the channel for some notifiables.
     *
     * @throws InvalidMessageException|InvalidRouteException|MissingTransportException|TransportExceptionInterface
     */
    public function send(object $notifiable, Notification $notification): ?SentMessage
    {
        if (! method_exists($notification, 'toSms')) {
            throw new InvalidMessageException(sprintf('%s needs a toSms() method to be sent on the sms channel.', $notification::class));
        }

        $message = $notification->toSms($notifiable);

        if ($message === null) {
            return null;
        }

        [$phone, $transport, $hasTransport] = $this->parseRoute($this->route($notifiable, 'sms', $notification));

        // A route that names its transport, but has none (an empty tenant setting), is skipped.
        if ($hasTransport && $transport === null) {
            return null;
        }

        if (is_string($message)) {
            // A notifiable with a phone route method but no number is skipped, as Laravel's own SMS channels do.
            if ($phone === null && $this->definesRoute($notifiable, 'sms')) {
                return null;
            }

            if ($phone === null) {
                throw new InvalidRouteException(sprintf('%s::toSms() returned text, so the notifiable needs a phone number from routeNotificationForSms().', $notification::class));
            }

            $message = new SmsMessage($phone, $message);
        } elseif ($message instanceof SmsMessage) {
            if ($phone !== null) {
                $message->phone($phone);
            }
        } else {
            throw new InvalidMessageException(sprintf('%s::toSms() must return a %s, a string or null, "%s" returned.', $notification::class, SmsMessage::class, get_debug_type($message)));
        }

        return $this->transportFor($transport, $message, $this->registry->texter(...), $this->registry->hasTexter(...), 'sms')->send($message);
    }

    /**
     * Split a route into its phone number, its transport and whether it named a transport. Empty
     * strings count as missing.
     *
     * @return array{0: string|null, 1: mixed, 2: bool}
     *
     * @throws InvalidRouteException
     */
    private function parseRoute(#[SensitiveParameter] mixed $route): array
    {
        if ($route === null || is_string($route)) {
            return [$route === '' ? null : $route, null, false];
        }

        if (is_array($route) && array_diff(array_keys($route), ['phone', 'transport']) === []) {
            $phone = $route['phone'] ?? null;
            $transport = $route['transport'] ?? null;

            if ($phone !== null && ! is_string($phone)) {
                throw new InvalidRouteException(sprintf('The sms route\'s "phone" must be a string, "%s" given.', get_debug_type($phone)));
            }

            return [$phone === '' ? null : $phone, $transport === '' ? null : $transport, array_key_exists('transport', $route)];
        }

        throw new InvalidRouteException(sprintf('The sms route must be a phone number or an array with "phone" and/or "transport" keys, "%s" given.', get_debug_type($route)));
    }
}
