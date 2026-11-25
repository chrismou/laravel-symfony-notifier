<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http;

use BadMethodCallException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\TransportException;
use Chrismou\LaravelSymfonyNotifier\Support\Redactor;
use Closure;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\StrayRequestException;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A Symfony HttpClientInterface that sends every request through Laravel's HTTP client, so
 * Http::fake(), Http::preventStrayRequests(), global middleware and options, and Laravel's HTTP
 * client events all apply to requests made by Notifier bridges.
 *
 * It covers what the bridges need rather than the whole contract: requests are synchronous,
 * stream() is not supported and only some request options are translated (see the README).
 */
final class LaravelHttpClient implements HttpClientInterface
{
    /**
     * The default options merged into every request.
     *
     * @var array<string, mixed>
     */
    private array $defaultOptions = [];

    /**
     * Callbacks that can adjust each request's PendingRequest before it is sent.
     *
     * @var list<Closure(PendingRequest, string, string, array<string, mixed>): (PendingRequest|null)>
     */
    private array $pendingRequestCallbacks = [];

    /**
     * Translates Symfony request options.
     */
    private readonly RequestOptions $requestOptions;

    /**
     * Create a client. Without a factory, Laravel's HTTP client factory (the one behind the Http
     * facade) is resolved from the container for each request, so fakes always apply.
     */
    public function __construct(private readonly ?Factory $factory = null)
    {
        $this->requestOptions = new RequestOptions();
    }

    /**
     * Send a request and return its response. A connection failure is thrown from the response's
     * methods, not from here; an unknown or unsupported option is thrown straight away.
     *
     * @param  array<string, mixed>  $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = $this->requestOptions->merge($options, $this->defaultOptions);
        $prepared = $this->requestOptions->prepare($method, $url, $options);
        $startTime = microtime(true);
        $response = null;
        $error = null;

        try {
            $response = $this->pendingRequest($prepared, $options)->send($prepared->method, $prepared->url);
        } catch (RequestException $exception) {
            // Thrown when the app has turned on throwing for error statuses globally.
            $response = $exception->response;
        } catch (StrayRequestException $exception) {
            throw $exception;
        } catch (ConnectionException|GuzzleException|TransportExceptionInterface|InvalidArgumentException $exception) {
            // Deferred to the response, as Symfony's client does, so the bridge reports it as
            // unreachable. This includes a pending request callback refusing the request.
            // URLs in the message are redacted, as chat apps put tokens in them.
            $error = new TransportException(Redactor::message($exception->getMessage()), 0, $exception instanceof TransportExceptionInterface ? $exception : null);
        }

        return new LaravelHttpResponse($prepared->method, $prepared->url, $response, $error, $prepared->userData, $startTime);
    }

    /**
     * Streaming is not supported: Notifier bridges never stream, and Laravel's client is synchronous.
     *
     * @param  ResponseInterface|iterable<array-key, ResponseInterface>  $responses
     *
     * @throws BadMethodCallException
     */
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new BadMethodCallException(sprintf('"%s" does not support streaming responses.', self::class));
    }

    /**
     * Get a copy of the client with default options merged into every request.
     *
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): static
    {
        $this->requestOptions->validate($options);

        $client = clone $this;
        $client->defaultOptions = $this->requestOptions->merge($options, $this->defaultOptions);

        return $client;
    }

    /**
     * Get a copy of the client that passes each request's PendingRequest to a callback before it is
     * sent, to add anything the Symfony options cannot express: retries, middleware, Guzzle
     * options. The callback gets the PendingRequest, the method, the URL and the request options,
     * and may return a replacement PendingRequest.
     *
     * @param  Closure(PendingRequest, string, string, array<string, mixed>): (PendingRequest|null)  $callback
     */
    public function withPendingRequest(Closure $callback): static
    {
        $client = clone $this;
        $client->pendingRequestCallbacks[] = $callback;

        return $client;
    }

    /**
     * Build the PendingRequest for a prepared request.
     *
     * @param  array<string, mixed>  $options
     */
    private function pendingRequest(PreparedRequest $prepared, array $options): PendingRequest
    {
        $pendingRequest = $this->factory()->createPendingRequest()->withOptions($prepared->guzzleOptions);

        if ($prepared->body !== null) {
            // The content type here is a placeholder: the request middleware below sets the real one.
            $pendingRequest->withBody($prepared->body, 'application/octet-stream');
        }

        $headers = $prepared->headers;
        $hasContentType = $prepared->hasHeader('Content-Type');

        // Laravel sends a JSON content type by default and merges headers case-sensitively, so the
        // headers are set on the PSR-7 request instead, replacing any of the same name. Without a
        // content type of its own, the request drops only Laravel's default and the placeholder
        // above, so one set by a pending request callback or global options is kept.
        $pendingRequest->withRequestMiddleware(static function (RequestInterface $request) use ($headers, $hasContentType): RequestInterface {
            if (! $hasContentType) {
                $contentTypes = array_values(array_filter(
                    $request->getHeader('Content-Type'),
                    static fn (string $value): bool => ! in_array(strtolower($value), ['application/json', 'application/octet-stream'], true),
                ));

                $request = $contentTypes === [] ? $request->withoutHeader('Content-Type') : $request->withHeader('Content-Type', $contentTypes);
            }

            foreach ($headers as $header) {
                $request = $request->withHeader($header['name'], $header['values']);
            }

            return $request;
        });

        foreach ($this->pendingRequestCallbacks as $callback) {
            $pendingRequest = $callback($pendingRequest, $prepared->method, $prepared->url, $options) ?? $pendingRequest;
        }

        return $pendingRequest;
    }

    /**
     * Get the HTTP client factory, resolving the container's on each request when none was given.
     */
    private function factory(): Factory
    {
        return $this->factory ?? Container::getInstance()->make(Factory::class);
    }
}
