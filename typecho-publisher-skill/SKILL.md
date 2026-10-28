---
name: typecho-publisher
description: 通过 typecho-cli 管理并发布 Typecho 博客文章，支持创建、查询、更新、删除、分类查询，以及根据仓库或开发记录撰写技术复盘、整理教程和 AI 协作记录后发布。涉及“发博客”“我的博客”“Typecho”“归档”“知识库”，或要求写文发布、修改已有博文时使用。
---

# Typecho Publisher

使用技能目录内的 `typecho-cli` 管理 Typecho 文章。把内容生产与发布操作分开：需要创作或改写时先走编辑流程，再进入发布流程。

## 解析运行环境

- 先定位当前 `SKILL.md` 所在目录，以下称“技能目录”；不要假设当前工作目录就是技能目录。
- 从技能目录解析 CLI 与参考文件；不要写死某个安装环境中的绝对路径。
- 使用 `python3 <技能目录>/typecho-cli <command>` 调用工具，不要直接拼凑 HTTP 请求。
- 运行需要 Python 3，以及可访问目标 Typecho 博客的网络。

## 配置与身份

配置读取优先级为：命令行 `--domain` / `--token` > 环境变量 `TYPECHO_DOMAIN` / `TYPECHO_TOKEN` > 技能目录 `config.json` > `~/.config/typecho-cli/config.json`。

- 可以将 `config.example.json` 复制为技能目录的 `config.json` 并填写真实配置，也可以写入 `~/.config/typecho-cli/config.json`。真实 `config.json` 已被 `.gitignore` 排除，不得提交到仓库或打入公开分发包。
- 只使用现有配置或用户明确提供的 `domain` 与 Token；当前任务上下文中已有用户明确提供的 Token 时可以沿用，但不得回显。不要从博客页面、代码仓库或无关文件中搜索凭据。
- 配置可用性未知时，先按发布流程完成预检，再执行查重、发布等其他请求。
- 不要把 Token、后台用户名或密码写入文章、日志、回复或公开分发包。
- 已配置有效 Token 时直接执行，不要重复向用户索取；401 时再提醒站长到「管理 → AI Token」检查或重新生成。
- 一个 Token 绑定一个 Typecho 账户。可读取所有文章，只能创建到本账户名下，也只能更新或删除本账户文章。
- 只能使用博客现有分类，不要编造或自动创建分类。

## 选择任务路径

| 任务 | 读取模块 |
|---|---|
| 查询、分类、发布现成文章、更新元数据 | [operations.md](references/operations.md) |
| 根据素材写文、润色或改写后发布 | [editorial.md](references/editorial.md)，随后执行发布流程 |
| 根据仓库或开发记录写技术复盘 | [editorial.md](references/editorial.md) 与 [technical-retrospective.md](references/technical-retrospective.md) |
| 修改已有文章正文 | 获取线上原文；按修改范围读取 [editorial.md](references/editorial.md)，再执行更新流程 |
| 删除文章、处理错误或超时 | [operations.md](references/operations.md) |

参考文件只按需读取，不要在简单查询时加载写作模块。

## 不可省略的边界

1. 发布前查询分类，并从实际返回结果中选择；没有合适分类时与用户沟通。
2. 新建前检索已有文章，避免重复；同一主题下的独立新角度不必强行合并。
3. 更新正文前先 `get` 最新完整原文，以线上版本为基准；`update --text` 是完整替换，不是增量追加。
4. 用户指定局部修改时只改授权范围，保留其他内容及用户手动调整。
5. 需要直接发布时显式传 `--status publish`；用户只要求写稿或草稿时不要擅自发布。
6. 写操作后读回核验；删除不可逆，执行前必须二次确认。
7. 网络超时或连接失败时先查询目标状态，再决定是否重试，避免重复创建文章。

详细命令、操作步骤和错误处理见 [operations.md](references/operations.md)。
