<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Channels;

use Chrismou\LaravelSymfonyNotifier\Exception\InvalidRouteException;
use Chrismou\LaravelSymfonyNotifier\TransportFactory;
use Chrismou\LaravelSymfonyNotifier\TransportRegistry;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use SensitiveParameter;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * What the chat and SMS channels share: working out which transport a message goes through.
 */
abstract class NotifierChannel
{
    /**
     * Create a channel.
     */
    public function __construct(
        protected readonly TransportFactory $factory,
        protected readonly TransportRegistry $registry,
    ) {
    }

    /**
     * Get the notifiable's route for a channel. An empty string counts as no route.
     */
    protected function route(object $notifiable, string $channel, Notification $notification): mixed
    {
        $route = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor($channel, $notification)
            : null;

        return $route === '' ? null : $route;
    }

    /**
     * Determine whether the notifiable defines its own route for a channel, with a
     * routeNotificationFor{Channel}() method or an on-demand route. A notifiable that does, but
     * has no route, is skipped rather than sent to the app's configured transports, so one
     * tenant's alerts never reach another's channel.
     */
    protected function definesRoute(object $notifiable, string $channel): bool
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_key_exists($channel, $notifiable->routes);
        }

        return method_exists($notifiable, 'routeNotificationFor'.ucfirst($channel));
    }

    /**
     * Get the transport a message goes through:
     *
     * - a TransportInterface is used as it is
     * - a DSN (anything containing "://") is built into a transport
     * - any other string names a configured transport, and is set as the message's transport
     * - null leaves the choice to the configured transports: the message's transport() if it has
     *   one, otherwise the first that supports it
     *
     * @param  callable(): TransportInterface  $configured  Gets the configured transports
     * @param  callable(string): bool  $isConfigured  Checks a configured transport name
     *
     * @throws InvalidRouteException
     */
    protected function transportFor(
        #[SensitiveParameter] mixed $transport,
        ChatMessage|SmsMessage $message,
        callable $configured,
        callable $isConfigured,
        string $channel,
    ): TransportInterface {
        if ($transport instanceof TransportInterface) {
            return $transport;
        }

        if ($transport === null) {
            return $configured();
        }

        if (! is_string($transport) || $transport === '') {
            throw new InvalidRouteException(sprintf(
                'The %s route must be a DSN, the name of a configured transport or a %s, "%s" given.',
                $channel,
                TransportInterface::class,
                get_debug_type($transport),
            ));
        }

        if (str_contains($transport, '://')) {
            return $this->factory->fromDsn($transport);
        }

        if (! $isConfigured($transport)) {
            throw new InvalidRouteException(sprintf('The %s route names the "%s" transport, which is not configured in notifier.%s_transports.', $channel, $transport, $channel === 'chat' ? 'chatter' : 'texter'));
        }

        $message->transport($transport);

        return $configured();
    }
}
