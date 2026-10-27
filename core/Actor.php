<?php
declare(strict_types=1);

namespace AgeCMS;

/** Who performs a change: a person in the editor, an API agent, the CLI or the system itself. */
final class Actor
{
    public const TYPES = ['human', 'agent', 'system', 'cli'];

    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $role,
        public readonly ?string $session = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown actor type {$type}");
        }
    }

    public static function human(string $name, string $role = 'admin'): self
    {
        return new self('human', $name, $role);
    }

    public static function agent(string $name, string $role, ?string $session = null): self
    {
        return new self('agent', $name, $role, $session);
    }

    public static function cli(string $name = 'cli'): self
    {
        return new self('cli', $name, 'admin');
    }

    public static function system(string $name = 'system'): self
    {
        return new self('system', $name, 'admin');
    }

    public function can(string $permission): bool
    {
        return Security::can($this->role, $permission);
    }

    public function withSession(string $session): self
    {
        return new self($this->type, $this->name, $this->role, $session);
    }

    /** @return array{type:string,name:string,role:string,session:?string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'name' => $this->name, 'role' => $this->role, 'session' => $this->session];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self((string)($a['type'] ?? ''), (string)($a['name'] ?? ''), (string)($a['role'] ?? 'viewer'), isset($a['session']) ? (string)$a['session'] : null);
    }
}
