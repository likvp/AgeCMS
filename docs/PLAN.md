# План разработки AgeCMS

Составлен 27.10.2026 после чтения ТЗ v2.0 ([ТЗ.md](ТЗ.md)) и 6 чатов Gemini ([gemini-analysis.md](gemini-analysis.md)). Это рабочий документ: по нему идёт разработка, отметки `[x]` ставятся по факту проверки.

## 0. Что строим и по каким правилам

Одна CMS на PHP 8.1+ и SQLite (WAL), которой одинаково удобно пользоваться человеку (правка текста кликом на сайте, админка) и ИИ-агенту (MCP по JSON-RPC 2.0, файл `AGENT.md`). Публичные страницы отдаются статикой из `assets/cache/`. Функциональный объём берём из чатов Gemini, архитектуру и защиту из ТЗ v2.0. При конфликте побеждает v2.0.

Жёсткие правила, которые проверяются тестами:

1. Установка только через CLI или веб-установщик с ключом из файла. Никакой инициализации на первом запросе.
2. Агенты и люди пишут в БД только через ядро (`Content`), агенты ещё и через очередь с одним писателем.
3. Таблица `revisions` не меняется и не удаляется: триггеры SQLite, цепочка хэшей.
4. Больше 15% существующих страниц за одну сессию: транзакция откатывается, запись замораживается, админ получает уведомление.
5. Скилы и модули исполняются только с валидной подписью манифеста и совпадающими хэшами файлов.
6. Никаких внешних CDN. Весь JS и CSS свои, CSP `default-src 'self'`.
7. Все секреты в `data/config.php` (права 0600), не в БД и не в бэкапе БД.
8. Совместимость PHP 8.1: без `json_validate`, без readonly-классов, без `#[\Override]`.

## 1. Решения по спорным местам

Подробная таблица "Gemini против v2.0" в [gemini-analysis.md](gemini-analysis.md). Ключевое:

- Страница хранится как документ: метаполя + список блоков `{id, type, data}`. Один документ версионируется одним JSON Patch.
- Схема блоков лежит в `theme.json` темы. Валидатор отвергает неизвестные типы, неизвестные поля, слишком длинные значения, опасные URL. HTML в полях не разрешён, только текст: это и есть "защищённый каркас".
- Circuit Breaker считает изменённые существующие страницы к числу страниц на старте сессии. Создание новых страниц считается отдельно (лимит на сессию), иначе миграция старого сайта невозможна. Для очень маленьких сайтов есть порог `min_touched` (по умолчанию 3).
- Ревизии: forward-патч на каждую правку, полный снимок-чекпойнт каждые 25 ревизий страницы. Откат это новая ревизия, не удаление. "Pruning" заменён чекпойнтами, архивация в ROADMAP.
- SSE из Gemini не делаем (бесконечный цикл съест PHP-воркеры хостинга).
- Аналитика: маяк `/_/hit` (статика не запускает PHP), без кук, уникальные по хэшу с суточной солью.

## 2. Структура репозитория

```
core/agent.md  llms.txt  README.md  LICENSE  CONTRIBUTING.md  ROADMAP.md
HOSTER_INTEGRATION_WHITEPAPER.md  index.php  .htaccess  nginx.vhost.example
core/        bootstrap.php Exceptions.php helpers.php
             Config Database Schema Actor Security Logger Http
             JsonPatch Revisions SchemaValidator Theme Pages Content
             CircuitBreaker Queue Cache Renderer Seo Router Search
             Auth AntiSpam EventBus Media Backup SkillRegistry ModuleInterface Installer
admin/       index.php (панель) api.php (правки) mcp.php (MCP) install.php (веб-установка)
bin/         install.php clear-cache.php rebuild-cache.php queue-worker.php
             backup.php token.php unfreeze.php sign-module.php verify-chain.php
data/        закрыт от веба: data.sqlite config.php logs/ queue/ backups/
assets/      app.css app.js editor.js cache/ uploads/
modules/     telegram_leads/ (manifest.json, manifest.json.sig, Module.php, skills/*.json)
themes/      default/ (theme.json, layout.php, blocks/*.php)
tests/       run.php + *Test.php (без внешних зависимостей)
bench/       shadow_cache_bench.php
docs/        ТЗ.md PLAN.md gemini-analysis.md gemini-links.md
```

## 3. Схема БД (миграция v1)

`settings`, `pages` (поля документа + `blocks_json`), `menus`, `revisions` (append-only, `patch_json`, `snapshot_json` чекпойнт, `prev_hash`, `hash`), `agent_sessions`, `session_pages`, `users`, `api_tokens` (хранится sha256 токена), `rate_limits`, `leads`, `analytics_daily`, `visitors_daily`, `cache_tags`, `pages_fts` (FTS5, при отсутствии модуля поиск через LIKE).

## 4. Потоки

**Запись.** Вызывающий (admin/api.php, mcp.php, CLI) собирает операцию и отдаёт в `Queue::submit`. Очередь кладёт подписанный HMAC файл в `data/queue/`, берёт `flock`, по порядку исполняет через `Content` внутри `BEGIN IMMEDIATE`: проверка заморозки, права, валидация схемы, Circuit Breaker, ревизия, сохранение страницы, FTS, теги кэша. После коммита: сброс кэша по тегам, событие в `EventBus`, пинг IndexNow в очередь. Результат читается из `data/queue/done/`.

**Чтение страницы.** `index.php` первой строкой проверяет `assets/cache/<slug>.html` (GET, без query, без куки админа) и отдаёт файл. Иначе `Router` находит страницу, `Renderer` собирает HTML темы, для анонимов пишет в кэш и отдаёт.

