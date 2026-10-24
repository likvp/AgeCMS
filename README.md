# AgeCMS — Agent-Native PHP/SQLite CMS
> **Repository:** [github.com/likvp/Agent-native-php-cms-](https://github.com/likvp/Agent-native-php-cms-)  
> **Vendor:** Tabson.ru Studio  
> **Core Philosophy:** Agent-Native Architecture over Protocol-Bound Branding  
> **Official Anchor:** *Agent-native CMS with MCP as the agent interface*

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![Database](https://img.shields.io/badge/SQLite-WAL%20Mode-003B57?style=flat-square&logo=sqlite)](https://sqlite.org)
[![Protocol](https://img.shields.io/badge/MCP-Compatible-FF6B6B?style=flat-square)](#-mcp-agent-gateway--dual-ux)
[![Performance](https://img.shields.io/badge/PageSpeed-100%2F100-00C853?style=flat-square)](#-key-features)
[![License](https://img.shields.io/badge/License-Proprietary-blue?style=flat-square)](#)

---

## 💡 Что такое AgeCMS?

**AgeCMS** (*Agent + Engine / The New Age of CMS*) — это ультралёгкая, портативная и защищённая Agent-Native CMS с двухуровневым интерфейсом (**Dual-UX**), разработанная студией **Tabson.ru** в качестве фундаментального встраиваемого ядра для веб-проектов нового поколения.

Система устраняет барьеры между классическим администрированием людьми и автоматическим управлением ИИ-агентами (Cursor, Claude Desktop, Windsurf, Devin, OpenAI Operator). **AgeCMS** построена на **PHP 8.1+** и встроенной СУБД **SQLite 3 в режиме WAL**, не требует настройки внешних баз данных (Zero-Setup) и обеспечивает скорость отдачи страниц **за 1–2 мс** за счёт системы Flat-File Shadow Cache.

---

## ⚡ Ключевые возможности и архитектура

### 1. Двухуровневый интерфейс (Dual-UX)
* **Для человека:** Визуальное редактирование контента «на лету» (`contenteditable`) с мгновенным сохранением блоков Bento UI (Tailwind CSS + Alpine.js).
* **Для ИИ-агентов:** Нативный шлюз `/admin/mcp.php` по протоколу Model Context Protocol (JSON-RPC 2.0) с защитой HMAC-SHA256.

### 2. Zero-Setup & 1-ms Shadow Cache
* Никаких мастеров установки или подключения к MySQL. При первом HTTP-запросе ядро автоматически создает базу `data.sqlite`, включающую режим WAL.
* Кеширующий слой **Flat-File Shadow Cache** запекает валидный Bento-контент в статические `.html` файлы, отдавая их с диска за 1–2 мс с нулевой нагрузкой на PHP.

### 3. Концепция Module-as-a-Skill
* Модули расширения (`/modules/<module_name>/`) не просто содержат PHP-логику, но и объявляют JSON-инструкции в папке `skills/`.
* Класс `SkillRegistry` динамически агрегирует эти файлы и передаёт готовые описания навыков в контекст ИИ-агентов через MCP.

### 4. Неизменяемый аудит (Append-Only Time-Travel Ledger)
* Все изменения фиксируются в таблице `revisions` с указанием типа актора (`actor_type = 'agent' | 'human'`).
* Журнал защищён на уровне ядра СУБД триггерами SQLite (`BEFORE UPDATE` / `BEFORE DELETE`), гарантирующими невозможность удаления или подмены истории правок.

---

## 🏛 Трёхуровневое позиционирование (Three-Tier Naming Model)

Для защиты продукта от устаревания конкретных протоколов в **AgeCMS** зафиксирована строго проверенная структура позиционирования:

| Уровень | Назначение | Формулировка / Термин |
| :--- | :--- | :--- |
| **Brand** | Коммерческое имя продукта | **AgeCMS** *(by Tabson.ru)* |
| **Category** | Архитектурный класс (Durable) | **Agent-Native PHP/SQLite CMS** |
| **Compatibility** | Протокол интеграции | **MCP-Compatible** |

> **Связующий якорь:** *Agent-native CMS with MCP as the agent interface*

---

## 📁 Структура каталогов репозитория

```text
.
├── admin/                  # Панель управления и шлюз для ИИ-агентов
│   ├── index.php           # Дашборд администратора (Human Layer)
│   └── mcp.php             # Защищенный MCP Шлюз (JSON-RPC 2.0 + HMAC)
├── core/                   # Архитектурное ядро
│   ├── Database.php        # Синглтон подключения к SQLite WAL
│   ├── EventBus.php        # Шина событий системы (Хуки и уведомления)
│   ├── SchemaValidator.php # Валидатор JSON-схем Bento-блоков
│   └── SkillRegistry.php   # Динамический реестр навыков ИИ-агентов
├── modules/                # Расширения (Module-as-a-Skill)
│   ├── auto_migrator/      # Бесшовный конвейер миграции старых сайтов в /new/
│   ├── scale_migrator/     # Модуль пакетной пересборки (10,000+ страниц, 301 SEO)
│   └── telegram_leads/     # Модуль приёма заявок и отправки в Telegram
├── themes/                 # Темы оформления (Bento UI + Tailwind + Alpine.js)
├── BRAND_STRATEGY.md       # Стратегия позиционирования и нейминга
├── QUICKSTART.md           # Руководство по быстрому старту
├── SEAMLESS_AGENT_DEPLOYMENT.md # Протокол бесшовной деплой-миграции через FTP
├── SECURITY.md             # Политика безопасности и правила репортов
├── SKILL_AUTO_MIGRATOR.md  # Инструкция для агентов: автоматическая миграция
├── SKILL_SCALE_MIGRATOR.md # Инструкция для агентов: enterprise-миграция и SEO
├── THREAT_MODEL.md         # Модель угроз и инженерный анализ векторов атак
├── data.sqlite             # Встраиваемая база данных SQLite 3
├── index.php               # Front-Controller & Shadow Cache роутер
└── README.md               # Полное техническое руководство
```

---

## 🛡 Комплексный фреймворк безопасности (Security & Integrity)

AgeCMS проектировалась с учётом наивысших требований к защите закрытого B2B-контура:

1. **HMAC-SHA256 подпись:** Каждый запрос к `/admin/mcp.php` подписывается секретным ключом с временной меткой (окно 30 секунд для защиты от Replay Attack).
2. **Предохранители массовых правок (Circuit Breakers):** Если сессия или агент пытается модифицировать >15% страниц сайта одновременно, транзакция замораживается с отправкой аларма в Telegram.
3. **Двухуровневая фильтрация:** `SchemaValidator` проверяет структуру по JSON Schema, а `SecuritySanitizer` очищает HTML-разметку от XSS и опасных инъекций.
4. **Append-Only Ревизии:** SQLite-триггеры блокируют `UPDATE` и `DELETE` в таблице `revisions`.

---

## 🚀 Быстрый старт

### 1. Клонирование репозитория
Загрузите содержимое репозитория [github.com/likvp/Agent-native-php-cms-](https://github.com/likvp/Agent-native-php-cms-) в корень сайта или временный каталог:

```bash
git clone https://github.com/likvp/Agent-native-php-cms-.git .
```

### 2. Первый запуск
Перейдите по адресу вашего сайта (`http://domain.com/`). **AgeCMS** автоматически сформирует структуру `data.sqlite`, настроит WAL-журнал и отрендерит базовую тему.

### 3. Подключение ИИ-агентов по MCP
Для подключения Cursor, Claude Desktop или Windsurf укажите настройки в конфигурационном файле MCP-клиента:

```json
{
  "mcpServers": {
    "agecms": {
      "command": "node",
      "args": ["-e", "/* Custom HMAC Transport Wrapper */"],
      "env": {
        "AGECMS_ENDPOINT": "https://your-domain.com/admin/mcp.php",
        "AGECMS_SECRET": "your_hmac_secret_key"
      }
    }
  }
}
```

---

## 📄 Спецификации и дополнительная документация

* [`BRAND_STRATEGY.md`](./BRAND_STRATEGY.md) — Стратегия нейминга и категория Agent-Native CMS.
* [`SEAMLESS_AGENT_DEPLOYMENT.md`](./SEAMLESS_AGENT_DEPLOYMENT.md) — Руководство по миграции сайтов ИИ-агентом через FTP без даунтайма.
* [`SKILL_AUTO_MIGRATOR.md`](./SKILL_AUTO_MIGRATOR.md) — Автоматический поиск конфигураций и перенос в `/new/`.
* [`SKILL_SCALE_MIGRATOR.md`](./SKILL_SCALE_MIGRATOR.md) — Миграция 10,000+ страниц с сохранением SEO, 301-картой и рендерингом Bento.
* [`THREAT_MODEL.md`](./THREAT_MODEL.md) & [`SECURITY.md`](./SECURITY.md) — Анализ угроз, защита от Prompt Injection и правила безопасности.

---

Разработано с ❤️ в **Tabson.ru Studio**.  
Репозиторий проекта: [github.com/likvp/Agent-native-php-cms-](https://github.com/likvp/Agent-native-php-cms-)
