<?php
/**
 * GET  /api/admin/users?search=xxx    — 用户列表
 * POST /api/admin/users               — 调整余额 { user_id, amount, remark }
 *       amount 正数=加余额，负数=减余额
 */

$auth = requireAuth();
if ($auth['role'] !== 'admin') error('需要管理员权限', 403);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = trim($_GET['search'] ?? '');
    if ($search) {
        $stmt = $pdo->prepare('SELECT id, username, email, role, balance FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 50');
        $like = "%$search%";
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $pdo->query('SELECT id, username, email, role, balance FROM users ORDER BY id DESC LIMIT 50');
    }
    $list = $stmt->fetchAll();
    success($list);
}

elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['user_id'] ?? 0);
    $amount = floatval($data['amount'] ?? 0);
    $remark = trim($data['remark'] ?? '');

    if ($userId <= 0) error('请选择用户');
    if ($amount == 0) error('金额不能为0');

    $stmt = $pdo->prepare('SELECT id, username, balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) error('用户不存在');

    $newBalance = round($user['balance'] + $amount, 2);
    if ($newBalance < 0) error('余额不足，无法减少到负数。当前余额：' . $user['balance']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET balance = ? WHERE id = ?')->execute([$newBalance, $userId]);
        $type = $amount > 0 ? 'income' : 'deduct';
        $pdo->prepare('INSERT INTO balance_logs (user_id, amount, type, remark) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $amount, $type, $remark ?: ($amount > 0 ? '管理员增加余额' : '管理员减少余额')]);
        $pdo->commit();
        success(['username' => $user['username'], 'old_balance' => $user['balance'], 'new_balance' => $newBalance, 'change' => $amount], '操作成功');
    } catch (Exception $e) {
        $pdo->rollBack();
        error('操作失败: ' . $e->getMessage(), 500);
    }
}
