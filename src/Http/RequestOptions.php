<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Http;

use Chrismou\LaravelSymfonyNotifier\Http\Exception\InvalidArgumentException;
use JsonException;
use Stringable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Translates the Symfony HttpClient request options that Notifier bridges use into a
 * PreparedRequest for Laravel's HTTP client.
 *
 * Only options that were passed are translated, so anything left unset keeps Laravel's defaults
 * and any Http::globalOptions(). Every other option is accepted only at its default value, and
 * throws otherwise rather than being silently ignored, as the HttpClientInterface contract requires.
 *
 * @internal
 */
final class RequestOptions
{
    /**
     * The options that are translated.
     */
    private const SUPPORTED = [
        'auth_basic', 'auth_bearer', 'query', 'headers', 'body', 'json', 'user_data', 'max_redirects',
        'timeout', 'max_duration',
    ];

    /**
     * The flags Symfony's client encodes the "json" option with.
     */
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Check that every option is known and can be honoured, throwing for the first that is not.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    public function validate(array $options): void
    {
        foreach ($options as $name => $value) {
            if (! array_key_exists($name, HttpClientInterface::OPTIONS_DEFAULTS)) {
                throw new InvalidArgumentException(sprintf('Unsupported option "%s" passed to "%s".', $name, LaravelHttpClient::class));
            }

            if (! in_array($name, self::SUPPORTED, true) && $value !== HttpClientInterface::OPTIONS_DEFAULTS[$name]) {
                throw new InvalidArgumentException(sprintf('The "%s" option is not supported by "%s".', $name, LaravelHttpClient::class));
            }
        }
    }

    /**
     * Merge default options (from withOptions()) into a request's options the way Symfony's client
     * does: request values win, headers are merged by name and "query" is never inherited.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function merge(array $options, array $defaults): array
    {
        $merged = $options + array_diff_key($defaults, ['query' => true]);

        if (isset($defaults['headers'], $options['headers'])) {
            $merged['headers'] = [];
            $removed = $this->removedHeaders($options['headers']);

            foreach ($this->normaliseHeaders($options['headers']) + $this->normaliseHeaders($defaults['headers']) as $key => $header) {
                if (! isset($removed[$key])) {
                    $merged['headers'][$header['name']] = $header['values'];
                }
            }
        }

        return $merged;
    }

    /**
     * Translate a request into what Laravel's HTTP client needs.
     *
     * @param  array<string, mixed>  $options  Already merged with any default options
     *
     * @throws InvalidArgumentException
     */
    public function prepare(string $method, string $url, array $options): PreparedRequest
    {
        $this->validate($options);

        if (! preg_match('/^[A-Z]+$/', $method)) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP method "%s", only uppercase letters are accepted.', $method));
        }

        $headers = $this->applyAuthentication(isset($options['headers']) ? $this->normaliseHeaders($options['headers']) : [], $options);
        [$body, $contentType] = $this->body($options);

        if ($contentType === 'application/x-www-form-urlencoded') {
            // As in Symfony, a form body is always sent as one, whatever content type was given.
            if (str_starts_with(strtolower($headers['content-type']['values'][0] ?? ''), 'multipart/form-data')) {
                throw new InvalidArgumentException('Multipart bodies are not supported: file uploads can\'t be sent through Laravel\'s HTTP client by this adapter.');
            }

            $headers['content-type'] = ['name' => 'Content-Type', 'values' => [$contentType]];
        } elseif ($contentType !== null && ! isset($headers['content-type'])) {
            $headers['content-type'] = ['name' => 'Content-Type', 'values' => [$contentType]];
        }

        $headers['accept'] ??= ['name' => 'Accept', 'values' => ['*/*']];

