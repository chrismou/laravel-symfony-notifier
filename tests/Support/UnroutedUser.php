<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Support;

use Illuminate\Notifications\Notifiable;

/**
 * A notifiable with no chat or SMS route methods, so the configured transports are used.
 */
final class UnroutedUser
{
    use Notifiable;
}
