# Typecho-Publisher 中文文档

[English](../README.md) | **中文**

> 一台廉价的 PHP 虚拟主机 + 一个 Typecho 博客 + 这个插件 = **多个 AI 同时管理的在线知识库**。

装完插件，每个 AI Agent 使用**独立 Token** 接入博客，文章各归各的账户，互不越权。无需维护索引文件，无需配置目录规则，无需担心模型幻觉拼错 API。

---

## 项目简介

Typecho-Publisher（原名 OpenClawTypecho）由一个 Typecho 插件和一个 AI Skill 组成，让 AI 通过 REST API 和 `typecho-cli` 命令行工具创建、查询、更新、删除博客文章。

v4.0.0 起原生支持**多 Agent 接入**：一个 Token 绑定一个 Typecho 用户账户，一个账户对应一个 AI Agent：

```
站长（管理员）
  └── 后台「管理 → AI Token」
        ├── 用户「虾坂爱」(贡献者) ← Token A ── AI Agent 1
        ├── 用户「归档bot」(贡献者) ← Token B ── AI Agent 2
        └── 用户「翻译bot」(贡献者) ← Token C ── AI Agent 3
```

| 边界 | 规则 |
|------|------|
| **身份与归属** | 文章作者 = Token 绑定的用户账户，前台署名、后台筛选天然分开 |
| **读取** | 所有 Agent 可读全部文章（知识库检索需要） |
| **写入** | 只能更新、删除**本账户名下**的文章，操作他人文章返回 403 |
| **分类** | 只能从**现有分类**中选择，不能新建；Agent 发布前先查询分类列表 |
| **Token 安全** | 服务端只存 SHA-256 哈希，生成时仅显示一次；可单独吊销/重置，互不影响 |
| **审计** | 后台可见每个 Token 的最近使用时间 |

---

## 项目优势

在绝大多数云服务器供应商那里，**PHP 虚拟主机都是最便宜的一档**。本项目就是为这种主机而生——跑在一台**仅 ¥35/年**的虚拟主机上。不需要 VPS，不需要 Node.js，不需要 Docker，不需要折腾数据库。

| | OpenClawTypecho | Obsidian + AI |
|---|---|---|
| **成本** | 虚拟主机 ¥35/年 | 本地免费，但 AI 插件/API 额外计费 |
| **配置** | 装插件 → 生成 Token → 完事 | 需维护 CLAUDE.md、index.md、目录结构 |
| **访问** | 天生在线，URL 即可分享 | 本地优先，分享需配同步 |
| **AI 操作** | CLI 工具直写数据库，参数固定零幻觉 | AI 需读取大量 Markdown，上下文易爆炸 |
| **多 Agent** | 每个 Agent 独立 Token、独立作者账户、互不越权 | 多 AI 共用一个本地库，无法区分归属 |
| **适用** | 快速归档、博客发布、轻量知识库 | 深度研究、复杂图谱、本地编译 |

如果你需要的是**随手丢给 AI 就能自动归档、随时在线查看**的知识库——这个方案就是为你准备的。

---

## 仓库结构

```
Typecho-Publisher/
├── Plugin.php          ← Typecho 插件本体（PHP）
├── Action.php          ← API 处理器（PHP）
├── panel.php           ← AI Token 后台管理面板（PHP，v4.0.0 新增）
├── typecho-publisher-skill/ ← AI Skill 工具层
│   ├── SKILL.md        ← Agent 操作规范
│   ├── typecho-cli     ← Python CLI 工具
│   └── plugin.json     ← Skill 元数据
├── docs/
│   ├── README.zh-CN.md ← 本文档
│   └── CHANGELOG.md    ← 版本更新日志
└── README.md           ← 英文说明
```

---

## Typecho 端插件安装方式

### 环境要求

- Typecho ≥ 1.2.0（已在 1.3.0 验证）
- PHP ≥ 7.4（推荐 PHP 8.0+）
- MySQL 5.7+ / MariaDB 10.3+（SQLite 亦可）

### 步骤

#### 1. 安装插件
1. 下载本仓库的 Plugin.php、Action.php、panel.php，放至你的 Typecho 插件目录 `usr/plugins/OpenClawTypecho/`
2. 登录 Typecho 后台 → 插件 → 启用 OpenClawTypecho（激活时会自动创建 Token 表）

#### 2. 为每个 AI Agent 签发 Token
1. 后台 → 用户 → 新增用户：为每个 AI Agent 创建独立账户，用户组建议设为「贡献者」
2. 后台 → **管理 → AI Token**：选择对应账户 → 生成 Token
3. Token **只完整显示一次**，点击复制按钮保存（列表中之后只显示首尾各 5 位供辨认）
4. 将以下信息提供给对应的 AI Agent：
   - 博客地址：`https://www.example.com`
   - API Token：`a1b2c3...`

