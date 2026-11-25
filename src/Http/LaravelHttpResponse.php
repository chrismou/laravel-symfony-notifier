<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http;

use Chrismou\LaravelSymfonyNotifier\Http\Exception\ClientException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\DecodingException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\RedirectionException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\ServerException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\TransportException;
use Chrismou\LaravelSymfonyNotifier\Support\Redactor;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use JsonException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A Symfony HttpClient response around a completed Laravel HTTP client response.
 *
 * Laravel's client is synchronous, so the request has already finished when this is created; a
 * connection failure is held back and thrown from the first method that needs the response, as
 * Symfony's lazy responses do.
 */
final class LaravelHttpResponse implements ResponseInterface
{
    /**
     * The response headers, keyed by lower-cased name.
     *
     * @var array<string, list<string>>
     */
    private array $headers = [];

    /**
     * The transport information returned by getInfo().
     *
     * @var array<string, mixed>
     */
    private array $info;

    /**
     * Create a response from a finished request.
     */
    public function __construct(
        string $method,
        string $url,
        private readonly ?Response $response,
        private readonly ?TransportException $error,
        mixed $userData,
        float $startTime,
    ) {
        foreach ($response?->headers() ?? [] as $name => $values) {
            $this->headers[strtolower((string) $name)] = array_values(array_map('strval', (array) $values));
        }

        // Guzzle only tracks redirects when asked to (the "max_redirects" option does), so without
        // it the count is 0 and the URL is the one requested.
        $redirects = $this->headers['x-guzzle-redirect-history'] ?? [];
        unset($this->headers['x-guzzle-redirect-history'], $this->headers['x-guzzle-redirect-status-history']);
        $effectiveUrl = $redirects === [] ? $url : (string) end($redirects);

        $this->info = [
            'canceled' => false,
            'error' => $error?->getMessage(),
            'http_code' => $response?->status() ?? 0,
            'http_method' => $method,
            'redirect_count' => count($redirects),
            'redirect_url' => $this->redirectUrl($effectiveUrl),
            'response_headers' => $this->rawHeaders(),
            'start_time' => $startTime,
            'total_time' => microtime(true) - $startTime,
            'url' => $effectiveUrl,
            'user_data' => $userData,
        ];
    }

    /**
     * Get the HTTP status code of the response.
     */
    public function getStatusCode(): int
    {
        return $this->completedResponse()->status();
    }

    /**
     * Get the response headers, keyed by lower-cased name.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array
    {
        $this->completedResponse();

        if ($throw) {
            $this->throwForStatus();
        }

        return $this->headers;
    }

    /**
     * Get the response body.
     */
    public function getContent(bool $throw = true): string
    {
        $response = $this->completedResponse();

        if ($throw) {
            $this->throwForStatus();
        }

        return $response->body();
    }

    /**
     * Get the response body decoded from JSON.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        $content = $this->getContent($throw);

        if ($content === '') {
            throw new DecodingException('Response body is empty.');
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DecodingException($exception->getMessage().sprintf(' for "%s".', Redactor::url($this->info['url'])), $exception->getCode(), $exception);
        }

        if (! is_array($decoded)) {
            throw new DecodingException(sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', get_debug_type($decoded), Redactor::url($this->info['url'])));
        }

        return $decoded;
    }

    /**
     * Cancel the response. The request has already completed, so this only stops the response
     * being used: every method other than getInfo() throws afterwards.
     */
    public function cancel(): void
    {
        $this->info['canceled'] = true;
        $this->info['error'] = 'Response has been canceled.';
    }

    /**
     * Get transport information, or one item of it.
     */
    public function getInfo(?string $type = null): mixed
    {
        return $type === null ? $this->info : ($this->info[$type] ?? null);
    }

    /**
     * Get the Laravel response, throwing if the request failed or the response was canceled.
     *
     * @throws TransportException
     */
    private function completedResponse(): Response
    {
        if ($this->info['canceled']) {
            throw new TransportException('Response has been canceled.');
        }

        if ($this->error !== null) {
            throw new TransportException($this->error->getMessage(), 0, $this->error);
        }

        assert($this->response !== null);

        return $this->response;
    }

    /**
     * Throw for a 3xx, 4xx or 5xx status code.
     *
     * @throws RedirectionException|ClientException|ServerException
     */
    private function throwForStatus(): void
    {
        $status = (int) $this->response?->status();

        match (true) {
            $status >= 500 => throw new ServerException($this),
            $status >= 400 => throw new ClientException($this),
            $status >= 300 => throw new RedirectionException($this),
            default => null,
        };
    }

    /**
     * Resolve the Location of a 3xx response against the URL it came from.
     */
    private function redirectUrl(string $effectiveUrl): ?string
    {
        $status = (int) $this->response?->status();
        $location = $this->headers['location'][0] ?? null;

        if ($status < 300 || $status >= 400 || $location === null) {
            return null;
        }

        return (string) UriResolver::resolve(new Uri($effectiveUrl), new Uri($location));
    }

    /**
     * Build the response headers as raw lines, like PHP's $http_response_header.
     *
     * @return list<string>
     */
    private function rawHeaders(): array
    {
        if ($this->response === null) {
            return [];
        }

        $psr = $this->response->toPsrResponse();
        $lines = [rtrim(sprintf('HTTP/%s %d %s', $psr->getProtocolVersion(), $psr->getStatusCode(), $psr->getReasonPhrase()))];

        foreach ($psr->getHeaders() as $name => $values) {
            if (str_starts_with(strtolower((string) $name), 'x-guzzle-redirect-')) {
                continue;
            }

            foreach ($values as $value) {
                $lines[] = $name.': '.$value;
            }
        }

        return $lines;
    }
}