        return new PreparedRequest(
            $method,
            $this->url($url, $options['query'] ?? []),
            $headers,
            $body,
            $this->guzzleOptions($options),
            $options['user_data'] ?? null,
        );
    }

    /**
     * Normalise headers given as name => value(s) or as "Name: value" lines, keyed by lower-cased
     * name, rejecting anything that could inject another header.
     *
     * @return array<string, array{name: string, values: list<string>}>
     *
     * @throws InvalidArgumentException
     */
    public function normaliseHeaders(mixed $headers): array
    {
        if (! is_iterable($headers)) {
            throw new InvalidArgumentException(sprintf('Option "headers" must be iterable, "%s" given.', get_debug_type($headers)));
        }

        $normalised = [];

        foreach ($headers as $name => $values) {
            if (is_int($name)) {
                if (! is_string($values) || ! str_contains($values, ':')) {
                    throw new InvalidArgumentException(sprintf('Invalid header: "%s" is not in the "Name: value" form.', is_string($values) ? $values : get_debug_type($values)));
                }

                [$name, $values] = explode(':', $values, 2);
                $values = [ltrim($values)];
            }

            $name = trim((string) $name);

            if ($name === '' || preg_match('/[\r\n\0:\s]/', $name)) {
                throw new InvalidArgumentException(sprintf('Invalid header name "%s".', $name));
            }

            $key = strtolower($name);
            $normalised[$key] ??= ['name' => $name, 'values' => []];

            foreach (is_iterable($values) ? $values : [$values] as $value) {
                if ($value === null) {
                    continue;
                }

                if (! is_scalar($value) && ! $value instanceof Stringable) {
                    throw new InvalidArgumentException(sprintf('Invalid value for header "%s": "%s" given.', $name, get_debug_type($value)));
                }

                $value = is_bool($value) ? ($value ? '1' : '') : (string) $value;

                if (preg_match('/[\r\n\0]/', $value)) {
                    throw new InvalidArgumentException(sprintf('Invalid value for header "%s": CR, LF and NUL are not allowed.', $name));
                }

                $normalised[$key]['values'][] = $value;
            }

            if ($normalised[$key]['values'] === []) {
                unset($normalised[$key]);
            }
        }

        return $normalised;
    }

    /**
     * Get the headers a request removes from the defaults by setting them to null, keyed by
     * lower-cased name.
     *
     * @return array<string, true>
     */
    private function removedHeaders(mixed $headers): array
    {
        $removed = [];

        foreach (is_iterable($headers) ? $headers : [] as $name => $value) {
            if (is_string($name) && $value === null) {
                $removed[strtolower(trim($name))] = true;
            }
        }

        return $removed;
    }

    /**
     * Check the URL is absolute HTTP(S) and append the "query" option, dropping any fragment.
     *
     * @throws InvalidArgumentException
     */
    private function url(string $url, mixed $query): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
            throw new InvalidArgumentException(sprintf('Invalid URL "%s": an absolute http or https URL is required.', $url));
        }

        if (! is_array($query)) {
            throw new InvalidArgumentException(sprintf('Option "query" must be an array, "%s" given.', get_debug_type($query)));
        }

        $url = explode('#', $url, 2)[0];

        if ($query === []) {
            return $url;
        }

        // As in Symfony, the option replaces parameters already in the URL, and null removes one.
        [$base, $existing] = explode('?', $url, 2) + [1 => ''];
        parse_str($existing, $parameters);
        $parameters = array_filter(array_replace($parameters, $query), static fn (mixed $value): bool => $value !== null);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return $query === '' ? $base : $base.'?'.$query;
    }

    /**
     * Turn "auth_basic" or "auth_bearer" into an Authorization header.
     *
     * @param  array<string, array{name: string, values: list<string>}>  $headers
     * @param  array<string, mixed>  $options
     * @return array<string, array{name: string, values: list<string>}>
     *
     * @throws InvalidArgumentException
     */
    private function applyAuthentication(array $headers, array $options): array
    {
        $basic = $options['auth_basic'] ?? null;
        $bearer = $options['auth_bearer'] ?? null;

        if ($basic !== null && $bearer !== null) {
            throw new InvalidArgumentException('Define either the "auth_basic" or the "auth_bearer" option, setting both is not supported.');
        }

        if (is_array($basic) && array_is_list($basic) && in_array(count($basic), [1, 2], true)) {
            $basic = implode(':', array_map(static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $basic));
        }

        if ($basic !== null && ! is_string($basic)) {
            throw new InvalidArgumentException('Option "auth_basic" must be a "user:password" string or a list of the username and password.');
        }

        if ($bearer !== null && (! is_string($bearer) || preg_match('/[^\x21-\x7E]/', $bearer))) {
            throw new InvalidArgumentException('Option "auth_bearer" must be a string of printable ASCII characters.');
        }

        // As in Symfony, an Authorization header that was set explicitly is kept.
        if (isset($headers['authorization'])) {
            return $headers;
        }

        return match (true) {
            $basic !== null => [...$headers, 'authorization' => ['name' => 'Authorization', 'values' => ['Basic '.base64_encode($basic)]]],
            $bearer !== null => [...$headers, 'authorization' => ['name' => 'Authorization', 'values' => ['Bearer '.$bearer]]],
            default => $headers,
        };
    }

    /**
     * Work out the request body and the content type it implies.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string|null, 1: string|null}
     *
     * @throws InvalidArgumentException
     */
    private function body(array $options): array
    {
        $body = $options['body'] ?? '';
        $json = $options['json'] ?? null;

        if ($json !== null) {
            if ($body !== '') {
                throw new InvalidArgumentException('Define either the "json" or the "body" option, setting both is not supported.');
            }

            try {
                return [json_encode($json, self::JSON_FLAGS | JSON_THROW_ON_ERROR), 'application/json'];
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid value for "json" option: '.$exception->getMessage(), 0, $exception);
            }
        }

        if (is_array($body)) {
            array_walk_recursive($body, static function (mixed &$value): void {
                if ($value !== null && ! is_scalar($value) && ! $value instanceof Stringable) {
                    throw new InvalidArgumentException(sprintf('Option "body" can only hold scalar form values, "%s" given. File uploads are not supported.', get_debug_type($value)));
                }

                if ($value instanceof Stringable) {
                    $value = (string) $value;
                }
            });

            return $body === [] ? [null, null] : [http_build_query($body, '', '&'), 'application/x-www-form-urlencoded'];
        }

        if (is_string($body) || $body instanceof Stringable) {
            $body = (string) $body;

            return [$body === '' ? null : $body, null];
        }

        throw new InvalidArgumentException(sprintf('Option "body" must be a string or a form array, "%s" given.', get_debug_type($body)));
    }

    /**
     * Translate the redirect and timeout options into Guzzle options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function guzzleOptions(array $options): array
    {
        $guzzleOptions = [];

        if (isset($options['max_redirects'])) {
            if (! is_int($options['max_redirects']) || $options['max_redirects'] < 0) {
                throw new InvalidArgumentException('Option "max_redirects" must be a non-negative integer.');
            }

            $guzzleOptions['allow_redirects'] = $options['max_redirects'] === 0
                ? false
                : ['max' => $options['max_redirects'], 'strict' => false, 'referer' => false, 'protocols' => ['http', 'https'], 'track_redirects' => true];
        }

        // Symfony's "timeout" is an idle timeout, which Guzzle's curl handler can't express, so it
        // limits the whole request unless "max_duration" is set.
        foreach (['max_duration', 'timeout'] as $name) {
            $value = $options[$name] ?? null;

            if ($value !== null && (! is_numeric($value) || $value < 0)) {
                throw new InvalidArgumentException(sprintf('Option "%s" must be a non-negative number.', $name));
            }

            if ($value !== null && $value > 0) {
                $guzzleOptions['timeout'] = (float) $value;

                break;
            }
        }

        return $guzzleOptions;
    }
}
