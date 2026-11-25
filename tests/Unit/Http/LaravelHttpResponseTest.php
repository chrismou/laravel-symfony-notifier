<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Unit\Http;

use Chrismou\LaravelSymfonyNotifier\Http\Exception\ClientException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\DecodingException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\RedirectionException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\ServerException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\TransportException;
use Chrismou\LaravelSymfonyNotifier\Http\LaravelHttpClient;
use Chrismou\LaravelSymfonyNotifier\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class LaravelHttpResponseTest extends TestCase
{
    /**
     * Fake every request with one response and send a request.
     *
     * @param  array<string, string|list<string>>  $headers
     * @param  array<string, mixed>  $options
     */
    private function respond(string $body = '', int $status = 200, array $headers = [], array $options = []): ResponseInterface
    {
        Http::fake(['*' => Http::response($body, $status, $headers)]);

        return (new LaravelHttpClient())->request('GET', 'https://api.example.com/resource', $options);
    }

    public function testStatusHeadersAndContentAreReturned(): void
    {
        $response = $this->respond('{"ok":true,"big":12345678901234567890}', 200, ['Content-Type' => 'application/json', 'X-Request-Id' => ['a', 'b']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['application/json'], $response->getHeaders()['content-type']);
        $this->assertSame(['a', 'b'], $response->getHeaders()['x-request-id']);
        $this->assertSame('{"ok":true,"big":12345678901234567890}', $response->getContent());
        $this->assertSame(['ok' => true, 'big' => '12345678901234567890'], $response->toArray());
    }

    /**
     * @return array<string, array{0: int, 1: class-string<HttpExceptionInterface>}>
     */
    public static function errorStatuses(): array
    {
        return [
            '3xx' => [302, RedirectionException::class],
            '4xx' => [404, ClientException::class],
            '5xx' => [503, ServerException::class],
        ];
    }

    /**
     * @param  class-string<HttpExceptionInterface>  $exceptionClass
     */
    #[DataProvider('errorStatuses')]
    public function testErrorStatusesThrowUnlessThrowingIsTurnedOff(int $status, string $exceptionClass): void
    {
        $response = $this->respond('{"error":"nope"}', $status, ['Location' => '/elsewhere'], ['max_redirects' => 0]);

        foreach (['getHeaders', 'getContent', 'toArray'] as $method) {
            try {
                $response->{$method}();
                $this->fail(sprintf('%s() did not throw.', $method));
            } catch (HttpExceptionInterface $exception) {
                $this->assertInstanceOf($exceptionClass, $exception);
                $this->assertSame($response, $exception->getResponse());
                $this->assertSame(sprintf('HTTP %d returned for "https://api.example.com/[redacted]".', $status), $exception->getMessage());
            }
        }

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('{"error":"nope"}', $response->getContent(false));
        $this->assertSame(['error' => 'nope'], $response->toArray(false));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function undecodableBodies(): array
    {
        return [
            'empty' => ['', 'Response body is empty.'],
            'invalid JSON' => ['{nope', 'Syntax error for "https://api.example.com/[redacted]".'],
            'scalar JSON' => ['"text"', 'JSON content was expected to decode to an array, "string" returned for "https://api.example.com/[redacted]".'],
        ];
    }

    #[DataProvider('undecodableBodies')]
    public function testToArrayThrowsADecodingExceptionForBodiesThatAreNotAJsonArray(string $body, string $message): void
    {
        $response = $this->respond($body);

        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage($message);

        $response->toArray();
    }

    public function testAConnectionFailureIsThrownFromTheResponseNotTheRequest(): void
    {
        Http::fake(['*' => $this->connectionFailure('cURL error 7: Failed to connect to api.example.com')]);

        $response = (new LaravelHttpClient())->request('GET', 'https://api.example.com');

        $this->assertSame(0, $response->getInfo('http_code'));
        $this->assertSame('cURL error 7: Failed to connect to api.example.com', $response->getInfo('error'));

        foreach (['getStatusCode', 'getHeaders', 'getContent', 'toArray'] as $method) {
            try {
                $response->{$method}();
                $this->fail(sprintf('%s() did not throw.', $method));
            } catch (TransportException $exception) {
                $this->assertInstanceOf(TransportExceptionInterface::class, $exception);
                $this->assertSame('cURL error 7: Failed to connect to api.example.com', $exception->getMessage());
            }
        }
    }

    public function testACanceledResponseThrowsFromEveryMethodButGetInfo(): void
    {
        $response = $this->respond('body', 500);
        $response->cancel();

        $this->assertTrue($response->getInfo('canceled'));
        $this->assertSame('Response has been canceled.', $response->getInfo('error'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Response has been canceled.');

        $response->getContent(false);
    }

    public function testInfoHasEveryValueTheContractRequires(): void
    {
        $response = $this->respond('moved', 301, ['Location' => '/new-home', 'X-Thing' => 'a'], ['user_data' => ['tag' => 1], 'max_redirects' => 0]);
        $response->getStatusCode();
        $info = $response->getInfo();

        $this->assertIsArray($info);
        $this->assertFalse($info['canceled']);
        $this->assertNull($info['error']);
        $this->assertSame(301, $info['http_code']);
        $this->assertSame('GET', $info['http_method']);
        $this->assertSame(0, $info['redirect_count']);
        $this->assertSame('https://api.example.com/new-home', $info['redirect_url']);
        $this->assertSame('HTTP/1.1 301 Moved Permanently', $info['response_headers'][0]);
        $this->assertContains('X-Thing: a', $info['response_headers']);
        $this->assertIsFloat($info['start_time']);
        $this->assertSame('https://api.example.com/resource', $info['url']);
        $this->assertSame(['tag' => 1], $info['user_data']);
        $this->assertSame(301, $response->getInfo('http_code'));
        $this->assertNull($response->getInfo('not_a_real_key'));
    }

    public function testFollowedRedirectsAreCountedAndTheFinalUrlReported(): void
    {
        Http::fake([
            'https://api.example.com/old' => Http::response('', 301, ['Location' => '/new']),
            'https://api.example.com/new' => Http::response('done'),
        ]);

        $response = (new LaravelHttpClient())->request('GET', 'https://api.example.com/old', ['max_redirects' => 3]);

        $this->assertSame('done', $response->getContent());
        $this->assertSame(1, $response->getInfo('redirect_count'));
        $this->assertSame('https://api.example.com/new', $response->getInfo('url'));
        $this->assertArrayNotHasKey('x-guzzle-redirect-history', $response->getHeaders());
    }

    public function testErrorMessagesDoNotContainTheTokenInTheUrl(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $response = (new LaravelHttpClient())->request('POST', 'https://discord.com/api/webhooks/1/SECRET-TOKEN');

        try {
            $response->getContent();
            $this->fail('No exception was thrown.');
        } catch (ClientException $exception) {
            $this->assertSame('HTTP 404 returned for "https://discord.com/[redacted]".', $exception->getMessage());
        }
    }

}