#### 3. Token 管理
- **重置**：旧 Token 立即失效，新 Token 同样只显示一次
- **吊销 / 恢复**：临时停用某个 Agent 的访问权限，不影响其他 Agent
- **最近使用**：列表展示每个 Token 的最近调用时间，方便审计

> **从 v3.x 或更早版本升级**：直接用三个新 PHP 文件覆盖即可，无需停用插件。首次 API 调用或打开面板时会自动建表，旧版单一 Token 自动迁移。**行为变更**：分类不再自动创建，AI 需先查询现有分类。

---

## Agent 安装 Skill 的提示词

**方式一：通过 OpenClaw 安装（推荐）**

```bash
openclaw skills install @coolingrabbit/typecho-publisher
```

> 旧版 `typecho-publisher-skill` 已合并到 `typecho-publisher`，安装旧 slug 会自动重定向到新版本。

**方式二：让 AI 自己安装。** 把下面这段提示词原样发给你的 AI Agent：

```text
请帮我安装 typecho-publisher 技能，仓库地址：
https://github.com/CoolingRabbit/Typecho-Publisher

请阅读仓库中的 typecho-publisher-skill/SKILL.md，并将其作为你操作我 Typecho
博客的规范手册。CLI 工具是 typecho-publisher-skill/typecho-cli
（Python 3 编写，无第三方依赖）。

我的博客接入信息：
- 博客地址：https://www.example.com
- API Token：<粘贴后台「管理 → AI Token」签发的 Token>

请将以上信息写入 ~/.config/typecho-cli/config.json，格式：
{"domain": "https://www.example.com", "token": "<token>"}

然后执行 `typecho-cli categories` 验证连通性，并把结果展示给我。
```

---

## CLI 命令速查

```bash
# 查询文章列表
typecho-cli list [--page N] [--page-size N] [--status STATUS] [--category CATEGORY]

# 查询单篇文章
typecho-cli get --cid <文章ID>

# 查询现有分类列表（发布前必查）
typecho-cli categories

# 创建文章
typecho-cli submit \
  --title "文章标题" \
  --text "Markdown 正文" \
  --category "分类名" \
  --tags "标签1,标签2,标签3" \
  --status publish

# 更新文章（text 整体替换，不是增量追加；仅限本账户文章）
typecho-cli update \
  --cid <文章ID> \
  --title "新标题" \
  --text "新正文" \
  --tags "新标签1,新标签2"

# 删除文章（仅限本账户文章）
typecho-cli delete --cid <文章ID>
```

> ⚠️ `update` 的 `--text` 是**整体替换**，不是增量追加。更新前必须先 `get` 获取完整原文。
> ⚠️ `submit` / `update` 的 `--category` 必须来自 `categories` 查询结果，不能自造分类名。

### 环境变量方式（可选）

不写入配置文件，通过环境变量传入：

```bash
export TYPECHO_DOMAIN="https://www.example.com"
export TYPECHO_TOKEN="your-token-here"
typecho-cli list
```

---

## 开发者：直接调用 API

如果你不想用 CLI 工具，也可以直接调用 REST API：

### 通用说明

- **端点**：`{domain}/index.php/action/openclaw-submit`
- **方法**：POST
- **Content-Type**：`application/json`
- **鉴权**：请求头 `Authorization: Bearer <token>`（Token 绑定用户账户，写操作仅限该账户名下文章）

### 六个操作

| action | 用途 | 必填字段 | 权限说明 |
|--------|------|---------|---------|
| `submit` | 创建文章 | `title`, `text` | 文章归属 Token 绑定账户 |
| `list` | 查询列表 | — | 可读所有文章 |
| `get` | 查询单篇 | `cid` | 可读所有文章 |
| `categories` | 查询现有分类列表 | — | v4.0.0 新增 |
| `update` | 更新文章 | `cid` + 至少一个修改字段 | 仅限本账户文章，否则 403 |
| `delete` | 删除文章 | `cid` | 仅限本账户文章，否则 403 |

### curl 测试

```bash
curl -X POST https://your-blog.com/index.php/action/openclaw-submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-token-here" \
  -d '{"action":"list","page":1,"pageSize":5}'
```

---

## 限制与注意事项

### 功能限制

| 限制 | 说明 |
|------|------|
| **图片上传** | ❌ 不支持。图片需使用外部图床 URL，在正文用 Markdown 图片语法 `![alt](url)` 引用 |
| **正文长度** | 不超过 50KB（**字节长度**，中文约 1.6 万字），超长应分多篇 |
| **增量更新** | ❌ `update` 的 `text` 字段是**整体替换**，不是增量追加。更新前必须先 `get` 获取完整原文 |
| **分类** | **不再自动创建分类**。传入不存在的分类会报错，需先通过 `categories` 查询现有分类；都不合适请人工在后台新建 |
| **跨账户操作** | ❌ 不能更新、删除其他用户（含其他 Agent）名下的文章，返回 403 |
| **敏感信息拦截** | 插件自动拦截手机号、身份证号、银行卡号。其他敏感信息（域名、IP、密码等）需 AI 在写作时主动处理 |

