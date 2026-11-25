<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http;

/**
 * A request translated from Symfony HttpClient options into what Laravel's HTTP client needs.
 *
 * @internal
 */
final readonly class PreparedRequest
{
    /**
     * Create a prepared request.
     *
     * @param  array<string, array{name: string, values: list<string>}>  $headers  Keyed by lower-cased header name
     * @param  array<string, mixed>  $guzzleOptions
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public array $guzzleOptions,
        public mixed $userData,
    ) {
    }

    /**
     * Determine whether a header was set, by its case-insensitive name.
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }
}
