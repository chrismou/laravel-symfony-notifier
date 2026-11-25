<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches Symfony Notifier's events (MessageEvent, SentMessageEvent, FailedMessageEvent)
 * through Laravel's event dispatcher, so they can be handled with Event::listen().
 *
 * Without a dispatcher, the container's is resolved for each event, so Event::fake() applies even
 * when it is called after the transports were built.
 */
final readonly class LaravelEventDispatcher implements EventDispatcherInterface
{
    /**
     * Create a dispatcher.
     */
    public function __construct(private ?Dispatcher $events = null)
    {
    }

    /**
     * Dispatch an event to its Laravel listeners. A name, when given, is used instead of the class.
     *
     * @template T of object
     *
     * @param  T  $event
     * @return T
     */
    public function dispatch(object $event, ?string $eventName = null): object
    {
        $events = $this->events ?? Container::getInstance()->make(Dispatcher::class);

        $eventName === null ? $events->dispatch($event) : $events->dispatch($eventName, [$event]);

        return $event;
    }
}
