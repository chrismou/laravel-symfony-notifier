<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http\Exception;

use Chrismou\LaravelSymfonyNotifier\Support\Redactor;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Base class for exceptions thrown when a response has a 3xx, 4xx or 5xx status code.
 */
abstract class HttpException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * Create the exception for the given response.
     */
    public function __construct(private readonly ResponseInterface $response)
    {
        $url = $response->getInfo('url');

        // Only the scheme and host are shown, as chat apps put tokens in their URLs.
        parent::__construct(
            sprintf('HTTP %d returned for "%s".', $response->getStatusCode(), is_string($url) ? Redactor::url($url) : ''),
            $response->getStatusCode(),
        );
    }

    /**
     * Get the response that caused the exception.
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
