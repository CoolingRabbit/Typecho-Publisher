# Typecho-Publisher (原名OpenClawTypecho)

> 一台廉价的 PHP 虚拟主机 + 一个 Typecho 博客 + 这个插件 = **AI 直接管理的在线知识库**。

装完插件，AI 通过 `typecho-cli` 工具即可开始归档、检索、更新文章。无需维护索引文件，无需配置目录规则，无需担心模型幻觉拼错 API。

---

## 为什么用这个方案

| | OpenClawTypecho | Obsidian + AI |
|---|---|---|
| **成本** | 虚拟主机 ¥35/年 | 本地免费，但 AI 插件/API 额外计费 |
| **配置** | 装插件 → 配置 Token → 完事 | 需维护 CLAUDE.md、index.md、目录结构 |
| **访问** | 天生在线，URL 即可分享 | 本地优先，分享需配同步 |
| **AI 操作** | CLI 工具直写数据库，参数固定零幻觉 | AI 需读取大量 Markdown，上下文易爆炸 |
| **适用** | 快速归档、博客发布、轻量知识库 | 深度研究、复杂图谱、本地编译 |

如果你需要的是**随手丢给 AI 就能自动归档、随时在线查看**的知识库——这个方案就是为你准备的。

---

## 仓库结构

```
Typecho-Publisher/
├── Plugin.php          ← Typecho 插件本体（PHP）
├── Action.php          ← API 处理器（PHP）
├── typecho-publisher-skill/ ← AI Skill 工具层（v3.0.0 新增）
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
- MySQL 5.7+ / MariaDB 10.3+

### 步骤

#### Typecho 插件安装
1. 下载本仓库的 Plugin.php 和 Action.php，放至 你的Typecho插件目录 /www/usr/plugins/OpenClawTypecho
2. 登录Typecho Web 后台 → 插件 → 启用 OpenClawTypecho
3. 点击 🔑 自动生成随机 Token，复制保存
4. 选择 AI 发布文章使用的作者账户（建议创建独立「贡献者」用户）
5. 将以下信息提供给 AI：
  博客地址：https://www.example.com
  API Token：CYSlXpaX...

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

# 创建文章
typecho-cli submit \
  --title "文章标题" \
  --text "Markdown 正文" \
  --category "分类名" \
  --tags "标签1,标签2,标签3" \
  --status publish

# 更新文章（text 整体替换，不是增量追加）
typecho-cli update \
  --cid <文章ID> \
  --title "新标题" \
  --text "新正文" \
  --tags "新标签1,新标签2"

# 删除文章
typecho-cli delete --cid <文章ID>
```

> ⚠️ `update` 的 `--text` 是**整体替换**，不是增量追加。更新前必须先 `get` 获取完整原文。

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
- **鉴权**：请求头 `Authorization: Bearer <token>`

### 五个操作

| action | 用途 | 必填字段 |
|--------|------|---------|
| `submit` | 创建文章 | `title`, `text` |
| `list` | 查询列表 | — |
| `get` | 查询单篇 | `cid` |
| `update` | 更新文章 | `cid` + 至少一个修改字段 |
| `delete` | 删除文章 | `cid` |

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
| **敏感信息拦截** | 插件自动拦截手机号、身份证号、银行卡号。其他敏感信息（域名、IP、密码等）需 AI 在写作时主动处理 |
| **分类** | 不强制要求。传入空分类时文章无分类，传入新分类名时自动创建 |

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
| Bearer Token 鉴权 | 时序安全比较（`hash_equals`），防止旁路攻击 |
| POST + JSON 限制 | 拒绝 GET 请求和表单提交，减少 CSRF 风险 |
| 敏感信息拦截 | 自动检测手机号、身份证号、银行卡号 |
| XSS 过滤 | 标签字段经 `Validate::xssCheck()` 处理 |
| 长度限制 | 标题 ≤ 200 字符，正文 ≤ 50KB |
| 独立作者账户 | 建议为 AI 创建独立贡献者账户，区分人工与 AI 文章 |

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
| 400 | 请求参数错误 | 缺少必填字段、长度超限、敏感信息、文章不存在 |
| 401 | 鉴权失败 | Token 未配置、格式错误、Token 无效 |

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

### Q: CLI 工具返回 "鉴权失败"？
**A:** 检查 `~/.config/typecho-cli/config.json` 中的 `token` 是否与后台一致。修改 token 后需重新保存后台设置。

### Q: 敏感信息被拦截，怎么排查？
**A:** 插件自动检测以下 3 类信息：
- 手机号（1 开头 11 位数字）
- 身份证号（15 或 18 位）
- 银行卡号（16-19 位数字）

如被拦截，请检查正文并将这些信息替换为占位符（如 `<phone>`）。

### Q: 分类/标签没有生效？
**A:** 分类/标签在传入非空值时才会创建和关联。如果传入空字符串或空数组，则不会设置分类/标签。

---

## 更新日志

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
