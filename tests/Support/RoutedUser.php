<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Support;

use Illuminate\Notifications\Notifiable;

/**
 * A notifiable with fixed chat and SMS routes.
 */
final class RoutedUser
{
    use Notifiable;

    /**
     * Create a notifiable.
     */
    public function __construct(
        private readonly mixed $chatRoute = null,
        private readonly mixed $smsRoute = null,
    ) {
    }

    /**
     * Get the chat route: a DSN, a transport name or a transport.
     */
    public function routeNotificationForChat(): mixed
    {
        return $this->chatRoute;
    }

    /**
     * Get the SMS route: a phone number, or an array with the phone and transport.
     */
    public function routeNotificationForSms(): mixed
    {
        return $this->smsRoute;
    }
}
