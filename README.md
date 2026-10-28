# AgeCMS

PHP 8.1+ и SQLite. CMS для сайтов, которые правят и люди, и ИИ-агенты, и которая при этом отдаёт страницы за миллисекунду.

Заказчик: Валентин Савельев (Tabson). Лицензия Proprietary, все права принадлежат Tabson.ru Studio (см. [LICENSE](LICENSE)).

## Что внутри

| Что | Как устроено |
| --- | --- |
| Скорость (Shadow Cache) | Готовая страница лежит файлом `assets/cache/<slug>.html`. Apache или nginx отдают её сами, PHP не стартует. Без настройки сервера работает быстрый путь в первых строках `index.php`. Замеры: [docs/BENCHMARK.md](docs/BENCHMARK.md). |
| Правка человеком (Dual-UX) | Вошли в `/admin/`, открыли страницу сайта, кликнули по тексту, исправили. Правка уходит в ту же очередь и тот же журнал, что и правки агента. |
| Правка агентом | MCP-шлюз `POST /admin/mcp.php` (JSON-RPC 2.0, Bearer-токен, роли), инструкция для агента лежит в [core/agent.md](core/agent.md) и отдаётся по адресу `/AGENT.md` с адресом сайта внутри. Агент получает доступ сам: логин и пароль владельца обменивает на свой токен. |
| История | Каждая правка это ревизия: патч RFC 6902, контрольные снимки, цепочка SHA-256. Триггеры SQLite запрещают менять и удалять ревизии. Откат создаёт новую ревизию. |
| Предохранитель | Агент изменил больше 15% страниц (и не меньше 3) за сессию: транзакция откатывается, запись замораживается до `bin/unfreeze.php`, уведомление в Telegram. |
| Схема | Страница это метаполя и список блоков. Тема объявляет типы блоков и поля, всё остальное отклоняется до записи в базу. |
| Модули | Папка с `manifest.json` и подписью. Подмена любого файла блокирует модуль. Навыки модуля видны агенту как инструменты `<модуль>__<навык>`. Справочник разработчика: [docs/MODULES.md](docs/MODULES.md). |
| SEO | meta, canonical из конфига, JSON-LD (в том числе FAQ и хлебные крошки), `sitemap.xml`, `robots.txt`, `llms.txt` (с отдельным текстом для моделей из настроек), IndexNow. |
| Защита форм | Подписанный токен времени, honeypot, лимиты по IP. Заявки в базу и в Telegram (модуль `telegram_leads`). |
| Бэкап | Целостный снимок базы (`VACUUM INTO`), ротация, отправка в Telegram. |

## Установка

Нужны PHP 8.1+ с `pdo_sqlite`, `mbstring`, `json`. Для картинок желательно `gd` с WebP, для подписей Ed25519 `sodium`, для вебхуков и IndexNow `curl`.

```bash
git clone <репозиторий> /var/www/agecms
cd /var/www/agecms
php bin/install.php --url=https://example.com --name="Мой сайт"
```

Скрипт создаст базу в `data/`, ключ подписей, демо-страницы и администратора. Пароль, если не задан флагом `--password`, печатается один раз. Установка по вебу (для хостинга без SSH) отключена, пока вы сами не положите в `data/install.key` строку от 32 символов и не откроете `/admin/install.php?key=...`: мастер из трёх шагов (проверка среды, данные сайта, результат с показом пароля один раз). После установки появляется `data/install.lock`, и оба пути закрыты.

Настройка веб-сервера:

- Apache: `.htaccess` из корня работает сам (нужны `mod_rewrite`, `mod_headers`, `AllowOverride All`, PHP через FPM или `mod_php`).
- nginx: возьмите [nginx.vhost.example](nginx.vhost.example), поправьте `server_name`, `root` и сокет FPM.
- Права: пользователь PHP должен писать в `data/`, `assets/cache/` и `assets/uploads/`.

Фоновые задачи (разбор очереди записи, пинги IndexNow, пересборка кэша сброшенных страниц) выполняются сами: сразу после ответа на сохранение в админке или через API и при заходе посетителей, не чаще раза в 30 секунд. Cron не нужен. Если хотите расписание, можно по-прежнему вызывать `php bin/queue-worker.php` раз в минуту. Резервную копию удобно делать ежедневно: `0 3 * * * php /var/www/agecms/bin/backup.php` (или кнопкой «Сделать бэкап» в «Системе»).

