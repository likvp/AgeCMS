<?php
declare(strict_types=1);

namespace AgeCMS;

/** What a handler registered with Scheduler::on() receives: one run of one task. */
final class ScheduledTask
{
    /** @param array<string,mixed> $payload what the module stored with the task */
    public function __construct(
        public readonly int $id,
        public readonly string $module,
        public readonly string $key,
        public readonly string $handler,
        public readonly array $payload,
        /** 1 for the first try. */
        public readonly int $attempt,
        public readonly int $maxAttempts,
        /** Unix time the task was meant for; now may be later (see late()). */
        public readonly int $dueAt,
        /** Unix time after which the task has no point any more, null when it never expires. */
        public readonly ?int $expiresAt,
        /** microtime(true) the handler should finish by. */
        public readonly float $deadline,
        private readonly string $claim,
        private readonly int $version,
    ) {
    }

    /** How many seconds after its time the task runs. */
    public function late(): int
    {
        return max(0, time() - $this->dueAt);
    }

    /**
     * False when the module has replaced or cancelled the task meanwhile: a long handler asks before every next step.
     */
    public function current(): bool
    {
        return Scheduler::owns($this->id, $this->claim, $this->version);
    }

    /**
     * Long work: keeps the task for $seconds more, otherwise it is taken for abandoned when its lock time is over.
     * Returns the same as current().
     */
    public function extend(int $seconds): bool
    {
        return Scheduler::extend($this->id, $this->claim, $this->version, $seconds);
    }
}
