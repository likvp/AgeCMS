<?php
declare(strict_types=1);

namespace AgeCMS;

/** What a module sees of a request that matched one of its routes. Built by the router, read-only. */
final class ModuleRequest
{
    /**
     * @param array<string,string> $params values of {placeholders} in the route path
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body POST fields without the form token fields; empty for GET
     * @param array<string,string> $cookies cookies of this module (names without the module prefix)
     */
    public function __construct(
        public readonly string $module,
        public readonly string $method,
        public readonly string $path,
        public readonly array $params,
        public readonly array $query,
        public readonly array $body,
        public readonly string $ip,
        public readonly array $cookies,
    ) {
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function cookie(string $name): string
    {
        return $this->cookies[$name] ?? '';
    }
}
