<?php
declare(strict_types=1);

namespace AgeCMS\Modules\TelegramLeads;

use AgeCMS\Actor;
use AgeCMS\AgeCMSException;
use AgeCMS\Config;
use AgeCMS\EventBus;
use AgeCMS\Http;
use AgeCMS\ModuleInterface;

/**
 * Sends new leads and circuit breaker alerts to a Telegram chat.
 * The bot token and chat id live in data/config.php (secrets.telegram_bot_token, secrets.telegram_chat_id),
 * set with: php bin/config.php set secrets.telegram_bot_token <token>
 */
final class Module implements ModuleInterface
{
    public function register(): void
    {
        EventBus::listen('lead.created', function (array $lead): void {
            $text = "Новая заявка\n";
            $text .= ($lead['name'] ?? '') !== '' ? "Имя: {$lead['name']}\n" : '';
            $text .= ($lead['phone'] ?? '') !== '' ? "Телефон: {$lead['phone']}\n" : '';
            $text .= ($lead['message'] ?? '') !== '' ? "Сообщение: {$lead['message']}\n" : '';
            $text .= ($lead['page'] ?? '') !== '' ? "Страница: /{$lead['page']}" : '';
            $this->send(trim($text));
        });
        EventBus::listen('circuit_breaker.tripped', function (array $event): void {
            $this->send("Сайт заморожен предохранителем.\nАгент: {$event['actor']}\n{$event['reason']}\nСнять заморозку: php bin/unfreeze.php");
        });
    }

    public function callSkill(string $skill, array $args, Actor $actor): array
    {
        return match ($skill) {
            'send_test_message' => ['sent' => $this->send('Проверка связи: AgeCMS подключена к этому чату.', true)],
            'status' => ['configured' => $this->configured()],
            default => throw new AgeCMSException("Unknown skill {$skill}"),
        };
    }

    private function configured(): bool
    {
        return (string)Config::get('secrets.telegram_bot_token', '') !== '' && (string)Config::get('secrets.telegram_chat_id', '') !== '';
    }

    private function send(string $text, bool $throw = false): bool
    {
        if (!$this->configured()) {
            if ($throw) {
                throw new AgeCMSException('Telegram is not configured: set secrets.telegram_bot_token and secrets.telegram_chat_id with bin/config.php');
            }
            return false;
        }
        $token = (string)Config::get('secrets.telegram_bot_token');
        $result = Http::postJson("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => (string)Config::get('secrets.telegram_chat_id'),
            'text' => mb_substr($text, 0, 3500),
            'disable_web_page_preview' => true,
        ]);
        $ok = $result !== null && $result['status'] === 200;
        if (!$ok && $throw) {
            throw new AgeCMSException('Telegram rejected the message (HTTP ' . ($result['status'] ?? 'no response') . ')');
        }
        return $ok;
    }
}
