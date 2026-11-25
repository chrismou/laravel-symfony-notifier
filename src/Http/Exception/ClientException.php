<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http\Exception;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

/**
 * Thrown when a response has a 4xx status code and the caller asked for errors to be thrown.
 */
final class ClientException extends HttpException implements ClientExceptionInterface
{
}
