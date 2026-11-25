<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Support;

use SensitiveParameter;

/**
 * Hides credentials in DSNs and URLs before they go into exception messages. Chat apps put
 * tokens in places that are normally safe to log: in the userinfo of a DSN, in the path of a
 * webhook URL (Discord, Telegram) and in the query string (Teams workflow signatures), so only
 * the scheme and host are kept.
 *
 * @internal
 */
final class Redactor
{
    /**
     * Matches anything that looks like a URL or DSN in a message.
     */
    private const URL_PATTERN = '~\b[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~i';

    /**
     * Reduce a URL or DSN to its scheme and host, marking where the rest was removed.
     */
    public static function url(#[SensitiveParameter] string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) strstr($url, '://', true));

        if ($parts === false || ! isset($parts['host'])) {
            return $scheme === '' ? '[redacted]' : $scheme.'://[redacted]';
        }

        $redacted = ($parts['scheme'] ?? $scheme).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '';
        $hasMore = isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($path !== '' && $path !== '/');

        return $hasMore ? $redacted.'/[redacted]' : $redacted;
    }

    /**
     * Redact every URL or DSN in a message, along with a known secret wherever it appears.
     */
    public static function message(string $message, #[SensitiveParameter] ?string $secret = null): string
    {
        if ($secret !== null && $secret !== '') {
            $message = str_replace($secret, self::url($secret), $message);
        }

        return (string) preg_replace_callback(self::URL_PATTERN, static fn (array $match): string => self::url($match[0]), $message);
    }
}
