<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier;

use Chrismou\LaravelSymfonyNotifier\Channels\ChatChannel;
use Chrismou\LaravelSymfonyNotifier\Channels\SmsChannel;
use Chrismou\LaravelSymfonyNotifier\Http\LaravelHttpClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Notifier\Chatter;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Texter;
use Symfony\Component\Notifier\TexterInterface;

/**
 * Registers the "chat" and "sms" notification channels, the transport factory, and Chatter and
 * Texter bindings for the configured transports, all sending through Laravel's HTTP client.
 *
 * Symfony's HttpClientInterface is deliberately not bound globally, so other code that expects
 * Symfony's own client is unaffected; resolve LaravelHttpClient to use it elsewhere.
 */
final class NotifierServiceProvider extends ServiceProvider
{
    /**
     * Register the bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/notifier.php', 'notifier');

        $this->app->singleton(LaravelHttpClient::class);
        $this->app->singleton(LaravelEventDispatcher::class, static fn (): LaravelEventDispatcher => new LaravelEventDispatcher());

        $this->app->singleton(TransportFactory::class, static function (Container $app): TransportFactory {
            $allowedSchemes = $app->make(Repository::class)->get('notifier.allowed_schemes');

            return new TransportFactory(
                $app->make(LaravelHttpClient::class),
                $app->make(LaravelEventDispatcher::class),
                is_array($allowedSchemes) ? array_values(array_map(static fn (mixed $scheme): string => strtolower((string) $scheme), $allowedSchemes)) : null,
            );
        });

        $this->app->singleton(TransportRegistry::class, static function (Container $app): TransportRegistry {
            $config = $app->make(Repository::class);

            return new TransportRegistry(
                $app->make(TransportFactory::class),
                (array) $config->get('notifier.chatter_transports', []),
                (array) $config->get('notifier.texter_transports', []),
            );
        });

        // The transports are only built when a message is sent, so injecting a Chatter or Texter
        // never fails just because none are configured.
        $this->app->singleton(ChatterInterface::class, static fn (Container $app): ChatterInterface => new Chatter(
            new LazyTransport(static fn () => $app->make(TransportRegistry::class)->chatter()),
            null,
            $app->make(LaravelEventDispatcher::class),
        ));

        $this->app->singleton(TexterInterface::class, static fn (Container $app): TexterInterface => new Texter(
            new LazyTransport(static fn () => $app->make(TransportRegistry::class)->texter()),
            null,
            $app->make(LaravelEventDispatcher::class),
        ));
    }

    /**
     * Register the notification channels and publish the config.
     */
    public function boot(): void
    {
        $this->callAfterResolving(ChannelManager::class, static function (ChannelManager $channels): void {
            $channels->extend('chat', fn (Container $app): ChatChannel => $app->make(ChatChannel::class));
            $channels->extend('sms', fn (Container $app): SmsChannel => $app->make(SmsChannel::class));
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/notifier.php' => $this->app->configPath('notifier.php'),
            ], 'notifier-config');
        }
    }
}
