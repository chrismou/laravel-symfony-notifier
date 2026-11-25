<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Exception;

use Throwable;

/**
 * Marker for every exception the package throws outside the HTTP client, which throws the
 * Symfony HttpClient contract exceptions instead.
 */
interface ExceptionInterface extends Throwable
{
}
