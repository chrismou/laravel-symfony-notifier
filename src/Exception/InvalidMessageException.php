<?php

declare(strict_types=1);

namespace Chrismou\LaravelSymfonyNotifier\Exception;

use LogicException;

/**
 * Thrown when a notification sent on the chat or sms channel has no toChat()/toSms() method, or it
 * returns something that is not a message.
 */
final class InvalidMessageException extends LogicException implements ExceptionInterface
{
}
