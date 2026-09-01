# Typecho-Publisher (原名OpenClawTypecho)

> 一台廉价的 PHP 虚拟主机 + 一个 Typecho 博客 + 这个插件 = **多个 AI 同时管理的在线知识库**。

装完插件，每个 AI Agent 使用**独立 Token** 接入博客，文章各归各的账户，互不越权。无需维护索引文件，无需配置目录规则，无需担心模型幻觉拼错 API。

---

## 为什么用这个方案

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

## 多 Agent 接入模型（v4.0.0 新增）

一个 Token 绑定一个 Typecho 用户账户，一个账户对应一个 AI Agent：

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

## 仓库结构

```
Typecho-Publisher/
├── Plugin.php          ← Typecho 插件本体（PHP）
├── Action.php          ← API 处理器（PHP）
├── panel.php           ← AI Token 后台管理面板（PHP，v4.0.0 新增）
├── typecho-publisher-skill/ ← AI Skill 工具层
│   ├── SKILL.md        ← AI 写作规范与操作指南
│   ├── typecho-cli     ← Python CLI 工具
│   └── plugin.json     ← Skill 元数据
└── README.md           ← 本文档
```

---

## 安装插件（Typecho 站长）

### 环境要求

- Typecho ≥ 1.2.0
- PHP ≥ 7.4（推荐 PHP 8.0+）
- MySQL 5.7+ / MariaDB 10.3+（SQLite 亦可）

### 步骤

#### Typecho 插件安装
1. 下载本仓库的 Plugin.php、Action.php、panel.php，放至 你的Typecho插件目录 /www/usr/plugins/OpenClawTypecho
2. 登录Typecho Web 后台 → 插件 → 启用 OpenClawTypecho（激活时会自动创建 Token 表）

#### 为每个 AI Agent 签发 Token
1. 后台 → 用户 → 新增用户：为每个 AI Agent 创建独立账户，用户组建议设为「贡献者」
2. 后台 → **管理 → AI Token**：选择对应账户 → 生成 Token
3. Token **只完整显示一次**，点击复制按钮保存（列表中之后只显示首尾各 5 位供辨认）
4. 将以下信息提供给对应的 AI Agent：
  博客地址：https://www.example.com
  API Token：a1b2c3...

#### Token 管理
- **重置**：旧 Token 立即失效，新 Token 同样只显示一次
- **吊销 / 恢复**：临时停用某个 Agent 的访问权限，不影响其他 Agent
- **最近使用**：列表展示每个 Token 的最近调用时间，方便审计

#### Skill 安装
**方式一：通过 OpenClaw 安装（推荐）**

```bash
openclaw skills install typecho-publisher
```

> 旧版 `typecho-publisher-skill` 已合并到 `typecho-publisher`，安装旧 slug 会自动重定向到新版本。

**方式二：将本仓库地址复制给ai，让他自己想办法**
o_o ....

### CLI 命令速查

```bash
# 查询文章列表
typecho-cli list [--page N] [--page-size N] [--status STATUS] [--category CATEGORY]

# 查询单篇文章
typecho-cli get --cid <文章ID>

# 查询现有分类列表（v4.0.0 新增，发布前必查）
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
| **分类** | v4.0.0 起**不再自动创建分类**。传入不存在的分类会报错，需先通过 `categories` 查询现有分类；都不合适请人工在后台新建 |
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

## 从旧版本升级

1. 用新版 Plugin.php、Action.php、panel.php 覆盖插件目录中的旧文件
2. **无需停用插件**：首次 API 调用或打开「管理 → AI Token」时会自动创建 Token 表
3. 旧版单一 Token 和作者配置会**自动迁移**为一条 Token 记录，老的 CLI 配置可以继续用，无感升级
4. 建议后续在「管理 → AI Token」中为每个 Agent 创建独立账户并签发独立 Token，然后吊销迁移来的旧 Token

> ⚠️ 行为变更提醒：v4.0.0 起分类**不再自动创建**，AI 传入了不存在的分类会收到错误提示。请确保 SKILL.md 同步升级到 4.0.0（AI 会先查询分类列表再发布）。

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
**A:** 这篇文章不属于当前 Token 绑定的用户账户（可能属于人工账户或其他 Agent）。v4.0.0 起每个 Agent 只能管理自己名下的文章，这是设计行为，请联系站长在后台处理。

### Q: 返回 "分类不存在"？
**A:** v4.0.0 起不再自动创建分类。先执行 `typecho-cli categories` 查看现有分类，从中选择；都不合适请在后台手动新建分类后重试。

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

### v4.0.1
- **修复**：旧版本升级后打开插件「设置」页报 Server Error。原因是旧版配置项（token、authorId）已保存在数据库中，Typecho 设置页回显配置时找不到同名输入项导致致命错误；现以隐藏域保留这两个字段（旧值继续用于迁移，页面上不可见）

### v4.0.0
- **多 Agent 支持**：新增 Token 表，每个 AI Agent 绑定独立 Typecho 用户账户、使用独立 Token
- **新增后台管理面板**「管理 → AI Token」：生成/重置/吊销/删除 Token，查看最近使用时间，Token 只显示一次（带复制按钮），列表仅显示首尾各 5 位
- **归属隔离**：`update` / `delete` 仅限本账户名下文章，越权返回 403；`submit` 文章作者自动设为 Token 绑定账户
- **新增 `categories` 操作 + CLI 命令**：查询现有分类列表
- **取消自动创建分类**：传入不存在的分类返回错误，AI 需先查询后选择
- **自动迁移**：旧版单一 Token + 作者配置自动导入为 Token 记录，老 CLI 无感升级
- Token 服务端只存 SHA-256 哈希；鉴权成功自动更新最近使用时间
- 修复：`update` 仅传 category/tags 时被误判"没有需要更新的字段"的问题

### v3.0.0
- **新增 `typecho-publisher-skill/` 目录**：包含 `typecho-cli` Python CLI 工具、plugin.json、更新版 SKILL.md
- **AI 操作方式变更**：不再通过读 SKILL.md 手动拼 HTTP 请求，改为调用 `typecho-cli` 命令
- **ClawHub Skill 合并**：旧版 `typecho-publisher-skill` 合并到 `typecho-publisher`
- **支持环境变量配置**：`TYPECHO_DOMAIN` / `TYPECHO_TOKEN`

### v2.0.2
- 新增 `.gitignore` 排除 `config.json` 等敏感文件
- 新增「发布安全提醒」章节

### v2.0.1
- 同步 SKILL.md 与本地版本
- category/tags 改为必填项
- 新增前置条件与快速配置章节

### v2.0.0
- 统一版本号，精简文档结构
- 增强错误提示信息
- 新增限制说明与 FAQ

### v1.1.0
- 新增文章查询列表（`list`）
- 新增单篇文章查询（`get`）
- 新增文章更新（`update`）
- 新增文章删除（`delete`）
- 统一单入口路由，通过 `action` 字段分发

### v1.0.0
- 初始版本：支持文章创建（`submit`）

---

## 许可证

[GPL-3.0](LICENSE)

Copyright (c) 2026 CoolingRabbit
