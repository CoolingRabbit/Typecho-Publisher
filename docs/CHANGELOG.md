# 更新日志

本项目的完整版本更新记录。

---

## v4.0.1 · 修复升级后设置页 Server Error（2026-09-01）

### 修复

- **旧版本升级后打开插件「设置」页报 Server Error**：旧版配置项（`token`、`authorId`）已保存在数据库中，v4.0.0 的配置表单不再包含同名输入项，Typecho 回显已保存配置时调用空对象方法导致致命错误。现以隐藏域保留这两个字段——页面上不可见，旧值继续用于 Token 迁移。

从 v3.x 或更早版本升级的用户请直接使用本版本覆盖 `Plugin.php`（`Action.php`、`panel.php` 与 v4.0.0 相同）。

---

## v4.0.0 · 多 Agent 独立接入（2026-09-01）

一台虚拟主机上的 Typecho 博客，现在可以同时接入多个 AI Agent：每个 Agent 绑定独立的 Typecho 用户账户、使用独立 Token，文章各归各的账户，互不越权。

### 新增

- **Token 表 + 后台管理面板**：后台「管理 → AI Token」为每个 Agent 用户生成/重置/吊销/删除 Token，查看最近使用时间；Token 只完整显示一次（带复制按钮），列表仅显示首尾各 5 位；服务端只存 SHA-256 哈希
- **归属隔离**：`submit` 文章作者自动设为 Token 绑定账户；`update` / `delete` 仅限本账户名下文章，越权返回 403
- **`categories` API + CLI 命令**：`typecho-cli categories` 查询现有分类列表
- **取消自动创建分类**：传入不存在的分类返回错误，AI 需先查询后选择
- **自动迁移**：旧版单一 Token + 作者配置自动导入为 Token 记录，老 CLI 无感升级；覆盖升级（未重新激活插件）时 API 侧自动兜底建表

### 修复

- `update` 仅传 category/tags 时被误判"没有需要更新的字段"的问题

### 升级方式

1. 用新版 `Plugin.php`、`Action.php`、`panel.php` 覆盖插件目录 `/usr/plugins/OpenClawTypecho/`
2. 无需停用插件：首次 API 调用或打开「管理 → AI Token」时自动建表并迁移旧 Token
3. 建议为每个 Agent 创建独立贡献者账户并签发独立 Token，然后吊销迁移来的旧 Token

⚠️ 行为变更：分类不再自动创建，请同步升级 SKILL 到 4.0.0（AI 会先查询分类列表再发布）。

---

## v3.0.0（2026-07-06）

- **新增 `typecho-publisher-skill/` 目录**：包含 `typecho-cli` Python CLI 工具、plugin.json、更新版 SKILL.md
- **AI 操作方式变更**：不再通过读 SKILL.md 手动拼 HTTP 请求，改为调用 `typecho-cli` 命令
- **ClawHub Skill 合并**：旧版 `typecho-publisher-skill` 合并到 `typecho-publisher`，可通过 `openclaw skills install typecho-publisher` 安装
- **支持环境变量配置**：`TYPECHO_DOMAIN` / `TYPECHO_TOKEN`

---

## v2.0.2

- 新增 `.gitignore` 排除 `config.json` 等敏感文件
- 新增「发布安全提醒」章节

## v2.0.1

- 同步 SKILL.md 与本地版本
- category/tags 改为必填项
- 新增前置条件与快速配置章节

## v2.0.0

- 统一版本号，精简文档结构
- 增强错误提示信息
- 新增限制说明与 FAQ

---

## v1.1.0

- 新增文章查询列表（`list`）
- 新增单篇文章查询（`get`）
- 新增文章更新（`update`）
- 新增文章删除（`delete`）
- 统一单入口路由，通过 `action` 字段分发

## v1.0.0

- 初始版本：支持文章创建（`submit`）
