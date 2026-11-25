<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Exception;

use InvalidArgumentException;

/**
 * Thrown when a notifiable's route for the chat or sms channel is not something a message can be
 * sent to.
 */
final class InvalidRouteException extends InvalidArgumentException implements ExceptionInterface
{
}
