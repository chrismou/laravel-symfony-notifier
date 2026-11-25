<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http\Exception;

use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

/**
 * Thrown when a response has a 5xx status code and the caller asked for errors to be thrown.
 */
final class ServerException extends HttpException implements ServerExceptionInterface
{
}
