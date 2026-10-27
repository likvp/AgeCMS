<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Plain-text e-mail for system messages (password recovery). Transports: PHP mail(), an SMTP server
 * (SSL on 465, STARTTLS on 587, or none for a local relay) and 'log', which writes .eml files to
 * data/outbox for tests and local work. Settings live in data/config.php under 'mail'.
 */
final class Mailer
{
    public static function transport(): string
    {
        $t = (string)Config::get('mail.transport', 'mail');
        return in_array($t, ['mail', 'smtp', 'log'], true) ? $t : 'mail';
    }

    /** Sender address: the configured one, or noreply@ the site's domain. */
    public static function from(): string
    {
        $from = trim((string)Config::get('mail.from', ''));
        if ($from !== '') {
            return $from;
        }
        $host = (string)parse_url((string)Config::get('site_url', ''), PHP_URL_HOST);
        return $host !== '' ? 'noreply@' . $host : '';
    }

    public static function configured(): bool
    {
        if (filter_var(self::from(), FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        return self::transport() !== 'smtp' || (string)Config::get('mail.smtp.host', '') !== '';
    }

    /** @throws AgeCMSException when the message could not be handed over */
    public static function send(string $to, string $subject, string $body): void
    {
        $to = self::clean($to);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new AgeCMSException('Recipient address is not valid');
        }
        if (!self::configured()) {
            throw new AgeCMSException('Mail is not configured: set the sender address in Settings');
        }
        $from = self::from();
        $name = self::clean((string)Config::get('mail.from_name', '')) ?: (string)Config::get('site_name', 'AgeCMS');
        $host = (string)(parse_url((string)Config::get('site_url', ''), PHP_URL_HOST) ?: 'localhost');
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($name) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $encoded = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $encoded . "\r\n";

        match (self::transport()) {
            'smtp' => self::smtp($from, $to, $message),
            'log' => self::log($to, $message),
            default => self::phpMail($to, $subject, $headers, $encoded),
        };
        Logger::log('info', 'mail.sent', ['to' => self::mask($to), 'transport' => self::transport()]);
    }

    /**
     * Tells the owners about a new lead, from noreply@ the site's domain. Recipients: the addresses set in Settings,
     * or every active administrator that has an e-mail. Never throws: a lead is already saved.
     *
     * @param array<string,string> $lead name, phone, message, page
     * @return int letters sent
     */
    public static function notifyLead(array $lead): int
    {
        if (Config::get('mail.leads_notify', true) !== true || !self::configured()) {
            return 0;
        }
        $to = array_filter(array_map('trim', explode(',', (string)Config::get('mail.leads_to', ''))));
        if ($to === []) {
            $to = array_column(Database::rows("SELECT email FROM users WHERE role = 'admin' AND email <> '' AND blocked_at IS NULL"), 'email');
        }
        $site = Seo::siteUrl();
        $body = "Новая заявка с сайта {$site}\n\n"
            . 'Имя: ' . ($lead['name'] ?? '') . "\n"
            . 'Телефон: ' . ($lead['phone'] ?? '') . "\n"
            . 'Сообщение: ' . ($lead['message'] ?? '') . "\n"
            . 'Страница: ' . $site . '/' . (($lead['page'] ?? '') === 'index' ? '' : ($lead['page'] ?? '')) . "\n\n"
            . "Все заявки: {$site}/admin/?s=leads\n";
        $sent = 0;
        foreach (array_slice(array_unique($to), 0, 5) as $address) {
            try {
                self::send((string)$address, 'Новая заявка с сайта ' . (string)parse_url($site, PHP_URL_HOST), $body);
                $sent++;
            } catch (\Throwable $e) {
                Logger::log('warning', 'mail.lead_failed', ['error' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /** a***@example.com: enough for the log, not enough to harvest. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    private static function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private static function encodeHeader(string $value): string
    {
        $value = self::clean($value);
        return preg_match('/^[\x20-\x7e]*$/', $value) === 1 ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @param list<string> $headers */
    private static function phpMail(string $to, string $subject, array $headers, string $encoded): void
    {
        // mail() adds To and Subject itself, so they must not be repeated in the extra headers.
        $extra = array_values(array_filter($headers, static fn(string $h): bool => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:')));
        if (!function_exists('mail') || !@mail($to, self::encodeHeader($subject), $encoded, implode("\r\n", $extra), '-f' . self::from())) {
            throw new AgeCMSException('PHP mail() could not send the message. Set up SMTP in Settings.');
        }
    }

    private static function log(string $to, string $message): void
    {
        $dir = AGECMS_DATA . '/outbox';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AgeCMSException("Cannot create {$dir}");
        }
        file_put_contents($dir . '/' . gmdate('Ymd-His') . '-' . substr(sprintf('%.6f', microtime(true)), -6) . '-' . bin2hex(random_bytes(2)) . '.eml', $message);
    }

    private static function smtp(string $from, string $to, string $message): void
    {
        $cfg = (array)Config::get('mail.smtp', []);
        $secure = (string)($cfg['secure'] ?? 'ssl');
        $host = (string)$cfg['host'];
        $port = (int)($cfg['port'] ?? 465);
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            throw new AgeCMSException("SMTP: cannot connect to {$host}:{$port} ({$error})");
        }
        stream_set_timeout($fp, 15);
        try {
            self::expect($fp, [220]);
            $me = (string)(parse_url((string)Config::get('site_url', ''), PHP_URL_HOST) ?: 'localhost');
            self::command($fp, 'EHLO ' . $me, [250]);
            if ($secure === 'tls') {
                self::command($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new AgeCMSException('SMTP: TLS handshake failed');
                }
                self::command($fp, 'EHLO ' . $me, [250]);
            }
            if ((string)($cfg['user'] ?? '') !== '') {
                self::command($fp, 'AUTH LOGIN', [334]);
                self::command($fp, base64_encode((string)$cfg['user']), [334]);
                self::command($fp, base64_encode((string)($cfg['pass'] ?? '')), [235]);
            }
            self::command($fp, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($fp, 'DATA', [354]);
            // Dot-stuffing: a line that starts with a dot would end the message early.
            $data = preg_replace('/^\./m', '..', $message);
            fwrite($fp, $data . ($data !== '' && !str_ends_with($data, "\r\n") ? "\r\n" : '') . ".\r\n");
            self::expect($fp, [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp @param list<int> $ok */
    private static function command($fp, string $line, array $ok): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $ok);
    }

    /** Reads a (possibly multi-line) reply and checks its code. @param resource $fp @param list<int> $ok */
    private static function expect($fp, array $ok): void
    {
        $code = 0;
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text = rtrim($line);
            $code = (int)substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array($code, $ok, true)) {
            throw new AgeCMSException('SMTP: unexpected reply "' . $text . '"');
        }
    }
}
