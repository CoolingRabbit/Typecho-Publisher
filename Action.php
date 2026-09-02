<?php
namespace TypechoPlugin\OpenClawTypecho;

use Typecho\Validate;
use Widget\ActionInterface;
use Widget\Base\Contents;
use Widget\Contents\EditTrait;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * OpenClaw Typecho Skill - API 处理器
 *
 * 为 OpenClaw 等 AI 服务提供 REST API，支持文章的创建、查询、更新、删除。
 * v4.0.0 起采用多 Token 鉴权：每个 AI Agent 绑定一个 Typecho 用户，
 * 只能更新、删除自己账户名下的文章，分类只能从现有分类中选择。
 *
 * @version 4.1.0
 */
class Action extends Contents implements ActionInterface
{
    use EditTrait;

    /**
     * 当前 Token 绑定的用户 ID
     */
    protected int $agentUid = 0;

    /**
     * 获取主题字段 hook
     */
    protected function getThemeFieldsHook(): string
    {
        return '';
    }

    /**
     * 执行入口
     */
    public function execute()
    {
        // 不需要预查询数据
    }

    /**
     * 处理动作（统一入口）
     */
    public function action()
    {
        try {
            $this->handleRequest();
        } catch (\Exception $e) {
            $code = intval($e->getCode());
            if ($code < 400 || $code > 599) {
                $code = strpos($e->getMessage(), '鉴权失败') === 0 ? 401 : 400;
            }
            $this->sendError($e->getMessage(), $code);
        }
    }

    /**
     * 处理请求（路由分发）
     */
    protected function handleRequest()
    {
        if (!$this->request->isPost()) {
            throw new \Exception('只接受 POST 请求');
        }

        if (!$this->request->isJson()) {
            throw new \Exception('Content-Type 必须是 application/json');
        }

        $data = $this->request->get('@json');
        if (!is_array($data)) {
            throw new \Exception('JSON 格式无效');
        }

        $this->agentUid = $this->authenticate();

        $action = $data['action'] ?? 'submit';
        $allowedActions = ['submit', 'list', 'get', 'update', 'delete', 'categories'];

        if (!in_array($action, $allowedActions, true)) {
            throw new \Exception('无效的操作类型，允许的值为: ' . implode(', ', $allowedActions));
        }

        $method = 'handle' . ucfirst($action);
        $this->$method($data);
    }

    // ==================== 创建文章 ====================

    /**
     * 处理创建文章
     */
    protected function handleSubmit(array $data)
    {
        $title = $this->sanitizeString($data['title'] ?? '');
        $text = $this->sanitizeText($data['text'] ?? '');
        $markdown = isset($data['markdown']) ? !empty($data['markdown']) : true;
        $category = $this->sanitizeString($data['category'] ?? '');
        $tags = $this->sanitizeTags($data['tags'] ?? []);
        $slug = $this->sanitizeSlug($data['slug'] ?? null);
        $requestedStatus = $this->sanitizeStatus($data['status'] ?? 'waiting');

        $this->validateArticleInput($title, $text);

        if ($markdown) {
            $text = '<!--markdown-->' . $text;
        }

        // 处理 draft 与 waiting 的状态映射
        $type = 'post';
        $dbStatus = $requestedStatus;
        if ($requestedStatus === 'draft') {
            $type = 'post_draft';
            $dbStatus = 'publish';
        }

        $contents = [
            'title'        => $title,
            'text'         => $text,
            'type'         => $type,
            'status'       => $dbStatus,
            'authorId'     => $this->agentUid,
            'allowComment' => 1,
            'allowPing'    => 1,
            'allowFeed'    => 1,
        ];

        if ($slug) {
            $contents['slug'] = $slug;
        }

        // 分类校验前置：分类不存在直接报错，避免产生脏文章
        $categoryId = 0;
        if (!empty($category)) {
            $categoryId = $this->findCategory($category);
        }

        $cid = $this->insert($contents);

        if ($cid <= 0) {
            throw new \Exception('创建文章失败：数据库写入异常，请检查数据库权限和表结构');
        }

        if ($categoryId > 0) {
            $this->setCategories($cid, [$categoryId], false, false);
        }

        if (!empty($tags)) {
            $this->setTags($cid, implode(',', $tags), false, false);
        }

        // 刷新分类/标签计数（setCategories/setTags 已关闭内核自动计数）
        $this->updateCategoryCount();

        $this->response->throwJson([
            'success' => true,
            'message' => '文章已创建',
            'cid'     => $cid,
            'status'  => $requestedStatus,
            'action'  => 'submit',
        ]);
    }

