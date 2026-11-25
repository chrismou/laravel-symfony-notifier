<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests\Unit\Http;

use BadMethodCallException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\InvalidArgumentException;
use Chrismou\LaravelSymfonyNotifier\Http\Exception\TransportException;
use Chrismou\LaravelSymfonyNotifier\Http\LaravelHttpClient;
use Chrismou\LaravelSymfonyNotifier\Tests\TestCase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Stringable;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class LaravelHttpClientTest extends TestCase
{
    /**
     * Send a request with the given options and return the request Laravel recorded and the
     * Guzzle options it was sent with.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: Request, 1: array<string, mixed>}
     */
    private function send(string $method, string $url, array $options = [], ?LaravelHttpClient $client = null): array
    {
        $sent = null;

        Http::fake(function (Request $request, array $guzzleOptions) use (&$sent) {
            $sent = [$request, $guzzleOptions];

            return Http::response('ok');
        });

        ($client ?? new LaravelHttpClient())->request($method, $url, $options)->getStatusCode();

        $this->assertNotNull($sent, 'No request was sent.');

        return $sent;
    }

    public function testJsonIsEncodedLikeSymfonyWithJsonHeaders(): void
    {
        [$request] = $this->send('POST', 'https://api.example.com/messages', ['json' => ['text' => '<a href="x">\'&\'</a>', 'ratio' => 1.0]]);

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://api.example.com/messages', $request->url());
        $this->assertSame(
            json_encode(['text' => '<a href="x">\'&\'</a>', 'ratio' => 1.0], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION),
            $request->body(),
        );
        $this->assertStringNotContainsString('<', $request->body());
        $this->assertSame(['application/json'], $request->toPsrRequest()->getHeader('Content-Type'));
        $this->assertSame(['*/*'], $request->toPsrRequest()->getHeader('Accept'));
    }

    public function testAnExplicitContentTypeOrAcceptHeaderIsKeptWhateverItsCase(): void
    {
        [$request] = $this->send('POST', 'https://api.example.com', [
            'json' => ['a' => 1],
            'headers' => ['content-type' => 'application/vnd.api+json', 'accept: text/plain'],
        ]);

        $this->assertSame(['application/vnd.api+json'], $request->toPsrRequest()->getHeader('Content-Type'));
        $this->assertSame(['text/plain'], $request->toPsrRequest()->getHeader('Accept'));
    }

    public function testAFormArrayBodyIsUrlEncoded(): void
    {
        [$request] = $this->send('POST', 'https://api.example.com', ['body' => ['To' => '+447700900000', 'Body' => 'Hi & bye']]);

        $this->assertSame('To=%2B447700900000&Body=Hi+%26+bye', $request->body());
        $this->assertSame(['application/x-www-form-urlencoded'], $request->toPsrRequest()->getHeader('Content-Type'));
    }

    public function testARawBodyIsSentWithoutLaravelsDefaultJsonContentType(): void
    {
        [$request] = $this->send('PUT', 'https://api.example.com', ['body' => 'plain text']);

        $this->assertSame('plain text', $request->body());
        $this->assertFalse($request->hasHeader('Content-Type'));
    }

    public function testARequestWithoutABodyHasNoContentType(): void
    {
        [$request] = $this->send('GET', 'https://api.example.com');

        $this->assertSame('', $request->body());
        $this->assertFalse($request->hasHeader('Content-Type'));
    }

    public function testHeadersInEveryFormAreSent(): void
    {
        [$request] = $this->send('GET', 'https://api.example.com', ['headers' => [
            'X-One' => 'a',
            'X-Many' => ['b', 'c'],
            'X-Line: d',
        ]]);

        $this->assertSame(['a'], $request->toPsrRequest()->getHeader('X-One'));
        $this->assertSame(['b', 'c'], $request->toPsrRequest()->getHeader('X-Many'));
        $this->assertSame(['d'], $request->toPsrRequest()->getHeader('X-Line'));
    }

    public function testBearerAndBasicAuthBecomeAnAuthorizationHeader(): void
    {
        [$bearer] = $this->send('GET', 'https://api.example.com', ['auth_bearer' => 'xoxb-123']);
        [$basic] = $this->send('GET', 'https://api.example.com', ['auth_basic' => ['AC123', 'secret']]);
        [$basicString] = $this->send('GET', 'https://api.example.com', ['auth_basic' => 'user:pass']);

        $this->assertSame(['Bearer xoxb-123'], $bearer->header('Authorization'));
        $this->assertSame(['Basic '.base64_encode('AC123:secret')], $basic->header('Authorization'));
        $this->assertSame(['Basic '.base64_encode('user:pass')], $basicString->header('Authorization'));
    }

    public function testQueryIsAppendedToTheUrlWithoutItsFragment(): void
    {
        [$request] = $this->send('GET', 'https://api.example.com/path?keep=1#fragment', ['query' => ['added' => 'a value', 'skipped' => null]]);
        [$withoutQuery] = $this->send('GET', 'https://api.example.com/path', ['query' => ['first' => '1']]);

        $this->assertSame('https://api.example.com/path?keep=1&added=a%20value', $request->url());
        $this->assertSame('https://api.example.com/path?first=1', $withoutQuery->url());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'unknown option' => ['GET', 'https://example.com', ['timout' => 1], 'Unsupported option "timout"'],
            'unsupported option' => ['GET', 'https://example.com', ['bindto' => '127.0.0.1'], 'The "bindto" option is not supported'],
            'negative timeout' => ['GET', 'https://example.com', ['timeout' => -1], 'Option "timeout" must be a non-negative number'],
            'json and body' => ['POST', 'https://example.com', ['json' => [], 'body' => 'x'], 'Define either the "json" or the "body" option'],
            'basic and bearer' => ['GET', 'https://example.com', ['auth_basic' => 'a:b', 'auth_bearer' => 'c'], 'Define either the "auth_basic" or the "auth_bearer" option'],
            'lowercase method' => ['get', 'https://example.com', [], 'Invalid HTTP method "get"'],
            'relative URL' => ['GET', '/path', [], 'an absolute http or https URL is required'],
            'unsupported scheme' => ['GET', 'ftp://example.com', [], 'an absolute http or https URL is required'],
            'header injection' => ['GET', 'https://example.com', ['headers' => ['X-A' => "a\r\nX-B: b"]], 'CR, LF and NUL are not allowed'],
            'unencodable json' => ['POST', 'https://example.com', ['json' => NAN], 'Invalid value for "json" option'],
            'file upload' => ['POST', 'https://example.com', ['body' => ['file' => new stdClass()]], 'File uploads are not supported'],
            'proxy' => ['GET', 'https://example.com', ['proxy' => 'http://proxy.internal:3128'], 'The "proxy" option is not supported'],
            'base_uri' => ['GET', 'path', ['base_uri' => 'https://example.com/'], 'The "base_uri" option is not supported'],
            'resource body' => ['POST', 'https://example.com', ['body' => STDIN], 'Option "body" must be a string or a form array'],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsThrowATransportExceptionBeforeSending(string $method, string $url, array $options, string $message): void
    {
        Http::fake();

        try {
            (new LaravelHttpClient())->request($method, $url, $options);
            $this->fail('No exception was thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertInstanceOf(TransportExceptionInterface::class, $exception);
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function testUnsupportedOptionsAreAcceptedAtTheirDefaultValues(): void
    {
        [$request] = $this->send('GET', 'https://api.example.com', ['bindto' => '0', 'capture_peer_cert_chain' => false, 'ciphers' => null]);

        $this->assertSame('https://api.example.com', $request->url());
    }

    public function testRedirectAndTimeoutOptionsAreTranslatedToGuzzleOptions(): void
    {
        [, $limited] = $this->send('GET', 'https://api.example.com', ['max_redirects' => 3, 'timeout' => 2.5]);
        [, $none] = $this->send('GET', 'https://api.example.com', ['max_redirects' => 0, 'timeout' => 2.5, 'max_duration' => 10]);

        $this->assertSame(3, $limited['allow_redirects']['max']);
        $this->assertSame(['http', 'https'], $limited['allow_redirects']['protocols']);
        $this->assertSame(2.5, $limited['timeout']);
        $this->assertFalse($none['allow_redirects']);
        $this->assertSame(10.0, $none['timeout']);
    }

    public function testStreamingIsNotSupported(): void
    {
        $this->expectException(BadMethodCallException::class);

        (new LaravelHttpClient())->stream([]);
    }

    public function testOptionsThatAreNotPassedKeepLaravelsDefaultsAndGlobalOptions(): void
    {
        Http::globalOptions(['allow_redirects' => false, 'timeout' => 7]);

        [, $options] = $this->send('GET', 'https://api.example.com');

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(7, $options['timeout']);
        $this->assertSame(10, $options['connect_timeout']);
    }

    public function testDefaultOptionsAreMergedIntoRequestsButQueryIsNotInherited(): void
    {
        $client = (new LaravelHttpClient())->withOptions([
            'headers' => ['X-Default' => 'yes', 'X-Replaced' => 'default'],
            'query' => ['from_defaults' => '1'],
            'timeout' => 4,
        ]);

        [$request, $options] = $this->send('GET', 'https://api.example.com/path', ['headers' => ['x-replaced' => 'request']], $client);

        $this->assertSame('https://api.example.com/path', $request->url());
        $this->assertSame(['yes'], $request->toPsrRequest()->getHeader('X-Default'));
        $this->assertSame(['request'], $request->toPsrRequest()->getHeader('X-Replaced'));
        $this->assertSame(4.0, $options['timeout']);
    }

    public function testWithOptionsRejectsUnsupportedOptionsAndLeavesTheOriginalClientUnchanged(): void
    {
        $client = new LaravelHttpClient();

        try {
            $client->withOptions(['peer_fingerprint' => ['sha256' => 'abc']]);
            $this->fail('No exception was thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('"peer_fingerprint" option is not supported', $exception->getMessage());
        }

        $configured = $client->withOptions(['headers' => ['X-Configured' => '1']]);
        [$fromOriginal] = $this->send('GET', 'https://api.example.com', [], $client);
        [$fromConfigured] = $this->send('GET', 'https://api.example.com', [], $configured);

        $this->assertFalse($fromOriginal->hasHeader('X-Configured'));
        $this->assertTrue($fromConfigured->hasHeader('X-Configured'));
    }

    public function testPendingRequestCallbacksCanAdjustTheRequestBeforeItIsSent(): void
    {
        $received = null;
        $client = (new LaravelHttpClient())->withPendingRequest(function (PendingRequest $request, string $method, string $url, array $options) use (&$received): PendingRequest {
            $received = [$method, $url, $options['user_data'] ?? null];

            return $request->withOptions(['timeout' => 3]);
        });

        [, $options] = $this->send('POST', 'https://api.example.com', ['user_data' => 'tag'], $client);

        $this->assertSame(['POST', 'https://api.example.com', 'tag'], $received);
        $this->assertSame(3, $options['timeout']);
    }

    public function testStrayRequestsArePrevented(): void
    {
        $this->expectException(StrayRequestException::class);

        (new LaravelHttpClient())->request('GET', 'https://not-faked.example.com');
    }

    public function testLaravelHttpClientEventsAreDispatched(): void
    {
        $dispatched = [];
        Event::listen([RequestSending::class, ResponseReceived::class], static function (RequestSending|ResponseReceived $event) use (&$dispatched): void {
            $dispatched[] = [$event::class, $event->request->url()];
        });
        Http::fake(['*' => Http::response('ok')]);

        (new LaravelHttpClient())->request('GET', 'https://api.example.com')->getStatusCode();

        $this->assertSame([
            [RequestSending::class, 'https://api.example.com'],
            [ResponseReceived::class, 'https://api.example.com'],
        ], $dispatched);
    }

    public function testAGivenFactoryIsUsedInsteadOfTheContainers(): void
    {
        $factory = new Factory();
        $factory->fake(['*' => $factory->response('from the given factory')]);

        $response = (new LaravelHttpClient($factory))->request('GET', 'https://api.example.com');

        $this->assertSame('from the given factory', $response->getContent());
        $factory->assertSentCount(1);
    }

    public function testStringableFormValuesAreSentAsText(): void
    {
        [$request] = $this->send('POST', 'https://api.example.com', ['body' => ['To' => new Stringable('+4477'), 'Body' => 'x']]);

        $this->assertSame('To=%2B4477&Body=x', $request->body());
    }

    public function testAFormBodyIsAlwaysSentAsAFormAndMultipartIsRefused(): void
    {
        [$request] = $this->send('POST', 'https://api.example.com', ['body' => ['a' => 'b'], 'headers' => ['Content-Type' => 'application/json']]);

        $this->assertSame(['application/x-www-form-urlencoded'], $request->toPsrRequest()->getHeader('Content-Type'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multipart bodies are not supported');

        (new LaravelHttpClient())->request('POST', 'https://api.example.com', ['body' => ['a' => 'b'], 'headers' => ['Content-Type' => 'multipart/form-data']]);
    }

    public function testAnExplicitAuthorizationHeaderWinsOverTheAuthOptions(): void
    {
        [$request] = $this->send('GET', 'https://api.example.com', ['auth_bearer' => 'token', 'headers' => ['Authorization' => 'Basic abc']]);
        [$singleUser] = $this->send('GET', 'https://api.example.com', ['auth_basic' => ['user']]);

        $this->assertSame(['Basic abc'], $request->toPsrRequest()->getHeader('Authorization'));
        $this->assertSame(['Basic '.base64_encode('user')], $singleUser->toPsrRequest()->getHeader('Authorization'));
    }

    public function testQueryReplacesAndRemovesParametersAlreadyInTheUrl(): void
    {
        [$request] = $this->send('GET', 'https://api.example.com/path?a=1&b=1&keep=1', ['query' => ['a' => '2', 'b' => null]]);

        $this->assertSame('https://api.example.com/path?a=2&keep=1', $request->url());
    }

    public function testANullHeaderRemovesADefaultAndFalseIsSentEmpty(): void
    {
        $client = (new LaravelHttpClient())->withOptions(['headers' => ['X-Default' => 'yes', 'X-Kept' => 'yes']]);

        [$request] = $this->send('GET', 'https://api.example.com', ['headers' => ['X-Default' => null, 'X-Empty' => false]], $client);

        $this->assertFalse($request->hasHeader('X-Default'));
        $this->assertSame(['yes'], $request->toPsrRequest()->getHeader('X-Kept'));
        $this->assertSame([''], $request->toPsrRequest()->getHeader('X-Empty'));
    }

    public function testAContentTypeSetByAPendingRequestCallbackIsKept(): void
    {
        $client = (new LaravelHttpClient())->withPendingRequest(static fn (PendingRequest $request): PendingRequest => $request->withHeaders(['Content-Type' => 'text/plain']));

        [$request] = $this->send('POST', 'https://api.example.com', ['body' => 'plain'], $client);

        $this->assertSame(['text/plain'], $request->toPsrRequest()->getHeader('Content-Type'));
    }

    public function testAPendingRequestCallbackCanRefuseARequestAndTheBridgeSeesATransportError(): void
    {
        Http::fake();
        $client = (new LaravelHttpClient())->withPendingRequest(static function (): never {
            throw new TransportException('Private addresses are not allowed.');
        });

        $response = $client->request('GET', 'https://internal.example.com');

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessage('Private addresses are not allowed.');

        try {
            $response->getStatusCode();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testUrlsInConnectionErrorsAreRedacted(): void
    {
        Http::fake(['*' => $this->connectionFailure('cURL error 6: Could not resolve host for https://api.telegram.org/bot123:SECRET/sendMessage')]);

        $response = (new LaravelHttpClient())->request('POST', 'https://api.telegram.org/bot123:SECRET/sendMessage');

        $this->assertSame('cURL error 6: Could not resolve host for https://api.telegram.org/[redacted]', $response->getInfo('error'));
    }

}
