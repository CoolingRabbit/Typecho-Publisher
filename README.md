# Typecho-Publisher

**English** | [中文文档](docs/README.zh-CN.md)

> A dirt-cheap PHP shared host + a Typecho blog + this plugin = **an online knowledge base managed directly by multiple AI agents**.

Once installed, each AI agent connects with its **own token** bound to its **own Typecho user account** — articles stay attributed to the right agent, and no agent can touch another's work.

---

## Introduction

Typecho-Publisher (formerly OpenClawTypecho) is a Typecho plugin plus an AI skill that lets AI agents create, query, update, and delete blog posts through a simple REST API and a `typecho-cli` command-line tool.

Since v4.0.0, the plugin is **multi-agent native**:

```
Site owner (administrator)
  └── Admin panel「Manage → AI Token」
        ├── User "agent-writer" (contributor) ← Token A ── AI Agent 1
        ├── User "agent-archiver" (contributor) ← Token B ── AI Agent 2
        └── User "agent-translator" (contributor) ← Token C ── AI Agent 3
```

| Boundary | Rule |
|----------|------|
| **Identity** | Each token is bound to one Typecho user account; posts are authored by that account |
| **Read** | Agents can read **all** posts (required for knowledge-base retrieval) |
| **Write** | Agents can only update/delete **their own** posts — cross-account attempts get `403` |
| **Category** | Agents can only pick from **existing** categories (queryable via API), never create new ones |
| **Token security** | Server stores only SHA-256 hashes; shown once at creation; revocable per agent |
| **Audit** | Admin panel shows each token's last-used time |

---

## Why This Project

Among virtually all cloud providers, **plain PHP virtual hosting is the cheapest tier money can buy**. This project is built to run on exactly that — a shared host costing only **¥35/year** (about $5/year). No VPS, no Node.js, no Docker, no database tuning.

| | Typecho-Publisher | Obsidian + AI |
|---|---|---|
| **Cost** | ¥35/year shared host | Local is free, but AI plugins/APIs cost extra |
| **Setup** | Install plugin → issue token → done | Maintain CLAUDE.md, index.md, folder rules |
| **Access** | Online by default, share via URL | Local-first, sharing needs sync setup |
| **AI operations** | CLI writes DB directly, fixed parameters, zero hallucination | AI reads piles of Markdown, context explodes |
| **Multi-agent** | Per-agent tokens, per-agent authorship, no cross-write | Multiple AIs share one local vault, no attribution |
| **Best for** | Quick archiving, blog publishing, lightweight KB | Deep research, complex graphs, local builds |

If you want a knowledge base you can **toss anything at an AI and read online later** — this is for you.

---

## Typecho Plugin Installation

### Requirements

- Typecho ≥ 1.2.0 (verified on 1.3.0)
- PHP ≥ 7.4 (PHP 8.0+ recommended)
- MySQL 5.7+ / MariaDB 10.3+ (SQLite also works)

### Steps

1. Download `Plugin.php`, `Action.php`, and `panel.php` from this repository, and place them into your Typecho plugin directory: `usr/plugins/OpenClawTypecho/`
2. Log in to the Typecho admin → **Plugins** → enable **OpenClawTypecho** (activation automatically creates the token table)
3. Admin → **Users → Add User**: create one account per AI agent (the **Contributor** group is recommended)
4. Admin → **Manage → AI Token**: pick an account → **Generate Token**
   - The full token is shown **only once**, with a copy button — save it immediately
   - The list later shows only the first/last 5 characters for identification
5. Hand these two pieces of information to the corresponding AI agent:
   - Blog URL: `https://www.example.com`
   - API Token: `a1b2c3...`

Token management on the same page: **regenerate** (old token dies instantly), **revoke/restore** (suspend one agent without affecting others), **delete**, and **last-used** timestamps for audit.

> Upgrading from v3.x or earlier? Just overwrite the three PHP files — no need to deactivate. The token table is created automatically on first API call or panel visit, and the legacy single token is migrated. **Breaking change**: categories are no longer auto-created; agents must query existing categories first.

---

## Agent Skill Installation (Prompt)

**Option 1 — via OpenClaw (recommended):**

```bash
openclaw skills install typecho-publisher
```

**Option 2 — let the agent install it itself.** Paste the following prompt to your AI agent:

```text
Please install the typecho-publisher skill from this repository:
https://github.com/CoolingRabbit/Typecho-Publisher

Read typecho-publisher-skill/SKILL.md in the repository and follow it as your
operating manual for publishing to my Typecho blog. The CLI tool is
typecho-publisher-skill/typecho-cli (Python 3, no dependencies).

My blog connection info:
- Blog URL: https://www.example.com
- API Token: <paste the token issued in Admin → Manage → AI Token>

Save them to ~/.config/typecho-cli/config.json in this format:
{"domain": "https://www.example.com", "token": "<token>"}

Then run `typecho-cli categories` to verify the connection and show me the result.
```

---

## Repository Structure

```
Typecho-Publisher/
├── Plugin.php             ← Typecho plugin core (PHP)
├── Action.php             ← REST API handler (PHP)
├── panel.php              ← AI Token admin panel (PHP)
├── typecho-publisher-skill/   ← AI skill layer
│   ├── SKILL.md           ← Writing rules & operating manual for agents
│   ├── typecho-cli        ← Python CLI tool
│   └── plugin.json        ← Skill metadata
├── docs/
│   └── README.zh-CN.md    ← Full Chinese documentation
└── README.md              ← This file
```

---

## License

[GPL-3.0](LICENSE)

Copyright (c) 2026 CoolingRabbit
