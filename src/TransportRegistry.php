<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier;

use Chrismou\LaravelSymfonyNotifier\Exception\InvalidDsnException;
use Chrismou\LaravelSymfonyNotifier\Exception\MissingTransportException;
use SensitiveParameter;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Component\Notifier\Transport\Transports;

/**
 * The chat and SMS transports named in the notifier config. Each is built the first time it is
 * used, so one wrong DSN only affects messages sent through it. A message picks one by name with
 * transport(); otherwise the first that supports it is used.
 */
final class TransportRegistry
{
    /**
     * The transports, keyed by "chatter" or "texter".
     *
     * @var array<string, Transports>
     */
    private array $transports = [];

    /**
     * Create a registry.
     *
     * @param  array<array-key, mixed>  $chatterDsns  Transport name => DSN
     * @param  array<array-key, mixed>  $texterDsns  Transport name => DSN
     */
    public function __construct(
        private readonly TransportFactory $factory,
        #[SensitiveParameter] private readonly array $chatterDsns,
        #[SensitiveParameter] private readonly array $texterDsns,
    ) {
    }

    /**
     * Get the configured chat transports.
     *
     * @throws MissingTransportException|InvalidDsnException
     */
    public function chatter(): TransportInterface
    {
        return $this->transports['chatter'] ??= $this->build($this->chatterDsns, 'chatter_transports');
    }

    /**
     * Get the configured SMS transports.
     *
     * @throws MissingTransportException|InvalidDsnException
     */
    public function texter(): TransportInterface
    {
        return $this->transports['texter'] ??= $this->build($this->texterDsns, 'texter_transports');
    }

    /**
     * Determine whether a chat transport with the given name is configured.
     */
    public function hasChatter(string $name): bool
    {
        return is_string($this->chatterDsns[$name] ?? null) && $this->chatterDsns[$name] !== '';
    }

    /**
     * Determine whether an SMS transport with the given name is configured.
     */
    public function hasTexter(string $name): bool
    {
        return is_string($this->texterDsns[$name] ?? null) && $this->texterDsns[$name] !== '';
    }

    /**
     * Set up the named transports, skipping any whose DSN is empty (an unset environment
     * variable). Each is only built when a message first needs it.
     *
     * @param  array<array-key, mixed>  $dsns
     *
     * @throws MissingTransportException|InvalidDsnException
     */
    private function build(#[SensitiveParameter] array $dsns, string $configKey): Transports
    {
        $transports = [];

        foreach ($dsns as $name => $dsn) {
            if ($dsn === null || $dsn === '' || $dsn === false) {
                continue;
            }

            if (! is_string($dsn)) {
                throw new InvalidDsnException(sprintf('The "%s" transport in notifier.%s must be a DSN string, "%s" given.', $name, $configKey, get_debug_type($dsn)));
            }

            $transports[(string) $name] = new LazyTransport(fn (): TransportInterface => $this->factory->fromDsn($dsn));
        }

        if ($transports === []) {
            throw new MissingTransportException(sprintf('No transports are configured in notifier.%s, so the message has nowhere to go. Add a DSN there or route the notifiable to one.', $configKey));
        }

        return new Transports($transports);
    }
}
