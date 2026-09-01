<?php
/**
 * OpenClawTypecho - AI Token 管理面板
 *
 * 为每个 AI Agent 用户生成、吊销、重置独立的 API Token。
 *
 * @version 4.0.0
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Cookie;
use Typecho\Db;
use Typecho\Request;
use Typecho\Response;
use Utils\Helper;

$request  = Request::getInstance();
$db       = Db::get();
$security = Helper::security();

\TypechoPlugin\OpenClawTypecho\Plugin::ensureTokenTable();

$panelUrl = Helper::url('OpenClawTypecho/panel.php');

// ---------- 用户组中文名 ----------
$groupLabels = [
    'administrator' => _t('管理员'),
    'editor'        => _t('编辑'),
    'contributor'   => _t('贡献者'),
    'subscriber'    => _t('关注者'),
];

// ---------- 处理操作（POST 后重定向，防止刷新重复提交） ----------
if ($request->isPost()) {
    try {
        if ($request->get('_') !== $security->getToken($panelUrl)) {
            throw new \Exception(_t('请求校验失败，请刷新页面后重试'));
        }

        $do = $request->get('do');

        switch ($do) {
            case 'generate':
                $uid = intval($request->get('uid'));
                $user = $db->fetchRow(
                    $db->select('uid', 'screenName', 'name')
                        ->from('table.users')
                        ->where('uid = ?', $uid)
                        ->limit(1)
                );
                if (!$user) {
                    throw new \Exception(_t('所选用户不存在'));
                }
                $exists = $db->fetchRow(
                    $db->select('id')
                        ->from('table.openclaw_tokens')
                        ->where('author_uid = ?', $uid)
                        ->limit(1)
                );
                if ($exists) {
                    throw new \Exception(_t('该用户已有 Token，如需更换请使用「重置」'));
                }

                $token = \TypechoPlugin\OpenClawTypecho\Plugin::generateToken();
                $db->query(
                    $db->insert('table.openclaw_tokens')->rows([
                        'author_uid'   => $uid,
                        'token_hash'   => hash('sha256', $token),
                        'token_prefix' => substr($token, 0, 5),
                        'token_suffix' => substr($token, -5),
                        'status'       => 'active',
                        'created_at'   => time(),
                        'last_used_at' => 0,
                    ])
                );

                Cookie::set('openclaw_flash_token', $token, 300);
                Cookie::set('openclaw_notice', _t('Token 已生成，请立即复制保存'), 300);
                Cookie::set('openclaw_notice_type', 'success', 300);
                break;

            case 'regenerate':
                $id = intval($request->get('id'));
                $row = $db->fetchRow(
                    $db->select('id')->from('table.openclaw_tokens')->where('id = ?', $id)->limit(1)
                );
                if (!$row) {
                    throw new \Exception(_t('Token 记录不存在'));
                }

                $token = \TypechoPlugin\OpenClawTypecho\Plugin::generateToken();
                $db->query(
                    $db->update('table.openclaw_tokens')
                        ->rows([
                            'token_hash'   => hash('sha256', $token),
                            'token_prefix' => substr($token, 0, 5),
                            'token_suffix' => substr($token, -5),
                            'status'       => 'active',
                        ])
                        ->where('id = ?', $id)
                );

                Cookie::set('openclaw_flash_token', $token, 300);
                Cookie::set('openclaw_notice', _t('Token 已重置，旧 Token 立即失效，请立即复制保存新 Token'), 300);
                Cookie::set('openclaw_notice_type', 'success', 300);
                break;

            case 'revoke':
            case 'activate':
                $id = intval($request->get('id'));
                $status = $do === 'revoke' ? 'revoked' : 'active';
                $db->query(
                    $db->update('table.openclaw_tokens')
                        ->rows(['status' => $status])
                        ->where('id = ?', $id)
                );
                Cookie::set('openclaw_notice', $do === 'revoke' ? _t('Token 已吊销，对应 Agent 将无法再调用 API') : _t('Token 已恢复启用'), 300);
                Cookie::set('openclaw_notice_type', 'notice', 300);
                break;

            case 'delete':
                $id = intval($request->get('id'));
                $db->query(
                    $db->delete('table.openclaw_tokens')->where('id = ?', $id)
                );
                Cookie::set('openclaw_notice', _t('Token 已删除'), 300);
                Cookie::set('openclaw_notice_type', 'notice', 300);
                break;

            default:
                throw new \Exception(_t('未知操作'));
        }
    } catch (\Throwable $e) {
        Cookie::set('openclaw_notice', $e->getMessage(), 300);
        Cookie::set('openclaw_notice_type', 'error', 300);
    }

    Response::getInstance()
        ->setStatus(302)
        ->setHeader('Location', $panelUrl)
        ->respond();
}

// ---------- 读取闪现数据（仅显示一次） ----------
$flashToken = Cookie::get('openclaw_flash_token');
$notice     = Cookie::get('openclaw_notice');
$noticeType = Cookie::get('openclaw_notice_type', 'notice');
if ($flashToken) {
    Cookie::delete('openclaw_flash_token');
}
if ($notice) {
    Cookie::delete('openclaw_notice');
    Cookie::delete('openclaw_notice_type');
}

// ---------- 查询数据 ----------
$tokens = $db->fetchAll(
    $db->select('t.id', 't.author_uid', 't.token_prefix', 't.token_suffix', 't.status', 't.created_at', 't.last_used_at',
                'u.screenName', 'u.name', 'u.group')
        ->from('table.openclaw_tokens as t')
        ->join('table.users as u', 't.author_uid = u.uid')
        ->order('t.created_at', Db::SORT_ASC)
);

$users = $db->fetchAll(
    $db->select('uid', 'screenName', 'name', 'group')
        ->from('table.users')
        ->order('uid', Db::SORT_ASC)
);

$tokenUserIds = array_map(function ($t) {
    return intval($t['author_uid']);
}, $tokens);

// ---------- 辅助函数 ----------
function octRelativeTime(int $ts): string
{
    if ($ts <= 0) {
        return _t('从未使用');
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return _t('刚刚');
    }
    if ($diff < 3600) {
        return floor($diff / 60) . _t(' 分钟前');
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . _t(' 小时前');
    }
    if ($diff < 2592000) {
        return floor($diff / 86400) . _t(' 天前');
    }
    return date('Y-m-d', $ts);
}

$csrfToken = $security->getToken($panelUrl);

include 'header.php';
include 'menu.php';
?>

<style>
/* ---- OpenClawTypecho 面板样式（遵循 Typecho 原生后台基调） ---- */
.oct-section { margin-bottom: 24px; }
.oct-section-title {
    margin: 0 0 10px;
    font-size: 14px;
    font-weight: bold;
    color: #444;
}
.oct-generate-bar {
    padding: 14px 16px;
    background: #fff;
    border: 1px solid #e3e3dc;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}
.oct-generate-bar select { max-width: 320px; }
.oct-generate-bar .oct-hint { color: #999; font-size: 12px; }

.oct-table td { vertical-align: middle; }
.oct-token-code {
    font-family: Consolas, "Courier New", monospace;
    font-size: 13px;
    letter-spacing: 1px;
    background: #f6f6f3;
    border: 1px solid #e3e3dc;
    padding: 2px 8px;
    white-space: nowrap;
}
.oct-status { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
.oct-status::before { content: ""; width: 8px; height: 8px; border-radius: 50%; }
.oct-status-active  { color: #4c9a4c; }
.oct-status-active::before  { background: #4c9a4c; }
.oct-status-revoked { color: #b94a48; }
.oct-status-revoked::before { background: #b94a48; }

.oct-time { white-space: nowrap; }
.oct-time small { display: block; color: #999; }

.oct-ops form { display: inline-block; margin: 0 2px 2px 0; }

.oct-empty {
    padding: 30px 20px;
    background: #fff;
    border: 1px solid #e3e3dc;
    color: #666;
}
.oct-empty ol { margin: 10px 0 0 22px; line-height: 2; }

/* 一次性 Token 弹层 */
.oct-modal-mask {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .45);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}
.oct-modal {
    width: 520px;
    max-width: 92vw;
    background: #fff;
    border: 1px solid #d8d8d3;
    box-shadow: 0 6px 30px rgba(0, 0, 0, .25);
}
.oct-modal-head {
    padding: 14px 20px;
    border-bottom: 1px solid #eee;
    font-size: 15px;
    font-weight: bold;
    color: #333;
}
.oct-modal-body { padding: 20px; }
.oct-modal-body p { margin: 0 0 12px; line-height: 1.8; color: #555; }
.oct-token-reveal {
    display: flex;
    gap: 8px;
    margin: 14px 0;
}
.oct-token-reveal input {
    flex: 1;
    font-family: Consolas, "Courier New", monospace;
    font-size: 13px;
    padding: 8px 10px;
    border: 1px solid #ccc;
    background: #fbfbf8;
    color: #222;
}
.oct-modal-warn { color: #b94a48; font-size: 13px; }
.oct-modal-foot {
    padding: 12px 20px;
    border-top: 1px solid #eee;
    text-align: right;
}
</style>

<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('AI Token 管理'); ?></h2>
        </div>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12" role="main">

                <?php if ($notice): ?>
                <ul class="message <?php echo htmlspecialchars($noticeType); ?>">
                    <li><?php echo htmlspecialchars($notice); ?></li>
                </ul>
                <?php endif; ?>

                <!-- 生成新 Token -->
                <div class="oct-section">
                    <h3 class="oct-section-title"><?php _e('生成新 Token'); ?></h3>
                    <form method="post" action="<?php echo $panelUrl; ?>" class="oct-generate-bar">
                        <input type="hidden" name="_" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="do" value="generate">
                        <select name="uid" required>
                            <option value=""><?php _e('— 选择 Agent 对应的用户账户 —'); ?></option>
                            <?php foreach ($users as $user): ?>
                            <?php $hasToken = in_array(intval($user['uid']), $tokenUserIds, true); ?>
                            <option value="<?php echo intval($user['uid']); ?>" <?php if ($hasToken) echo 'disabled'; ?>>
                                <?php
                                    echo htmlspecialchars(($user['screenName'] ?: $user['name'])
                                        . ' (' . ($groupLabels[$user['group']] ?? $user['group']) . ')'
                                        . ($hasToken ? ' · ' . _t('已有 Token') : ''));
                                ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn primary"><?php _e('生成 Token'); ?></button>
                        <span class="oct-hint"><?php _e('每个用户账户对应一个 AI Agent，Token 只显示一次'); ?></span>
                    </form>
                </div>

                <!-- Token 列表 -->
                <div class="oct-section">
                    <h3 class="oct-section-title"><?php _e('已签发的 Token'); ?></h3>

                    <?php if (empty($tokens)): ?>
                    <div class="oct-empty">
                        <strong><?php _e('还没有任何 Token。'); ?></strong>
                        <ol>
                            <li><?php _e('在「用户 → 新增用户」中为 AI Agent 创建一个账户，用户组建议设为「贡献者」'); ?></li>
                            <li><?php _e('回到本页，在上方选择该账户并点击「生成 Token」'); ?></li>
                            <li><?php _e('复制生成的 Token，连同博客地址一起提供给对应的 AI Agent'); ?></li>
                        </ol>
                    </div>
                    <?php else: ?>
                    <table class="typecho-list-table oct-table">
                        <colgroup>
                            <col width="16%">
                            <col width="24%">
                            <col width="10%">
                            <col width="15%">
                            <col width="13%">
                            <col width="22%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th><?php _e('Agent 用户'); ?></th>
                                <th><?php _e('Token'); ?></th>
                                <th><?php _e('状态'); ?></th>
                                <th><?php _e('最近使用'); ?></th>
                                <th><?php _e('创建时间'); ?></th>
                                <th><?php _e('操作'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tokens as $t): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($t['screenName'] ?: $t['name']); ?></strong>
                                    <br><small style="color:#999;"><?php echo htmlspecialchars($groupLabels[$t['group']] ?? $t['group']); ?> · UID <?php echo intval($t['author_uid']); ?></small>
                                </td>
                                <td>
                                    <code class="oct-token-code"><?php echo htmlspecialchars($t['token_prefix']); ?>&hellip;<?php echo htmlspecialchars($t['token_suffix']); ?></code>
                                </td>
                                <td>
                                    <?php if ($t['status'] === 'active'): ?>
                                    <span class="oct-status oct-status-active"><?php _e('启用中'); ?></span>
                                    <?php else: ?>
                                    <span class="oct-status oct-status-revoked"><?php _e('已吊销'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="oct-time">
                                    <?php echo octRelativeTime(intval($t['last_used_at'])); ?>
                                    <?php if ($t['last_used_at'] > 0): ?>
                                    <small><?php echo date('Y-m-d H:i:s', intval($t['last_used_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="oct-time"><?php echo date('Y-m-d H:i', intval($t['created_at'])); ?></td>
                                <td class="oct-ops">
                                    <form method="post" action="<?php echo $panelUrl; ?>" onsubmit="return confirm('<?php _e('重置后旧 Token 立即失效，确定重置？'); ?>');">
                                        <input type="hidden" name="_" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="do" value="regenerate">
                                        <input type="hidden" name="id" value="<?php echo intval($t['id']); ?>">
                                        <button type="submit" class="btn btn-s"><?php _e('重置'); ?></button>
                                    </form>
                                    <?php if ($t['status'] === 'active'): ?>
                                    <form method="post" action="<?php echo $panelUrl; ?>" onsubmit="return confirm('<?php _e('吊销后该 Agent 将立即无法调用 API，确定吊销？'); ?>');">
                                        <input type="hidden" name="_" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="do" value="revoke">
                                        <input type="hidden" name="id" value="<?php echo intval($t['id']); ?>">
                                        <button type="submit" class="btn btn-s warn"><?php _e('吊销'); ?></button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" action="<?php echo $panelUrl; ?>">
                                        <input type="hidden" name="_" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="do" value="activate">
                                        <input type="hidden" name="id" value="<?php echo intval($t['id']); ?>">
                                        <button type="submit" class="btn btn-s"><?php _e('恢复'); ?></button>
                                    </form>
                                    <form method="post" action="<?php echo $panelUrl; ?>" onsubmit="return confirm('<?php _e('删除后该 Token 记录将移除（不影响已发布的文章），确定删除？'); ?>');">
                                        <input type="hidden" name="_" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="do" value="delete">
                                        <input type="hidden" name="id" value="<?php echo intval($t['id']); ?>">
                                        <button type="submit" class="btn btn-s warn"><?php _e('删除'); ?></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- 使用说明 -->
                <div class="oct-section">
                    <h3 class="oct-section-title"><?php _e('接入说明'); ?></h3>
                    <div class="oct-empty" style="padding:16px 20px;">
                        <?php _e('AI Agent 调用 API 时在请求头中携带对应用户的 Token：'); ?>
                        <br><code class="oct-token-code">Authorization: Bearer &lt;token&gt;</code>
                        <br><br>
                        <?php _e('每个 Token 发布的文章归属于其绑定的用户账户；Agent 只能更新、删除自己账户名下的文章，分类只能从现有分类中选择。'); ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php if ($flashToken): ?>
<div class="oct-modal-mask" id="octModalMask">
    <div class="oct-modal">
        <div class="oct-modal-head"><?php _e('Token 已生成'); ?></div>
        <div class="oct-modal-body">
            <p><?php _e('这是该 Token 唯一一次完整显示，请立即复制并妥善保存：'); ?></p>
            <div class="oct-token-reveal">
                <input type="text" id="octTokenInput" readonly value="<?php echo htmlspecialchars($flashToken); ?>" onclick="this.select();">
                <button type="button" class="btn primary" id="octCopyBtn"><?php _e('复制'); ?></button>
            </div>
            <p class="oct-modal-warn"><?php _e('关闭本窗口后将无法再次查看完整 Token，遗失只能重置。'); ?></p>
        </div>
        <div class="oct-modal-foot">
            <button type="button" class="btn" id="octCloseBtn"><?php _e('我已保存，关闭'); ?></button>
        </div>
    </div>
</div>
<script>
(function () {
    var input = document.getElementById('octTokenInput');
    var copyBtn = document.getElementById('octCopyBtn');
    var closeBtn = document.getElementById('octCloseBtn');
    var mask = document.getElementById('octModalMask');

    function copyToken() {
        var done = function () {
            copyBtn.innerHTML = '<?php _e('已复制'); ?>';
            setTimeout(function () { copyBtn.innerHTML = '<?php _e('复制'); ?>'; }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done, function () { fallbackCopy(); done(); });
        } else {
            fallbackCopy();
            done();
        }
    }

    function fallbackCopy() {
        input.focus();
        input.select();
        try { document.execCommand('copy'); } catch (e) {}
    }

    copyBtn.addEventListener('click', copyToken);
    closeBtn.addEventListener('click', function () { mask.parentNode.removeChild(mask); });
    input.focus();
    input.select();
})();
</script>
<?php endif; ?>

<?php
include 'copyright.php';
include 'common-js.php';
include 'footer.php';
?>