    // ==================== 查询列表 ====================

    /**
     * 处理文章列表查询（可读所有文章，用于知识库检索）
     */
    protected function handleList(array $data)
    {
        $page = max(1, intval($data['page'] ?? 1));
        $pageSize = min(50, max(1, intval($data['pageSize'] ?? 10)));
        $statusFilter = isset($data['status']) ? strval($data['status']) : null;
        $categoryFilter = $this->sanitizeString($data['category'] ?? '');

        $offset = ($page - 1) * $pageSize;

        // 构建基础查询
        $select = $this->db->select(
                'c.cid',
                'c.title',
                'c.slug',
                'c.created',
                'c.modified',
                'c.status',
                'c.type',
                'c.authorId',
                'u.screenName as authorName'
            )
            ->from('table.contents as c')
            ->join('table.users as u', 'c.authorId = u.uid')
            ->where('c.type = ? OR c.type = ?', 'post', 'post_draft')
            ->order('c.created', \Typecho\Db::SORT_DESC)
            ->offset($offset)
            ->limit($pageSize);

        // 状态与分类过滤（与 count 查询共用同一组条件，保证 total 与列表一致）
        $this->applyListFilters($select, $statusFilter, $categoryFilter);

        $articles = $this->db->fetchAll($select);

        // 查询分类信息
        foreach ($articles as &$article) {
            $article['created'] = date('Y-m-d H:i:s', $article['created']);
            $article['modified'] = date('Y-m-d H:i:s', $article['modified']);
            $article['status'] = $this->mapDbStatusToLabel($article['status'], $article['type']);
            $article['categories'] = $this->getCategoriesByCid($article['cid']);
            $article['tags'] = $this->getTagsByCid($article['cid']);
            unset($article['type']);
        }

        // 统计总数（与列表查询使用相同的 join 和过滤条件）
        $countSelect = $this->db->select('COUNT(DISTINCT c.cid) as total')
            ->from('table.contents as c')
            ->join('table.users as u', 'c.authorId = u.uid')
            ->where('c.type = ? OR c.type = ?', 'post', 'post_draft');
        $this->applyListFilters($countSelect, $statusFilter, $categoryFilter);
        $total = $this->db->fetchRow($countSelect)['total'] ?? 0;

        $this->response->throwJson([
            'success'   => true,
            'action'    => 'list',
            'page'      => $page,
            'pageSize'  => $pageSize,
            'total'     => intval($total),
            'totalPage' => ceil(intval($total) / $pageSize),
            'data'      => $articles,
        ]);
    }

    // ==================== 查询单篇 ====================

    /**
     * 处理单篇文章查询（可读所有文章）
     */
    protected function handleGet(array $data)
    {
        $cid = intval($data['cid'] ?? 0);
        if ($cid <= 0) {
            throw new \Exception('缺少 cid 参数');
        }

        $article = $this->db->fetchRow(
            $this->db->select(
                'cid',
                'title',
                'slug',
                'text',
                'created',
                'modified',
                'status',
                'type',
                'authorId',
                'allowComment',
                'allowPing',
                'allowFeed'
            )
            ->from('table.contents')
            ->where('cid = ?', $cid)
            ->where('type = ? OR type = ?', 'post', 'post_draft')
            ->limit(1)
        );

        if (!$article) {
            throw new \Exception('文章不存在：cid ' . $cid . ' 对应的文章未找到');
        }

        $article['created'] = date('Y-m-d H:i:s', $article['created']);
        $article['modified'] = date('Y-m-d H:i:s', $article['modified']);
        $article['status'] = $this->mapDbStatusToLabel($article['status'], $article['type']);
        $article['isMarkdown'] = strpos($article['text'], '<!--markdown-->') === 0;
        if ($article['isMarkdown']) {
            $article['text'] = substr($article['text'], strlen('<!--markdown-->'));
        }
        $article['categories'] = $this->getCategoriesByCid($cid);
        $article['tags'] = $this->getTagsByCid($cid);
        unset($article['type']);

        $this->response->throwJson([
            'success' => true,
            'action'  => 'get',
            'data'    => $article,
        ]);
    }

    // ==================== 查询分类列表 ====================

