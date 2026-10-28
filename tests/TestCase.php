<?php
declare(strict_types=1);

use AgeCMS\{Actor, Cache, Config, Database, EventBus, Installer, Queue, SkillRegistry};

/** Minimal test base: assertions plus a fresh installed site per call of freshSite(). */
abstract class TestCase
{
    public int $assertions = 0;

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== true) {
            throw new AssertionError($message !== '' ? $message : 'Expected true, got ' . var_export($value, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionError(($message !== '' ? $message . ': ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionError(($message !== '' ? $message . ': ' : '') . "Expected to find '{$needle}' in " . substr($haystack, 0, 300));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new AssertionError(($message !== '' ? $message . ': ' : '') . "Did not expect '{$needle}'");
        }
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $messagePart = ''): Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionError("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messagePart !== '' && !str_contains($e->getMessage(), $messagePart)) {
                throw new AssertionError("Exception message '{$e->getMessage()}' lacks '{$messagePart}'");
            }
            return $e;
        }
        throw new AssertionError("Expected {$class}, nothing was thrown");
    }

    /** Wipes the test sandbox and installs a clean site. @param array<string,mixed> $options */
    protected function freshSite(array $options = []): void
    {
        TestEnv::wipe();
        Config::reset();
        Database::reset();
        EventBus::reset();
        SkillRegistry::reset();
        TestEnv::copyModules();
        Installer::install($options + ['site_url' => 'https://example.test', 'site_name' => 'Test Site', 'admin_password' => 'Passw0rd-test-1', 'demo' => false]);
        // Tests never ask the real update source.
        Config::set('updates.url', '');
    }

    protected function admin(): Actor
    {
        return Actor::cli('test');
    }

    /** @param array<string,mixed> $fields */
    protected function createPage(string $slug, array $fields = [], ?Actor $actor = null): array
    {
        return Queue::submit($actor ?? $this->admin(), 'create_page', ['slug' => $slug, 'fields' => $fields + [
            'title' => 'Page ' . $slug,
            'blocks' => [['id' => 'hero_1', 'type' => 'hero', 'data' => ['h1' => 'Heading ' . $slug]]],
        ]]);
    }
}

/** Paths of the sandbox. Constants are set once in run.php. */
final class TestEnv
{
    public static function root(): string
    {
        return TEST_TMP;
    }

    public static function wipe(): void
    {
        self::remove(TEST_TMP);
        mkdir(TEST_TMP, 0700, true);
    }

    public static function copyModules(): void
    {
        $dest = TEST_TMP . '/modules';
        mkdir($dest, 0700, true);
        $src = dirname(__DIR__) . '/modules';
        foreach (glob($src . '/*', GLOB_ONLYDIR) as $module) {
            self::copy($module, $dest . '/' . basename($module));
        }
    }

    public static function copy(string $from, string $to): void
    {
        mkdir($to, 0700, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || $item === 'manifest.json.sig') {
                continue;
            }
            is_dir("{$from}/{$item}") ? self::copy("{$from}/{$item}", "{$to}/{$item}") : copy("{$from}/{$item}", "{$to}/{$item}");
        }
    }

    public static function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $item) {
                if ($item !== '.' && $item !== '..') {
                    self::remove($path . '/' . $item);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
