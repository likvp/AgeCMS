<?php
declare(strict_types=1);

use AgeCMS\ModuleSigner;

/** Builds and signs small modules inside the test sandbox. Every test uses module names of its own: PHP keeps the classes it has loaded. */
final class ModuleFixture
{
    /**
     * @param list<array<string,mixed>> $routes routes for the manifest
     * @param array<string,string> $migrations file name (migrations/001_x.sql) => SQL
     * @param array<string,mixed> $extra more manifest keys
     * @param list<array<string,mixed>>|null $admin admin pages for the manifest (makes it an AdminModuleInterface)
     * @param list<array<string,string>>|null $sitemap addresses the module offers for sitemap.xml (makes it a SitemapModuleInterface)
     */
    public static function make(string $name, array $routes = [], array $migrations = [], array $extra = [], bool $routed = true, ?array $sitemap = null, ?array $admin = null): string
    {
        $dir = AGECMS_MODULES . '/' . $name;
        mkdir($dir . '/migrations', 0700, true);
        mkdir($dir . '/skills', 0700, true);
        $interface = $admin !== null ? 'AdminModuleInterface' : ($sitemap !== null ? 'SitemapModuleInterface' : ($routed ? 'RoutedModuleInterface' : 'ModuleInterface'));
        $code = <<<'PHP'
<?php
declare(strict_types=1);

namespace AgeCMS\Modules\__NAME__;

use AgeCMS\{Actor, ModuleRequest, ModuleResponse, __IFACE__};

final class Module implements __IFACE__
{
    public function register(): void
    {
    }

    public function callSkill(string $skill, array $args, Actor $actor): array
    {
        return ['pong' => true];
    }
__HANDLE__
}
PHP;
        $handle = <<<'PHP'

    public function handle(ModuleRequest $request): ModuleResponse
    {
        $name = '__NAME__';
        return match ($request->path) {
            "/{$name}/hello" => ModuleResponse::html('Привет', '<p class="mod-hello">hello ' . htmlspecialchars((string)($request->query['x'] ?? '')) . '</p>'),
            "/{$name}/form" => $request->method === 'POST'
                ? ModuleResponse::json(['ok' => true, 'fields' => array_keys($request->body), 'v' => $request->body['v'] ?? null])
                : ModuleResponse::html('Форма', '<form method="post">' . ag_module_form_fields() . '<input name="v"></form>'),
            "/{$name}/boom" => throw new \RuntimeException('secret trace detail'),
            "/{$name}/item/{$request->param('id')}" => ModuleResponse::html('Позиция', '<p class="mod-item">item ' . htmlspecialchars($request->param('id')) . '</p>'),
            "/{$name}/login" => ModuleResponse::html('Вход', '<p>in</p>')->withCookie('sid', 'abc123', 60),
            "/{$name}/whoami" => ModuleResponse::json(['sid' => $request->cookie('sid'), 'all' => array_keys($request->cookies)]),
            "/{$name}/go" => ModuleResponse::redirect("/{$name}/hello"),
            "/{$name}/evil" => ModuleResponse::redirect('https://evil.example/'),
            "/{$name}/pub" => ModuleResponse::html('Каталог', '<p class="mod-pub">public</p>'),
            "/{$name}/idx" => ModuleResponse::html('Витрина', '<p class="mod-idx">idx</p>')->withDescription('Описание <b>витрины</b> модуля'),
            "/{$name}/pubcookie" => ModuleResponse::html('Каталог', '<p>x</p>')->withCookie('sid', 'x', 60),
            default => ModuleResponse::html('Пусто', '<p>empty</p>', 404),
        };
    }
PHP;
        if ($sitemap !== null) {
            $handle .= "\n\n    public function sitemapUrls(): iterable\n    {\n        return " . var_export($sitemap, true) . ";\n    }\n";
        }
        if ($admin !== null) {
            $handle = <<<'PHP'

    public function handleAdmin(ModuleRequest $request, Actor $actor): ModuleResponse
    {
        $name = '__NAME__';
        $base = "/admin/m/{$name}";
        return match ($request->path) {
            "{$base}/" => ModuleResponse::html('Страница <модуля>', '<form method="post" action="' . $base . '/save">' . ag_admin_csrf_field() . '<input name="v"></form><p class="mod-admin">who ' . htmlspecialchars($actor->name) . ' ' . htmlspecialchars($actor->role) . '</p>'),
            "{$base}/save" => ModuleResponse::json(['ok' => true, 'fields' => array_keys($request->body), 'v' => $request->body['v'] ?? null, 'actor' => $actor->name, 'cookies' => $request->cookies, 'module' => $request->module]),
            "{$base}/item/{$request->param('id')}" => ModuleResponse::html('Позиция', '<p class="mod-aitem">item ' . htmlspecialchars($request->param('id')) . '</p>'),
            "{$base}/go" => ModuleResponse::redirect("{$base}/"),
            "{$base}/evil" => ModuleResponse::redirect('/admin/?s=users'),
            "{$base}/evil2" => ModuleResponse::redirect('https://evil.example/'),
            "{$base}/cookie" => ModuleResponse::html('Печенье', '<p>x</p>')->withCookie('sid', 'abc', 60),
            "{$base}/boom" => throw new \RuntimeException('secret trace detail'),
            "{$base}/upload", "{$base}/noupload" => ModuleResponse::json(['files' => array_map(static fn($f) => ['name' => $f->name, 'kind' => $f->kind, 'mime' => $f->mime, 'size' => $f->size, 'stored' => isset($request->body['keep']) ? $f->store() : null, 'text' => $f->kind === 'csv' ? $f->contents() : null, 'media' => isset($request->body['media']) ? $f->toMedia() : null], $request->files), 'body' => $request->body]),
            default => ModuleResponse::html('Пусто', '<p>empty</p>', 404),
        };
    }
PHP;
        }
        $code = str_replace('__HANDLE__', ($routed || $admin !== null) ? $handle : '', $code);
        file_put_contents($dir . '/Module.php', str_replace(['__NAME__', '__IFACE__'], [$name, $interface], $code));
        file_put_contents($dir . '/skills/ping.json', json_encode(['name' => 'ping', 'description' => 'Ping', 'permission' => 'skills.run']));

        $manifest = ['name' => $name, 'version' => '1.0.0', 'title' => $name, 'entry' => 'Module.php', 'class' => "AgeCMS\\Modules\\{$name}\\Module"] + $extra;
        if ($routes !== []) {
            $manifest['routes'] = $routes;
        }
        if ($admin !== null) {
            $manifest['admin'] = $admin;
        }
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
        foreach ($migrations as $file => $sql) {
            file_put_contents($dir . '/' . $file, $sql);
        }
        ModuleSigner::sign($name);
        return $dir;
    }

    /** Signs again after the files of the module were changed on purpose. */
    public static function resign(string $name): void
    {
        ModuleSigner::sign($name);
    }

    /** The routes that most tests use: all the paths the fixture handler knows, under /<name>/. */
    public static function routes(string $name): array
    {
        return [
            ['path' => "/{$name}/hello"],
            ['path' => "/{$name}/form", 'methods' => ['GET', 'POST']],
            ['path' => "/{$name}/boom"],
            ['path' => "/{$name}/item/{id}"],
            ['path' => "/{$name}/login"],
            ['path' => "/{$name}/whoami"],
            ['path' => "/{$name}/go"],
            ['path' => "/{$name}/evil"],
            ['path' => "/{$name}/pub", 'cache' => 'public', 'max_age' => 120],
            ['path' => "/{$name}/pubcookie", 'cache' => 'public', 'max_age' => 120],
            ['path' => "/{$name}/idx", 'cache' => 'public', 'max_age' => 120, 'indexable' => true],
        ];
    }
}
