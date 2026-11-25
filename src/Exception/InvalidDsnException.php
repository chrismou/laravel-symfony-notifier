<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Exception;

use InvalidArgumentException;

/**
 * Thrown when a DSN can't be built into a transport: it is malformed, its scheme is not allowed
 * or its bridge is not installed. The message never contains the DSN's credentials.
 */
final class InvalidDsnException extends InvalidArgumentException implements ExceptionInterface
{
}
