<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier;

use Closure;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * A transport that is only built when it is first used, so resolving a Chatter or Texter, or
 * configuring a transport whose DSN is wrong, does not fail until a message actually needs it.
 *
 * @internal
 */
final class LazyTransport implements TransportInterface
{
    /**
     * The transport, once built.
     */
    private ?TransportInterface $transport = null;

    /**
     * Create a lazy transport.
     *
     * @param  Closure(): TransportInterface  $build
     */
    public function __construct(private readonly Closure $build)
    {
    }

    /**
     * Send a message through the transport.
     */
    public function send(MessageInterface $message): ?SentMessage
    {
        return $this->transport()->send($message);
    }

    /**
     * Determine whether the transport can send the message.
     */
    public function supports(MessageInterface $message): bool
    {
        return $this->transport()->supports($message);
    }

    /**
     * Get the transport's name, as Symfony records it on sent messages.
     */
    public function __toString(): string
    {
        return (string) $this->transport();
    }

    /**
     * Build the transport the first time it is needed.
     */
    private function transport(): TransportInterface
    {
        return $this->transport ??= ($this->build)();
    }
}
