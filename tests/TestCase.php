<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Tests;

use Chrismou\LaravelSymfonyNotifier\NotifierServiceProvider;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Boots a Laravel app with the package registered and stray HTTP requests prevented, so every
 * test proves its requests went through Laravel's HTTP client.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Prevent stray requests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Get the package's service providers.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [NotifierServiceProvider::class];
    }

    /**
     * Build a fake that fails the connection, as an unreachable host would.
     *
     * @return Closure(Request): PromiseInterface
     */
    protected function connectionFailure(string $message = 'cURL error 7: Failed to connect'): Closure
    {
        return static fn (Request $request): PromiseInterface => Create::rejectionFor(new ConnectException($message, $request->toPsrRequest()));
    }
}
