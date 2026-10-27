<?php
declare(strict_types=1);

namespace AgeCMS;

/** Entry point of an installed module. Only loaded after its manifest signature and file hashes check out. */
interface ModuleInterface
{
    /** Subscribe to events here (EventBus::listen). */
    public function register(): void;

    /**
     * Runs one of the module's skills.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function callSkill(string $skill, array $args, Actor $actor): array;
}