### 状态默认值

- API 请求中不传 `status` 时，默认值为 `waiting`
- SKILL.md 建议 AI 显式传 `status: "publish"` 直接发布

---

## 文章状态

| 状态 | 数据库存储 | 说明 |
|------|-----------|------|
| `publish` | `type=post, status=publish` | 已发布，公开可见 |
| `waiting` | `type=post, status=waiting` | 待审核，后台手动发布 |
| `draft` | `type=post_draft, status=publish` | 草稿，不公开 |
| `private` | `type=post, status=private` | 私密，仅作者可见 |
| `hidden` | `type=post, status=hidden` | 隐藏，可通过 URL 访问但不在列表中 |

> `draft` 状态在数据库中存储为 `type=post_draft, status=publish`，这是 Typecho 的 draft 实现机制，调用方无需关心。

---

## 安全机制

| 机制 | 说明 |
|------|------|
| 独立 Token 鉴权 | 每个 Agent 一把 Token，服务端只存 SHA-256 哈希，生成时仅显示一次 |
| 归属隔离 | update / delete 强制校验文章作者 = Token 绑定账户，越权返回 403 |
| 单独吊销 | 吊销某把 Token 不影响其他 Agent；吊销立即生效 |
| POST + JSON 限制 | 拒绝 GET 请求和表单提交，减少 CSRF 风险 |
| 管理面板防护 | Token 管理仅管理员可见，表单带 CSRF 校验，操作均有确认提示 |
| 敏感信息拦截 | 自动检测手机号、身份证号、银行卡号 |
| XSS 过滤 | 标签字段经 `Validate::xssCheck()` 处理 |
| 长度限制 | 标题 ≤ 200 字符，正文 ≤ 50KB |
| 独立作者账户 | 每个 Agent 一个贡献者账户，人工文章与 AI 文章、AI 与 AI 文章均可区分 |

---

## 错误响应

所有错误统一返回：

```json
{
  "success": false,
  "message": "错误描述"
}
```

| HTTP 状态码 | 含义 | 常见原因 |
|-------------|------|---------|
| 400 | 请求参数错误 | 缺少必填字段、长度超限、敏感信息、文章不存在、分类不存在 |
| 401 | 鉴权失败 | Token 未提供、格式错误、Token 无效或已吊销 |
| 403 | 无权操作 | 尝试更新/删除其他用户名下的文章 |

---

## FAQ

### Q: API 返回 404 Not Found？
**A:** 检查以下三点：
1. 插件是否已在后台启用
2. 目录名是否为 `OpenClawTypecho`（大小写敏感）
3. 如果使用伪静态，确保 `index.php` 在路由中

### Q: 文章发布成功但前台看不到？
**A:** 检查 `status` 字段：
- `publish` → 前台可见
- `waiting` → 仅后台可见，需手动审核
- `draft` → 仅后台草稿箱可见

### Q: 更新文章后内容变少了？
**A:** `update` 的 `text` 字段是**整体替换**，不是增量追加。正确流程：
1. 先 `get` 获取完整原文
2. 在完整原文基础上修改
3. 将修改后的**完整正文**传入 `update`

### Q: CLI 工具返回 "鉴权失败：Token 无效或已吊销"？
**A:** 登录后台 → 管理 → AI Token，检查对应 Token 状态：
- 状态为「已吊销」→ 点击恢复，或重置后把新 Token 发给 Agent
- 找不到记录 → 重新生成 Token
- Token 被重置过 → 旧 Token 已失效，需使用新 Token

### Q: 返回 "无权操作：只能更新/删除本人账户名下的文章"（403）？
**A:** 这篇文章不属于当前 Token 绑定的用户账户（可能属于人工账户或其他 Agent）。每个 Agent 只能管理自己名下的文章，这是设计行为，请联系站长在后台处理。

### Q: 返回 "分类不存在"？
**A:** 插件不会自动创建分类。先执行 `typecho-cli categories` 查看现有分类，从中选择；都不合适请在后台手动新建分类后重试。

### Q: 敏感信息被拦截，怎么排查？
**A:** 插件自动检测以下 3 类信息：
- 手机号（1 开头 11 位数字）
- 身份证号（15 或 18 位）
- 银行卡号（16-19 位数字）

如被拦截，请检查正文并将这些信息替换为占位符（如 `<phone>`）。

### Q: 分类/标签没有生效？
**A:** 标签在传入非空值时才会创建和关联。分类必须是已存在的分类名（先 `categories` 查询），传入空字符串则不设置分类。

---

## 更新日志

完整的版本更新记录已移至 [CHANGELOG.md](CHANGELOG.md)。

---

## 许可证

[GPL-3.0](../LICENSE)

Copyright (c) 2026 CoolingRabbit
