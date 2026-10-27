<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Events for modules and declarative integrations. A listener or webhook failure is logged and
 * never reaches the code that raised the event.
 *
 * Declarative webhooks are settings rows named integration_<name> holding JSON:
 *   {"event": "lead.created", "webhook_url": "https://...", "payload_template": {"text": "New lead {{name}}"}}
 */
final class EventBus
{
    /** @var array<string,list<callable>> */
    private static array $listeners = [];

    public static function listen(string $event, callable $callback): void
    {
        self::$listeners[$event][] = $callback;
    }

    public static function reset(): void
    {
        self::$listeners = [];
    }

    /** @param array<string,mixed> $payload */
    public static function trigger(string $event, array $payload = []): void
    {
        SkillRegistry::boot();
        foreach (self::$listeners[$event] ?? [] as $callback) {
            try {
                $callback($payload);
            } catch (Throwable $e) {
                Logger::log('error', 'event.listener_failed', ['event' => $event, 'error' => $e->getMessage()]);
            }
        }
        if ($event === Background::TICK_EVENT) {
            return; // fires every 30 seconds: only modules listen, never an HTTP webhook
        }
        try {
            $rows = Database::rows("SELECT key, value FROM settings WHERE key LIKE 'integration\_%' ESCAPE '\\'");
        } catch (Throwable) {
            return;
        }
        foreach ($rows as $row) {
            $config = json_decode((string)$row['value'], true);
            if (is_array($config) && ($config['event'] ?? null) === $event && is_string($config['webhook_url'] ?? null)) {
                self::webhook($row['key'], $config, $payload);
            }
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $payload
     */
    private static function webhook(string $name, array $config, array $payload): void
    {
        $body = self::fill((array)($config['payload_template'] ?? $payload), $payload);
        $result = Http::postJson($config['webhook_url'], $body);
        if ($result === null || $result['status'] >= 300) {
            Logger::log('warning', 'event.webhook_failed', ['integration' => $name, 'status' => $result['status'] ?? null]);
        }
    }

    /**
     * Replaces {{key}} inside string values only, so a payload value can never change the JSON structure.
     *
     * @param array<string|int,mixed> $template
     * @param array<string,mixed> $payload
     * @return array<string|int,mixed>
     */
    public static function fill(array $template, array $payload): array
    {
        $out = [];
        foreach ($template as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::fill($value, $payload);
            } elseif (is_string($value)) {
                $out[$key] = preg_replace_callback(
                    '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
                    static fn(array $m): string => isset($payload[$m[1]]) && is_scalar($payload[$m[1]]) ? (string)$payload[$m[1]] : '',
                    $value,
                );
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
