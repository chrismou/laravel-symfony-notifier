<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http\Exception;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;

/**
 * Thrown by toArray() when the response body is not a JSON object or array.
 */
final class DecodingException extends RuntimeException implements DecodingExceptionInterface
{
}
