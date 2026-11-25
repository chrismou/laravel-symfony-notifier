<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http\Exception;

use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

/**
 * Thrown when a response has a 3xx status code and the caller asked for errors to be thrown.
 */
final class RedirectionException extends HttpException implements RedirectionExceptionInterface
{
}
