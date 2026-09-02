<?php
namespace TypechoPlugin\OpenClawTypecho;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Layout;
use Typecho\Db;
use Typecho\Options;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * OpenClaw Typecho Skill
 *
 * 为 OpenClaw 等 AI 服务提供 REST API，支持向 Typecho 博客创建、查询、更新、删除文章，构建 AI 知识库。
 * v4.0.0 起支持多 Agent：每个 AI Agent 绑定一个 Typecho 用户账户，使用独立 Token 接入。
 *
 * @package OpenClawTypecho
 * @author CoolingRabbit
 * @version 4.1.0
 * @link https://github.com/CoolingRabbit/Typecho-Publisher
 */
class Plugin implements PluginInterface
{
    /**
     * Token 表名（不含前缀）
     */
    const TOKEN_TABLE = 'openclaw_tokens';

    /**
     * 激活插件
     */
    public static function activate()
    {
        Helper::addAction('openclaw-submit', '\TypechoPlugin\OpenClawTypecho\Action');
        Helper::addPanel(3, 'OpenClawTypecho/panel.php', _t('AI Token'), _t('管理 AI Agent 的 API 访问令牌'), 'administrator');
        self::installTokenTable();
        return _t('插件已激活，请进入「管理 → AI Token」为 Agent 用户生成访问令牌');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        Helper::removeAction('openclaw-submit');
        Helper::removePanel(3, 'OpenClawTypecho/panel.php');
    }

    /**
     * 配置面板（v4.0.0 起 Token 迁移至独立管理页，此处仅保留指引）
     */
    public static function config(Form $form)
    {
        $panelUrl = Helper::url('OpenClawTypecho/panel.php');

        $note = new Layout('div', ['class' => 'description']);
        $note->html(
            _t('自 v4.0.0 起，API Token 改为按用户独立管理：每个 AI Agent 对应一个 Typecho 用户账户，使用专属 Token 接入。') .
            '<br>' .
            sprintf(_t('请前往 <a href="%s">管理 → AI Token</a> 生成和管理令牌。旧版单一 Token 已在升级时自动迁移，无需重新配置。'), $panelUrl)
        );
        $form->addItem($note);

        // 旧版配置字段以隐藏域保留：Typecho 设置页会将已保存的配置值回显到同名输入项，
        // 若输入项不存在会导致设置页报错；同时保留旧值供迁移逻辑读取
        $form->addInput(new Hidden('token'));
        $form->addInput(new Hidden('authorId'));
    }

    /**
     * 个人配置
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 生成随机 Token（48 位十六进制字符）
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * 确保 Token 表存在。
     * 覆盖升级（直接替换插件文件、未重新激活插件）时表可能不存在，
     * 在 API 鉴权和管理面板中调用本方法兜底建表 + 迁移。
     */
    public static function ensureTokenTable(): void
    {
        $db = Db::get();
        try {
            $db->fetchRow($db->select('id')->from('table.' . self::TOKEN_TABLE)->limit(1));
        } catch (\Throwable $e) {
            self::installTokenTable();
        }
    }

    /**
     * 创建 Token 表并迁移旧版配置
     */
    public static function installTokenTable(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TOKEN_TABLE;

        $adapterName = method_exists($db, 'getAdapterName') ? $db->getAdapterName() : 'Mysql';

        if (stripos($adapterName, 'sqlite') !== false) {
            $db->query(
                "CREATE TABLE IF NOT EXISTS \"{$table}\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"author_uid\" INTEGER NOT NULL,
                    \"token_hash\" TEXT NOT NULL,
                    \"token_prefix\" TEXT NOT NULL DEFAULT '',
                    \"token_suffix\" TEXT NOT NULL DEFAULT '',
                    \"status\" TEXT NOT NULL DEFAULT 'active',
                    \"created_at\" INTEGER NOT NULL DEFAULT 0,
                    \"last_used_at\" INTEGER NOT NULL DEFAULT 0
                )",
                Db::WRITE
            );
            $db->query(
                "CREATE UNIQUE INDEX IF NOT EXISTS \"{$table}_author_uid\" ON \"{$table}\" (\"author_uid\")",
                Db::WRITE
            );
        } else {
            $db->query(
                "CREATE TABLE IF NOT EXISTS `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `author_uid` INT UNSIGNED NOT NULL,
                    `token_hash` CHAR(64) NOT NULL,
                    `token_prefix` VARCHAR(8) NOT NULL DEFAULT '',
                    `token_suffix` VARCHAR(8) NOT NULL DEFAULT '',
                    `status` VARCHAR(10) NOT NULL DEFAULT 'active',
                    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
                    `last_used_at` INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `author_uid` (`author_uid`),
                    KEY `token_hash` (`token_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                Db::WRITE
            );
        }

        self::migrateLegacyToken();
    }

    /**
     * 迁移旧版单一 Token 配置为一条 Token 记录（老 CLI 无感升级）
     */
    protected static function migrateLegacyToken(): void
    {
        try {
            $config = Options::alloc()->plugin('OpenClawTypecho');
            $token = trim((string)($config->token ?? ''));
            $authorId = intval($config->authorId ?? 0);
        } catch (\Throwable $e) {
            return;
        }

        if ($token === '' || $authorId <= 0) {
            return;
        }

        $db = Db::get();

        // 该用户已有 Token 记录则不重复迁移
        $exists = $db->fetchRow(
            $db->select('id')
                ->from('table.' . self::TOKEN_TABLE)
                ->where('author_uid = ?', $authorId)
                ->limit(1)
        );
        if ($exists) {
            return;
        }

        $db->query(
            $db->insert('table.' . self::TOKEN_TABLE)->rows([
                'author_uid'   => $authorId,
                'token_hash'   => hash('sha256', $token),
                'token_prefix' => substr($token, 0, 5),
                'token_suffix' => substr($token, -5),
                'status'       => 'active',
                'created_at'   => time(),
                'last_used_at' => 0,
            ])
        );
    }
}