    /**
     * 处理分类列表查询
     * Agent 只能使用现有分类，发布前通过本接口获取可选范围
     */
    protected function handleCategories(array $data)
    {
        $rows = $this->db->fetchAll(
            $this->db->select('mid', 'name', 'slug', 'count', 'parent')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->order('order', \Typecho\Db::SORT_ASC)
                ->order('mid', \Typecho\Db::SORT_ASC)
        );

        $categories = array_map(function ($row) {
            return [
                'mid'    => intval($row['mid']),
                'name'   => $row['name'],
                'slug'   => $row['slug'],
                'count'  => intval($row['count']),
                'parent' => intval($row['parent']),
            ];
        }, $rows);

        $this->response->throwJson([
            'success' => true,
            'action'  => 'categories',
            'total'   => count($categories),
            'data'    => $categories,
        ]);
    }

    // ==================== 更新文章 ====================

    /**
     * 处理文章更新（仅限本人账户名下的文章）
     */
    protected function handleUpdate(array $data)
    {
        $cid = intval($data['cid'] ?? 0);
        if ($cid <= 0) {
            throw new \Exception('缺少 cid 参数');
        }

        // 检查文章是否存在
        $exists = $this->db->fetchRow(
            $this->db->select('cid', 'type', 'status', 'authorId')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type = ? OR type = ?', 'post', 'post_draft')
                ->limit(1)
        );

        if (!$exists) {
            throw new \Exception('文章不存在：cid ' . $cid . ' 对应的文章未找到');
        }

        // 归属隔离：只能更新本人账户名下的文章
        $this->assertOwnership($exists, $cid, '更新');

        $update = [];

        // 标题
        if (isset($data['title'])) {
            $title = $this->sanitizeString($data['title']);
            if (empty($title)) {
                throw new \Exception('标题不能为空');
            }
            if (mb_strlen($title) > 200) {
                throw new \Exception('标题长度不能超过 200 字符');
            }
            $update['title'] = $title;
        }

        // 正文
        if (isset($data['text'])) {
            $text = $this->sanitizeText($data['text']);
            if (empty($text)) {
                throw new \Exception('正文不能为空');
            }
            if (mb_strlen($text) > 50000) {
                throw new \Exception('正文长度不能超过 50000 字符');
            }

            $markdown = isset($data['markdown']) ? !empty($data['markdown']) : true;
            if ($markdown) {
                $text = '<!--markdown-->' . $text;
            }
            $update['text'] = $text;
        }

        // 缩略名
        if (array_key_exists('slug', $data)) {
            $slug = $this->sanitizeSlug($data['slug']);
            if ($slug !== null) {
                $update['slug'] = $slug;
            }
        }

        // 状态
        if (isset($data['status'])) {
            $requestedStatus = $this->sanitizeStatus($data['status']);
            if ($requestedStatus === 'draft') {
                $update['type'] = 'post_draft';
                $update['status'] = 'publish';
            } else {
                $update['type'] = 'post';
                $update['status'] = $requestedStatus;
            }
        }

        if (empty($update) && !isset($data['category']) && !isset($data['tags'])) {
            throw new \Exception('没有需要更新的字段：请至少传入 title、text、category、tags、slug 或 status 中的一个');
        }

        // 敏感内容检查
        $checkContent = ($update['title'] ?? '') . ($update['text'] ?? '');
        if (!empty($checkContent)) {
            $this->checkSensitiveContent($checkContent);
        }

        // 分类校验前置：分类不存在直接报错，避免写库后才发现分类无效
        $categoryId = null;
        if (isset($data['category'])) {
            $category = $this->sanitizeString($data['category']);
            if (!empty($category)) {
                $categoryId = $this->findCategory($category);
            }
        }

        // 执行更新
        if (!empty($update)) {
            $update['modified'] = time();
            $this->db->query(
                $this->db->update('table.contents')
                    ->rows($update)
                    ->where('cid = ?', $cid)
            );
        }

        // 更新分类（已在写库前完成校验）
        if ($categoryId !== null) {
            $this->setCategories($cid, [$categoryId], false, false);
        }

        // 更新标签
        if (isset($data['tags'])) {
            $tags = $this->sanitizeTags($data['tags']);
            $this->setTags($cid, implode(',', $tags), false, false);
        }

        // 更新计数
        $this->updateCategoryCount();

        $this->response->throwJson([
            'success' => true,
            'message' => '文章已更新',
            'cid'     => $cid,
            'action'  => 'update',
        ]);
    }

    // ==================== 删除文章 ====================

