<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Exception;

use LogicException;

/**
 * Thrown when a message would be sent through the configured transports but none are configured.
 */
final class MissingTransportException extends LogicException implements ExceptionInterface
{
}
