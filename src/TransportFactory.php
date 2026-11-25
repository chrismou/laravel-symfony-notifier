<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier;

use Chrismou\LaravelSymfonyNotifier\Exception\InvalidDsnException;
use Chrismou\LaravelSymfonyNotifier\Support\Redactor;
use SensitiveParameter;
use Symfony\Component\Notifier\Exception\InvalidArgumentException as NotifierInvalidArgumentException;
use Symfony\Component\Notifier\Exception\LogicException as NotifierLogicException;
use Symfony\Component\Notifier\Transport;
use Symfony\Component\Notifier\Transport\FailoverTransport;
use Symfony\Component\Notifier\Transport\RoundRobinTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds Notifier transports from DSNs, with every bridge sending through the given HTTP client
 * (the Laravel one when resolved from the container). Any installed bridge is supported, as are
 * Symfony's "||" (failover) and "&&" (round robin) DSNs and the null://null transport.
 */
final class TransportFactory
{
    /**
     * Create a factory.
     *
     * @param  list<string>|null  $allowedSchemes  The DSN schemes that may be built, or null for any installed bridge
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?array $allowedSchemes = null,
    ) {
    }

    /**
     * Build a transport from a DSN such as "slack://TOKEN@default?channel=C0123".
     *
     * Failover and round-robin DSNs are split here, as Symfony's Transport::fromString() does,
     * because Symfony's static Transport::fromDsn() can only build one transport from its bridge
     * factories before they run out.
     *
     * @throws InvalidDsnException when the DSN is invalid, its scheme is not allowed or its bridge is not installed
     */
    public function fromDsn(#[SensitiveParameter] string $dsn): TransportInterface
    {
        $failover = preg_split('/\s++\|\|\s++/', $dsn) ?: [$dsn];

        if (count($failover) > 1) {
            return new FailoverTransport(array_map($this->single(...), $failover));
        }

        $roundRobin = preg_split('/\s++&&\s++/', $dsn) ?: [$dsn];

        if (count($roundRobin) > 1) {
            return new RoundRobinTransport(array_map($this->single(...), $roundRobin));
        }

        return $this->single($dsn);
    }

    /**
     * Build the transport for a single DSN. Symfony's exceptions quote the whole DSN, credentials
     * included, so they are rethrown with only the scheme and host, and without chaining them.
     *
     * @throws InvalidDsnException
     */
    private function single(#[SensitiveParameter] string $dsn): TransportInterface
    {
        $scheme = strtolower((string) strstr($dsn, '://', true));

        if ($this->allowedSchemes !== null && ! in_array($scheme, $this->allowedSchemes, true)) {
            throw new InvalidDsnException(sprintf('The "%s" scheme is not allowed by notifier.allowed_schemes.', $scheme));
        }

        try {
            return Transport::fromDsn($dsn, $this->dispatcher, $this->client);
        } catch (NotifierInvalidArgumentException|NotifierLogicException $exception) {
            throw new InvalidDsnException(sprintf(
                'The notifier DSN "%s" could not be used: %s',
                Redactor::url($dsn),
                Redactor::message($exception->getMessage(), $dsn),
            ));
        }
    }
}
