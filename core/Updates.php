<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Tells the owner that a newer core is out. The version of this copy is in core/release.json; once a day the
 * site downloads the same kind of file from updates.url (the release.json of the newest core, published
 * anywhere over https) and remembers the answer in data/updates.json. Nothing is installed by itself: the
 * admin shows a notice, the code is replaced by the hoster's panel or by hand (see HOSTER_INTEGRATION_WHITEPAPER.md).
 */
final class Updates
{
    private const EVERY = 86400;
    /** After a failed check the next try comes sooner, so a short outage does not hide a release for a day. */
    private const RETRY = 3600;
    private const MAX_BYTES = 65536;

    /** @var array{version:string,date:string,notes:string,url:string}|null */
    private static ?array $current = null;

    /** @return array{version:string,date:string,notes:string,url:string} this copy of the core */
    public static function current(): array
    {
        if (self::$current === null) {
            $release = self::parse((string)@file_get_contents(__DIR__ . '/release.json'));
            self::$current = $release ?? ['version' => '0.0.0', 'date' => '', 'notes' => '', 'url' => ''];
        }
        return self::$current;
    }

    public static function version(): string
    {
        return self::current()['version'];
    }

    public static function sourceUrl(): string
    {
        return trim((string)Config::get('updates.url', ''));
    }

    /**
     * Reads a release file. Only the four known fields are kept, notes are cut to 2000 characters,
     * the link is kept only when it is https. Returns null for anything that is not a release.
     *
     * @return array{version:string,date:string,notes:string,url:string}|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['version'] ?? null) || preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $data['version']) !== 1) {
            return null;
        }
        $date = is_string($data['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date']) === 1 ? $data['date'] : '';
        $notes = is_string($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 2000) : '';
        $url = is_string($data['url'] ?? null) && preg_match('~^https://[^\s"<>]+$~', $data['url']) === 1 ? $data['url'] : '';
        return ['version' => $data['version'], 'date' => $date, 'notes' => $notes, 'url' => $url];
    }

    /**
     * What the last check found: checked_at (unix time), error ('' when it went fine) and latest (the release
     * file from the source, null until the first good answer).
     *
     * @return array{checked_at:int,error:string,source:string,latest:array{version:string,date:string,notes:string,url:string}|null}
     */
    public static function state(): array
    {
        $data = json_decode((string)@file_get_contents(self::file()), true);
        $latest = is_array($data['latest'] ?? null) ? self::parse((string)json_encode($data['latest'])) : null;
        return [
            'checked_at' => (int)($data['checked_at'] ?? 0),
            'error' => (string)($data['error'] ?? ''),
            'source' => (string)($data['source'] ?? ''),
            'latest' => $latest,
        ];
    }

    /** @return array{version:string,date:string,notes:string,url:string}|null the newer release, if the source has one */
    public static function available(): ?array
    {
        $state = self::state();
        $latest = $state['latest'];
        if ($latest === null || $state['source'] !== self::sourceUrl() || self::sourceUrl() === '') {
            return null;
        }
        return version_compare($latest['version'], self::version(), '>') ? $latest : null;
    }

    public static function due(): bool
    {
        if (self::sourceUrl() === '') {
            return false;
        }
        $state = self::state();
        if ($state['source'] !== self::sourceUrl()) {
            return true;
        }
        $wait = $state['error'] === '' ? self::EVERY : self::RETRY;
        return time() - $state['checked_at'] >= $wait;
    }

    /** Runs check() when a day has passed since the last one. Called from the background work. */
    public static function checkIfDue(): bool
    {
        if (!self::due()) {
            return false;
        }
        $handle = @fopen(self::file() . '.lock', 'c');
        if ($handle === false) {
            return false;
        }
        try {
            // Another request is already asking the source.
            if (!flock($handle, LOCK_EX | LOCK_NB) || !self::due()) {
                return false;
            }
            self::check();
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Asks the source now and stores the answer. $fetch replaces the download in tests: it gets the URL and
     * returns ['status' => int, 'body' => string] or null, like Http::get.
     *
     * @param (callable(string):(array{status:int,body:string}|null))|null $fetch
     * @return array{checked_at:int,error:string,source:string,latest:array{version:string,date:string,notes:string,url:string}|null}
     */
    public static function check(?callable $fetch = null): array
    {
        $source = self::sourceUrl();
        $old = self::state();
        $state = ['checked_at' => time(), 'error' => '', 'source' => $source, 'latest' => $old['source'] === $source ? $old['latest'] : null];
        if ($source === '') {
            $state['error'] = 'Адрес проверки не задан';
        } else {
            try {
                $reply = $fetch !== null ? $fetch($source) : Http::get($source, 5, self::MAX_BYTES);
            } catch (Throwable $e) {
                $reply = null;
            }
            $release = $reply !== null && $reply['status'] === 200 ? self::parse($reply['body']) : null;
            if ($reply === null) {
                $state['error'] = 'Сервер с обновлениями не ответил или адрес не подходит (нужен https на публичном адресе)';
            } elseif ($reply['status'] !== 200) {
                $state['error'] = 'Сервер с обновлениями ответил кодом ' . $reply['status'];
            } elseif ($release === null) {
                $state['error'] = 'По адресу лежит не файл версии';
            } else {
                $state['latest'] = $release;
            }
        }
        self::save($state);
        if ($state['error'] !== '') {
            Logger::log('warning', 'updates.check_failed', ['source' => $source, 'error' => $state['error']]);
        }
        return $state;
    }

    public static function file(): string
    {
        return AGECMS_DATA . '/updates.json';
    }

    /** Forgets the cached version of this copy (tests swap release.json contents through it). */
    public static function reset(?array $current = null): void
    {
        self::$current = $current;
    }

    /** @param array<string,mixed> $state */
    private static function save(array $state): void
    {
        if (!is_dir(AGECMS_DATA)) {
            return;
        }
        $tmp = self::file() . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false) {
            rename($tmp, self::file());
        }
    }
}
