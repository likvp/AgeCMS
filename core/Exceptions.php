<?php
declare(strict_types=1);

namespace AgeCMS;

class AgeCMSException extends \RuntimeException
{
    /** Short machine name used in queue results and API errors. */
    public function kind(): string
    {
        return 'error';
    }
}

final class NotInstalledException extends AgeCMSException
{
    public function kind(): string
    {
        return 'not_installed';
    }
}

final class ValidationException extends AgeCMSException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }

    public function kind(): string
    {
        return 'validation';
    }
}

final class NotFoundException extends AgeCMSException
{
    public function kind(): string
    {
        return 'not_found';
    }
}

final class ForbiddenException extends AgeCMSException
{
    public function kind(): string
    {
        return 'forbidden';
    }
}

final class FrozenException extends AgeCMSException
{
    public function kind(): string
    {
        return 'frozen';
    }
}

final class CircuitBreakerException extends AgeCMSException
{
    public function kind(): string
    {
        return 'circuit_breaker';
    }
}

/** A scheduled task (Scheduler) asks for its next attempt after so many seconds: the other side said "wait" (Retry-After). */
final class RetryLaterException extends AgeCMSException
{
    public function __construct(string $message, public readonly int $seconds)
    {
        parent::__construct($message);
    }

    public function kind(): string
    {
        return 'retry_later';
    }
}

/** A scheduled task (Scheduler) says that another attempt is pointless: the person blocked the bot, the order is gone. */
final class GiveUpException extends AgeCMSException
{
    public function kind(): string
    {
        return 'give_up';
    }
}

final class Exceptions
{
    /** Rebuilds an exception from the kind/message pair stored in a queue result. */
    public static function rebuild(string $kind, string $message): AgeCMSException
    {
        return match ($kind) {
            'validation' => new ValidationException([$message]),
            'not_found' => new NotFoundException($message),
            'forbidden' => new ForbiddenException($message),
            'frozen' => new FrozenException($message),
            'circuit_breaker' => new CircuitBreakerException($message),
            default => new AgeCMSException($message),
        };
    }
}
