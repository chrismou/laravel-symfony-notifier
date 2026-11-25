<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http\Exception;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown when a request could not be completed: the connection failed, timed out or was aborted.
 */
final class TransportException extends RuntimeException implements TransportExceptionInterface
{
}