**Правка человеком.** Вошедший админ видит страницу без кэша с `editor.js`: поля с `data-ag-*` становятся `contenteditable`, через 800 мс уходит запрос в `admin/api.php` с CSRF-токеном.

**Агент.** `POST /admin/mcp.php` с `Authorization: Bearer`. Методы MCP (`initialize`, `tools/list`, `tools/call`) и прямые алиасы из чатов (`get_site_structure`, `update_block_data`, `batch_create_pages`, `rollback_revision`). Скилы модулей добавляются в `tools/list` только если подпись прошла.

## 5. Этапы и задачи

### Этап 0. Подготовка
- [x] Прочитать 6 чатов Gemini, сделать разбор
- [x] Проверить окружение: PHP 8.5, SQLite 3.53 с FTS5, GD с webp/avif, sodium, curl, zip
- [x] Написать этот план

### Этап 1. Ядро и безопасность (ТЗ этап 1)
- [x] `bootstrap`, `Exceptions`, `Config`, `Database` (синглтон PDO, WAL, `BEGIN IMMEDIATE`, повтор при BUSY), `Schema` (миграции по `user_version`)
- [x] `Actor`, `Security` (роли, HMAC, HKDF), `Logger` (JSON-строки)
- [x] `JsonPatch` (diff и apply по RFC 6902), `Revisions` (патчи, чекпойнты, цепочка хэшей, триггеры)
- [x] `SchemaValidator` + `Theme`, `Pages`
- [x] `CircuitBreaker`, `Content` (операции записи)
- [x] `Queue` (очередь, один писатель, подпись файлов)
- [x] `Auth` (пользователи, сессии, CSRF, токены агентов, лимит попыток)
- [x] `Installer`, `bin/install.php`, `admin/install.php`, `bin/token.php`, `bin/unfreeze.php`, `bin/verify-chain.php`

### Этап 2. Кэш и маршрутизация (ТЗ этап 2)
- [x] `Cache` (файлы, теги, инвалидация, прогрев), `Renderer`, `Router`, `index.php` с быстрым путём
- [x] `Seo` (мета, JSON-LD, sitemap, robots, llms.txt, IndexNow), `Search`
- [x] Тема `default`: layout и блоки `hero`, `text`, `features`, `faq`, `lead_form`; `app.css`, `app.js`, `editor.js`
- [x] `.htaccess`, `nginx.vhost.example`, `bin/clear-cache.php`, `bin/rebuild-cache.php`, `bin/queue-worker.php`
- [x] Админка `admin/index.php`, `admin/api.php`

### Этап 3. Агентский слой и модули (чаты 4 и 5)
- [x] `admin/mcp.php`, `SkillRegistry` (подпись, хэши), `bin/sign-module.php`
- [x] `EventBus` (+ вебхуки без SSRF), `AntiSpam`, `Media`, `Backup`, `bin/backup.php`
- [x] Модуль `telegram_leads`
- [x] `core/agent.md` (отдаётся как `/AGENT.md`, агент выдаёт себе токен по логину и паролю); `llms.txt` отдаётся динамически маршрутом (список опубликованных страниц), статического файла нет

### Этап 4. Тесты и бенчмарки (ТЗ этап 3)
- [x] `tests/run.php`, 90 тестов в 13 файлах (вместо отдельных `AuthTest` и `RouterTest` проверки входа, токенов и маршрутов лежат в `InstallerTest` и `HttpIntegrationTest`)
- [x] `bench/shadow_cache_bench.php`: прогон на php -S и на Apache 2.4 + php-fpm (поднят локально); nginx на машине нет, не измерен. Результаты в [BENCHMARK.md](BENCHMARK.md)
- [x] Сквозная проверка: установка, открытие страниц, правка, откат, MCP-вызовы curl

### Этап 5. Публичность (ТЗ этап 4)
- [x] `LICENSE` (Proprietary, решение Валентина 27.10.2026; сначала была Apache 2.0), `CONTRIBUTING.md`, `ROADMAP.md`, `HOSTER_INTEGRATION_WHITEPAPER.md`
- [x] `README.md` с установкой
- [x] Заготовка `SKILL_AUTO_MIGRATOR` и Pitch-Deck: только описание в ROADMAP, код и презентация вне этого прохода

### Завершение
- [x] `git init`, коммиты по этапам на русском (скилл `git-commits`)
- [x] Обновить карточку `AgeCMS` в Obsidian, коммит и пуш vault
- [ ] Отчёт Валентину в формате `operator-report` (дан в чате)

## 6. Критерии готовности

- `php tests/run.php` зелёный.
- `php bin/install.php` на пустой папке даёт рабочий сайт: главная, вторая страница, sitemap, robots, llms.txt, поиск.
- Повторный запрос страницы идёт из `assets/cache/` (видно по заголовку и по логу).
- Правка поля из админки создаёт ревизию, сбрасывает кэш только затронутых страниц.
- Прямой `UPDATE revisions` и `DELETE FROM revisions` падают с ошибкой триггера.
- Агент, меняющий 16 из 100 страниц, получает ошибку, дальше запись заморожена до `bin/unfreeze.php`.
- Подмена `skills/*.json` или `Module.php` без новой подписи блокирует модуль.
- `curl` к `/data/data.sqlite`, `/core/`, `/bin/` даёт 403 или 404 (проверка конфигов Apache/nginx по тексту, живой прогон если есть сервер).

## 7. Что может не получиться и как это отмечаем

- Apache удалось поднять локально (httpd + php-fpm), `.htaccess` проверен вживую. nginx в окружении нет, `nginx.vhost.example` не проверен в бою.
- Публикация в Яндекс Cloud Boost, Роспатент, партнёрства с хостерами: это бизнес-часть ТЗ, к коду не относится. Документ для хостеров делаю, остальное остаётся за Валентином.