## Команды

| Команда | Что делает |
| --- | --- |
| `php bin/token.php create <имя> --role=editor` | выдать токен агенту (показывается один раз, в базе только хэш) |
| `php bin/token.php list` / `revoke <имя>` | список и отзыв |
| `php bin/unfreeze.php` | снять заморозку после срабатывания предохранителя |
| `php bin/verify-chain.php` | проверить цепочку хэшей ревизий |
| `php bin/clear-cache.php` / `rebuild-cache.php` | сбросить и пересобрать кэш |
| `php bin/backup.php [--telegram]` | снимок базы |
| `php bin/config.php get\|set <ключ> [значение]` | настройки и секреты (`secrets.telegram_bot_token`, `secrets.telegram_chat_id`, адрес проверки обновлений `updates.url`) |
| `php bin/sign-module.php <модуль>` | подписать модуль после правки |

## Подключить агента

```bash
php bin/token.php create seo-agent --role=editor
curl -s https://example.com/admin/mcp.php \
  -H "Authorization: Bearer agc_..." -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Для клиентов MCP адрес и заголовок те же.

Проще всего без пароля: в «Системе» кнопка «Получить текст для агента» выдаёт готовый текст с адресом сайта и одноразовым кодом `agi_...` (действует сутки, срабатывает один раз). Владелец вставляет его в чат, агент читает `https://example.com/AGENT.md` и обменивает код на токен:

```bash
curl -s https://example.com/admin/api.php -H "Content-Type: application/json" \
  -d '{"action":"redeem_invite","code":"agi_...","name":"claude-seo"}'
```

Токен живёт ограниченный срок (`agent_access.token_days`, по умолчанию 90 дней, предупреждение за неделю в заголовке `X-AgeCMS-Token-Warning` и в `get_site_structure`). Пользователя можно заблокировать: его вход и токены перестают работать. Перед первой правкой агента за сеанс делается копия базы (`backup.before_agent`, последние `backup.keep_auto`). Запасной путь: владелец даёт агенту логин и пароль, сайт сам заводит агенту отдельного пользователя `agent-<имя>` и выдаёт токен на него (`"own_user": false` отключает) (в «Пользователях» есть кнопка для отдельного пользователя агента), агент читает `https://example.com/AGENT.md` (адрес указан и в `/llms.txt`, и в ответах 401) и обменивает пару на свой токен:

```bash
curl -s https://example.com/admin/api.php -H "Content-Type: application/json" \
  -d '{"action":"issue_token","username":"anna","password":"...","name":"claude-seo","role":"editor"}'
```

Роль токена не выше роли пользователя, неверные пароли считаются как при входе в админку, 10 токенов в час на пользователя. Владелец видит и отзывает токены в «Системе», а там же может выключить самовыдачу (`agent_access.self_issue` в `data/config.php`).

## Тесты и замеры

```bash
php tests/run.php          # 130 тестов, около 35 секунд, песочница во временной папке
php bench/shadow_cache_bench.php
```

Админка (`/admin/`): страницы с редактором блоков, меню, настройки сайта, история с откатом, заявки, токены агентов, бэкап.

Тесты покрывают журнал и триггеры, предохранитель, параллельных писателей, схему, кэш, подписи модулей, защиту от SSRF, антиспам, установку и сквозной HTTP-прогон. Что не проверено, перечислено в [docs/BENCHMARK.md](docs/BENCHMARK.md) и [ROADMAP.md](ROADMAP.md).

## Документы

- [docs/ТЗ.md](docs/ТЗ.md) - ТЗ v2.0
- [docs/PLAN.md](docs/PLAN.md) - план и отметки выполнения
- [docs/gemini-analysis.md](docs/gemini-analysis.md) - что взято из чатов Gemini и что исправлено
- [HOSTER_INTEGRATION_WHITEPAPER.md](HOSTER_INTEGRATION_WHITEPAPER.md) - для хостинг-провайдеров
- [ROADMAP.md](ROADMAP.md), [CONTRIBUTING.md](CONTRIBUTING.md)
