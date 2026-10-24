# AgeCMS — The Agent-Native PHP/SQLite CMS
> **Developed with ❤️ by Tabson.ru Studio**  
> **Core Philosophy:** Agent-Native Architecture over Protocol-Bound Branding  
> **Official Anchor:** *Agent-native CMS with MCP as the agent interface*

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![Database](https://img.shields.io/badge/SQLite-WAL%20Mode-003B57?style=flat-square&logo=sqlite)](https://sqlite.org)
[![Protocol](https://img.shields.io/badge/MCP-Compatible-FF6B6B?style=flat-square)](#-dual-ux-human--ai-architecture)
[![Performance](https://img.shields.io/badge/PageSpeed-100%2F100-00C853?style=flat-square)](#-key-features)
[![License](https://img.shields.io/badge/License-Proprietary-blue?style=flat-square)](#)

---

## 💡 What is AgeCMS?

**AgeCMS** (Agent + Engine / The New Age of CMS) is a lightweight, zero-setup, dual-interface CMS built from the ground up for the AI-driven web generation. Designed as an in-house core engine for **Tabson.ru Studio**, AgeCMS decouples traditional human administrative flows from autonomous AI-agent workflows.

It is built on top of **PHP 8.1+** and embedded **SQLite 3 (WAL mode)**, achieving sub-2ms response times with zero external database dependencies.

---

## ⚡ Key Features

* **Dual-UX Architecture:** Seamless visual inline editing (`contenteditable` + Bento UI) for humans; standardized JSON-RPC 2.0 / MCP (Model Context Protocol) endpoints for AI Agents (Cursor, Claude, Windsurf, Devin).
* **Zero-Setup & Portable:** No configuration wizards or complex MySQL provisioning. Initializes `data.sqlite` with WAL mode automatically on the first HTTP request.
* **1-ms Flat-File Shadow Cache:** Serves compiled static HTML snapshots instantly with almost zero PHP/SQLite overhead.
* **Module-as-a-Skill Protocol:** Every extension module (`/modules/<module_name>/skills/*.json`) automatically exports declarative skills directly into the AI agent's context.
* **Immutable Audit Ledger:** Time-Travel versioning backed by SQLite-level `BEFORE UPDATE` / `BEFORE DELETE` triggers to ensure `revisions` are strictly append-only.
* **Hardened Security Suite:** HMAC-SHA256 request signatures, circuit breakers for bulk mutations (15% limit), and strict Schema-based RBAC.

---

## 🏛 Three-Tier Positioning Model

To protect the architecture from protocol deprecation or rebranding, **AgeCMS** strictly enforces a 3-tier positioning structure:

| Tier | Role | Term / Scope |
| :--- | :--- | :--- |
| **Brand** | Commercial Product Name | **AgeCMS** *(by Tabson.ru)* |
| **Category** | Durable Architectural Class | **Agent-Native PHP/SQLite CMS** |
| **Compatibility** | Current Agent Protocol Interface | **MCP-Compatible** |

---

## 📁 Directory Structure

```text
public_html/
├── admin/                  # Administrative Panel & MCP Gateway
│   ├── index.php           # Human Dashboard
│   └── mcp.php             # Secure Agent Endpoint (HMAC Signed)
├── core/                   # Core Architecture
│   ├── Database.php        # SQLite WAL Singleton
│   ├── EventBus.php        # Internal Event Hook System
│   ├── SchemaValidator.php # Strict JSON Block Schema Validation
│   └── SkillRegistry.php   # Dynamic Agent Skill Aggregator
├── modules/                # Custom Business Modules (Module-as-a-Skill)
│   ├── auto_migrator/      # Automated Legacy Migration Engine
│   └── telegram_leads/     # Telegram Notification Dispatcher
├── themes/                 # Bento UI Theme Templates (Tailwind + Alpine.js)
├── data.sqlite             # Embedded SQLite Database File
└── index.php               # Front-Controller & Shadow Cache Router
```

---

## 🚀 Quick Start for Developers & AI Agents

### 1. Installation
Simply upload the repository contents to your web root (`/public_html/`) or staging folder (`/new/`). Ensure PHP 8.1+ with `pdo_sqlite` enabled.

```bash
git clone https://github.com/tabson/agecms.git .
```

### 2. First Boot
Navigate to `http://your-domain.com/`. AgeCMS will auto-create `data.sqlite`, execute core migrations, compile the initial Bento theme, and initialize the WAL journal.

### 3. Connecting an AI Agent via MCP
To interact with AgeCMS via Cursor or Claude Desktop, point your MCP client configuration to `/admin/mcp.php`:

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

## 🛡 Security Policy & Threat Mitigation

AgeCMS is designed for secure deployment in both internal agency workflows and hardened production environments:
* **HMAC-SHA256 Signatures:** Required on all `/admin/mcp.php` calls.
* **Bulk Mutation Circuit Breaker:** Rejects agent operations affecting >15% of pages in a single transaction.
* **Append-Only Revisions:** SQLite database triggers prevent deletion or editing of historical revision logs.

For full threat analysis and security guidelines, see [`THREAT_MODEL.md`](./THREAT_MODEL.md) and [`SECURITY.md`](./SECURITY.md).

---

## 📄 License & Attribution

Designed and developed by **Tabson.ru Studio**.  
All rights reserved. Confidential in-house core software.