    /**
     * 处理文章删除（仅限本人账户名下的文章）
     */
    protected function handleDelete(array $data)
    {
        $cid = intval($data['cid'] ?? 0);
        if ($cid <= 0) {
            throw new \Exception('缺少 cid 参数');
        }

        // 检查文章是否存在
        $exists = $this->db->fetchRow(
            $this->db->select('cid', 'authorId')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type = ? OR type = ?', 'post', 'post_draft')
                ->limit(1)
        );

        if (!$exists) {
            throw new \Exception('文章不存在：cid ' . $cid . ' 对应的文章未找到');
        }

        // 归属隔离：只能删除本人账户名下的文章
        $this->assertOwnership($exists, $cid, '删除');

        // 删除关联的分类和标签关系
        $this->db->query(
            $this->db->delete('table.relationships')
                ->where('cid = ?', $cid)
        );

        // 删除文章
        $this->db->query(
            $this->db->delete('table.contents')
                ->where('cid = ?', $cid)
        );

        // 更新分类计数
        $this->updateCategoryCount();

        $this->response->throwJson([
            'success' => true,
            'message' => '文章已删除',
            'cid'     => $cid,
            'action'  => 'delete',
        ]);
    }

    // ==================== 辅助方法 ====================

    /**
     * 归属校验：文章作者必须是当前 Token 绑定的用户
     */
    protected function assertOwnership(array $article, int $cid, string $verb): void
    {
        if (intval($article['authorId']) !== $this->agentUid) {
            throw new \Exception(
                '无权操作：只能' . $verb . '本人账户名下的文章（cid ' . $cid . ' 属于其他用户）',
                403
            );
        }
    }

    /**
     * 验证文章输入
     */
    protected function validateArticleInput(string $title, string $text): void
    {
        if (empty($title)) {
            throw new \Exception('标题不能为空');
        }

        if (empty($text)) {
            throw new \Exception('正文不能为空');
        }

        if (mb_strlen($title) > 200) {
            throw new \Exception('标题长度不能超过 200 字符');
        }

        if (mb_strlen($text) > 50000) {
            throw new \Exception('正文长度不能超过 50000 字符');
        }

        $this->checkSensitiveContent($title . $text);
    }

    /**
     * 获取文章的分类
     */
    protected function getCategoriesByCid(int $cid): array
    {
        $rows = $this->db->fetchAll(
            $this->db->select('m.name', 'm.slug')
                ->from('table.metas as m')
                ->join('table.relationships as r', 'm.mid = r.mid')
                ->where('r.cid = ?', $cid)
                ->where('m.type = ?', 'category')
        );

        return array_map(function ($row) {
            return ['name' => $row['name'], 'slug' => $row['slug']];
        }, $rows);
    }

    /**
     * 获取文章的标签
     */
    protected function getTagsByCid(int $cid): array
    {
        $rows = $this->db->fetchAll(
            $this->db->select('m.name', 'm.slug')
                ->from('table.metas as m')
                ->join('table.relationships as r', 'm.mid = r.mid')
                ->where('r.cid = ?', $cid)
                ->where('m.type = ?', 'tag')
        );

        return array_map(function ($row) {
            return $row['name'];
        }, $rows);
    }

    /**
     * 状态映射：前端标签 → 数据库存储
     */
    protected function mapStatusToDb(string $status): ?string
    {
        switch ($status) {
            case 'waiting': return 'waiting';
            case 'draft':   return 'publish';
            case 'publish': return 'publish';
            case 'private': return 'private';
            case 'hidden':  return 'hidden';
            default:        return null;
        }
    }

    /**
     * 状态映射：数据库存储 → 前端标签
     */
    protected function mapDbStatusToLabel(string $dbStatus, string $type): string
    {
        if ($type === 'post_draft') {
            return 'draft';
        }
        return $dbStatus;
    }

    /**
     * 鉴权：校验 Bearer Token 并返回绑定的用户 ID
     */
    protected function authenticate(): int
    {
        $auth = $this->request->getHeader('Authorization', '');
        if (empty($auth)) {
            throw new \Exception('鉴权失败：请求缺少 Authorization 头', 401);
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            throw new \Exception('鉴权失败：Authorization 格式错误，应为 Bearer <token>', 401);
        }

        $token = $matches[1];
        if (empty($token)) {
            throw new \Exception('鉴权失败：Token 为空', 401);
        }

        // 覆盖升级时表可能尚未创建，兜底建表 + 迁移旧配置
        Plugin::ensureTokenTable();

        $row = $this->db->fetchRow(
            $this->db->select('id', 'author_uid')
                ->from('table.openclaw_tokens')
                ->where('token_hash = ?', hash('sha256', $token))
                ->where('status = ?', 'active')
                ->limit(1)
        );

        if (!$row) {
            throw new \Exception('鉴权失败：Token 无效或已吊销，请进入后台「管理 → AI Token」检查', 401);
        }

        // Token 绑定的用户可能已被站长删除，此时 Token 必须失效
        $userExists = $this->db->fetchRow(
            $this->db->select('uid')
                ->from('table.users')
                ->where('uid = ?', intval($row['author_uid']))
                ->limit(1)
        );
        if (!$userExists) {
            throw new \Exception('鉴权失败：Token 绑定的用户已被删除，请联系站长重新签发', 401);
        }

        // 更新最近使用时间
        $this->db->query(
            $this->db->update('table.openclaw_tokens')
                ->rows(['last_used_at' => time()])
                ->where('id = ?', intval($row['id']))
        );

        return intval($row['author_uid']);
    }

