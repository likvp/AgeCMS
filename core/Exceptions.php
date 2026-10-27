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
