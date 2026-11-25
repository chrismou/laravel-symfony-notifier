<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http\Exception;

use InvalidArgumentException as BaseInvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown for an unknown, unsupported or invalid request option. The HttpClientInterface contract
 * requires this to be a TransportExceptionInterface, and it extends the global
 * InvalidArgumentException so it can be caught as either.
 */
final class InvalidArgumentException extends BaseInvalidArgumentException implements TransportExceptionInterface
{
}