    /**
     * 字符串清理
     */
    protected function sanitizeString(?string $value): string
    {
        return trim($value ?? '');
    }

    /**
     * 正文清理
     */
    protected function sanitizeText(?string $value): string
    {
        return trim($value ?? '');
    }

    /**
     * 标签清理
     */
    protected function sanitizeTags($tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        if (!is_array($tags)) {
            return [];
        }

        $result = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (mb_strlen($tag) > 0 && mb_strlen($tag) <= 100 && Validate::xssCheck($tag)) {
                $result[] = $tag;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * 缩略名清理
     */
    protected function sanitizeSlug(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^[a-zA-Z0-9\-_]+$/', $value) && strlen($value) <= 200) {
            return $value;
        }
        return null;
    }

    /**
     * 状态约束：白名单校验，非法值直接拒绝
     */
    protected function sanitizeStatus(?string $value): string
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return 'waiting';
        }
        $allowed = ['publish', 'draft', 'waiting', 'private', 'hidden'];
        if (!in_array($value, $allowed, true)) {
            throw new \Exception('无效的文章状态：' . $value . '，允许的值为: ' . implode(', ', $allowed));
        }
        return $value;
    }

    /**
     * 查找现有分类（v4.0.0 起不再自动创建分类）
     */
    protected function findCategory(string $name): int
    {
        $row = $this->db->fetchRow(
            $this->db->select('mid')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->where('name = ?', $name)
                ->limit(1)
        );

        if (!$row) {
            throw new \Exception('分类不存在：' . $name . '，请先通过 categories 操作查询现有分类列表');
        }

        return intval($row['mid']);
    }

    /**
     * 列表过滤条件：list 的查询与 count 统计共用，保证 total 与列表一致
     */
    protected function applyListFilters($select, ?string $statusFilter, string $categoryFilter): void
    {
        if ($statusFilter !== null) {
            $dbStatus = $this->mapStatusToDb($statusFilter);
            if ($dbStatus !== null) {
                $select->where('c.status = ?', $dbStatus);
                if ($statusFilter === 'draft') {
                    $select->where('c.type = ?', 'post_draft');
                } else {
                    $select->where('c.type = ?', 'post');
                }
            }
        }

        if ($categoryFilter !== '') {
            $select->join('table.relationships as rc', 'c.cid = rc.cid')
                ->join('table.metas as mc', 'rc.mid = mc.mid')
                ->where('mc.type = ?', 'category')
                ->where('mc.name = ?', $categoryFilter);
        }
    }

    /**
     * 刷新分类与标签的文章计数
     * （setCategories/setTags 以 false 关闭了内核自动计数，需手动维护 metas.count）
     */
    protected function updateCategoryCount(): void
    {
        $rows = $this->db->fetchAll(
            $this->db->select('table.metas.mid', 'COUNT(table.relationships.cid) AS `count`')
                ->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid', 'LEFT JOIN')
                ->where('table.metas.type = ? OR table.metas.type = ?', 'category', 'tag')
                ->group('table.metas.mid')
        );

        foreach ($rows as $row) {
            $this->db->query(
                $this->db->update('table.metas')
                    ->rows(['count' => intval($row['count'])])
                    ->where('mid = ?', intval($row['mid']))
            );
        }
    }

    /**
     * 敏感内容检查
     */
    protected function checkSensitiveContent(string $content): void
    {
        $patterns = [
            '手机号'    => '/(?<!\d)1[3-9]\d{9}(?!\d)/',
            '身份证号'  => '/(?<!\d)(?:\d{17}[\dXx]|\d{15})(?!\d)/',
            '银行卡号'  => '/(?<!\d)\d{16,19}(?!\d)/',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $content)) {
                throw new \Exception("内容疑似包含{$name}等敏感信息，请人工确认后提交");
            }
        }
    }

    /**
     * 返回错误
     */
    protected function sendError(string $message, int $code = 400)
    {
        $this->response->setStatus($code);
        $this->response->throwJson([
            'success' => false,
            'message' => $message,
        ]);
    }
}
