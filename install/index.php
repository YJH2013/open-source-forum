<?php
/**
 * 论坛安装程序
 *
 * 使用方式：
 *   开发: php -S localhost:8080 -t install/
 *   生产: 访问 http://你的域名/install/
 *
 * 安装完成后会自动生成锁定文件，防止重复安装
 */

// 检查是否已安装
$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    die('✅ 论坛已安装。如需重新安装，请删除 install/install.lock 文件后刷新本页面。<br><br>
    <a href="../">🏠 返回首页</a>');
}

$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$error = '';
$success = '';

// 统一开启 session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==================== 步骤1: 环境检查 ====================
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = 2;
}

// ==================== 步骤2: 数据库配置 ====================
elseif ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? 'forum');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = trim($_POST['db_pass'] ?? '');

    try {
        $dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // 创建数据库（如果不存在）
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");

        // 保存数据库信息到 session
        $_SESSION['install_db'] = compact('dbHost', 'dbPort', 'dbName', 'dbUser', 'dbPass');
        $_SESSION['install_pdo_ok'] = true;

        $step = 3;
    } catch (PDOException $e) {
        $error = '数据库连接失败：' . $e->getMessage();
    }
}

// ==================== 步骤3: 完成安装 ====================
elseif ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['install_db'])) {
        $error = '会话已过期，请重新开始安装。';
        $step = 1;
    } else {
        $db = $_SESSION['install_db'];
        $siteName = trim($_POST['site_name'] ?? '我的论坛');
        $adminUser = trim($_POST['admin_user'] ?? 'admin');
        $adminPass = trim($_POST['admin_pass'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? 'admin@forum.com');

        if (strlen($adminPass) < 6) {
            $error = '管理员密码至少6位';
        } else {
            try {
                $dsn = "mysql:host={$db['dbHost']};port={$db['dbPort']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['dbUser'], $db['dbPass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec("USE `{$db['dbName']}`");

                // 执行建表 SQL
                $schemaFile = __DIR__ . '/../database/schema.sql';
                if (file_exists($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    // 替换数据库名占位符
                    $sql = str_replace('{DB_NAME}', $db['dbName'], $sql);
                    // 移除 CREATE DATABASE / USE 语句
                    $sql = preg_replace('/CREATE DATABASE.*?;/si', '', $sql);
                    $sql = preg_replace('/USE.*?;/si', '', $sql);
                    // 按 ; 后跟换行符拆分，更可靠
                    $statements = preg_split('/;\s*\n/', $sql);
                    $statements = array_filter(array_map('trim', $statements), fn($s) => !empty($s) && !str_starts_with($s, '--'));
                    foreach ($statements as $stmt) {
                        try { $pdo->exec($stmt); } catch (PDOException $e) {
                            // 忽略 "already exists" 类错误
                            if (!str_contains($e->getMessage(), 'already exists')) {
                                throw $e;
                            }
                        }
                    }
                }

                // 插入或更新管理员（处理邮箱冲突）
                $hash = password_hash($adminPass, PASSWORD_BCRYPT);
                // 先按用户名查
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$adminUser]);
                $byName = $stmt->fetch();
                // 再按邮箱查
                $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
                $stmt->execute([$adminEmail]);
                $byEmail = $stmt->fetch();

                if ($byName) {
                    // 用户名已存在，直接更新密码和角色
                    $pdo->prepare("UPDATE users SET password = ?, role = 'admin', email = ? WHERE username = ?")
                        ->execute([$hash, $adminEmail, $adminUser]);
                } elseif ($byEmail) {
                    // 邮箱已被占用，更新那个用户为管理员
                    $pdo->prepare("UPDATE users SET username = ?, password = ?, role = 'admin' WHERE email = ?")
                        ->execute([$adminUser, $hash, $adminEmail]);
                } else {
                    $pdo->prepare("INSERT INTO users (username, email, password, role, signature) VALUES (?, ?, ?, 'admin', '论坛管理员')")
                        ->execute([$adminUser, $adminEmail, $hash]);
                }

                // 更新站点名称
                $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('site_name', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                    ->execute([$siteName]);

                // 写入数据库配置文件
                $configFile = __DIR__ . '/../backend/config/database.php';
                $configContent = "<?php\n/**\n * 数据库配置 & PDO 连接\n * 由安装程序自动生成\n */\n\n"
                    . "define('DB_HOST', " . var_export($db['dbHost'], true) . ");\n"
                    . "define('DB_PORT', " . var_export($db['dbPort'], true) . ");\n"
                    . "define('DB_NAME', " . var_export($db['dbName'], true) . ");\n"
                    . "define('DB_USER', " . var_export($db['dbUser'], true) . ");\n"
                    . "define('DB_PASS', " . var_export($db['dbPass'], true) . ");\n"
                    . "define('DB_CHARSET', 'utf8mb4');\n\n"
                    . "function getDB(): PDO {\n"
                    . "    static \$pdo = null;\n"
                    . "    if (\$pdo === null) {\n"
                    . "        \$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);\n"
                    . "        \$options = [\n"
                    . "            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n"
                    . "            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
                    . "            PDO::ATTR_EMULATE_PREPARES   => false,\n"
                    . "        ];\n"
                    . "        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, \$options);\n"
                    . "        \$pdo->exec(\"SET NAMES utf8mb4\");\n"
                    . "    }\n"
                    . "    return \$pdo;\n"
                    . "}\n\n"
                    . "function initDatabase(): void {\n"
                    . "    // 数据库表已在安装程序中创建，这里仅做连接测试\n"
                    . "    \$pdo = getDB();\n"
                    . "    \$stmt = \$pdo->query(\"SHOW TABLES LIKE 'users'\");\n"
                    . "    if (\$stmt->rowCount() === 0) {\n"
                    . "        // 如果表不存在，尝试重新创建\n"
                    . "        \$sql = file_get_contents(__DIR__ . '/../../database/schema.sql');\n"
                    . "        \$sql = preg_replace('/CREATE DATABASE.*?;/si', '', \$sql);\n"
                    . "        \$sql = preg_replace('/USE.*?;/si', '', \$sql);\n"
                    . "        \$pdo->exec(\$sql);\n"
                    . "    }\n"
                    . "}\n";

                file_put_contents($configFile, $configContent);

                // 写入锁定文件
                file_put_contents($lockFile, date('Y-m-d H:i:s'));

                $success = '安装完成！';
                session_destroy();

            } catch (Exception $e) {
                $error = '安装失败：' . $e->getMessage();
            }
        }
    }
}

// ==================== HTML 输出 ====================
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>论坛安装程序</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .installer {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 560px;
            overflow: hidden;
        }
        .installer-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #fff;
            padding: 30px 36px;
            text-align: center;
        }
        .installer-header h1 { font-size: 24px; margin-bottom: 6px; }
        .installer-header p { opacity: 0.85; font-size: 14px; }
        .installer-body { padding: 30px 36px; }
        .steps {
            display: flex;
            justify-content: center;
            gap: 0;
            margin-bottom: 28px;
        }
        .step-dot {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700;
            background: #e5e7eb; color: #9ca3af;
            position: relative;
            z-index: 1;
        }
        .step-dot.active { background: #4f46e5; color: #fff; }
        .step-dot.done { background: #10b981; color: #fff; }
        .step-line {
            flex: 1; max-width: 60px;
            height: 3px; background: #e5e7eb;
            align-self: center; margin: 0 -4px;
        }
        .step-line.done { background: #10b981; }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-size: 13px; font-weight: 600;
            color: #374151; margin-bottom: 5px;
        }
        .form-group label .hint { font-weight: 400; color: #9ca3af; }
        .form-group input {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 8px; font-size: 14px;
            transition: border-color 0.2s;
            outline: none;
        }
        .form-group input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }

        .btn {
            display: inline-block; width: 100%;
            padding: 12px 24px; border: none; border-radius: 8px;
            font-size: 15px; font-weight: 600; cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .alert-info { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }

        .env-check { font-size: 13px; }
        .env-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .env-ok { color: #10b981; }
        .env-fail { color: #dc2626; }

        .done-box { text-align: center; padding: 20px 0; }
        .done-box .icon { font-size: 64px; margin-bottom: 16px; }
        .done-box h2 { font-size: 22px; color: #10b981; margin-bottom: 8px; }
        .done-box .links { margin-top: 20px; display: flex; gap: 12px; justify-content: center; }
        .done-box .links a {
            padding: 10px 24px; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 14px;
        }
        .link-primary { background: #4f46e5; color: #fff; }
        .link-primary:hover { background: #4338ca; }
        .link-outline { border: 2px solid #4f46e5; color: #4f46e5; }
        .link-outline:hover { background: #f5f3ff; }
    </style>
</head>
<body>
<div class="installer">
    <div class="installer-header">
        <h1>🛠️ 论坛安装程序</h1>
        <p>快速部署您的论坛</p>
    </div>
    <div class="installer-body">
        <!-- 步骤条 -->
        <div class="steps">
            <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">1</div>
            <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">2</div>
            <div class="step-line <?= $step > 2 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 3 ? ($step > 3 ? 'done' : 'active') : '' ?>">3</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="done-box">
                <div class="icon">✅</div>
                <h2>安装成功！</h2>
                <p style="color:#6b7280;margin-bottom:4px">论坛已就绪，可以开始使用了</p>
                <div class="links">
                    <a href="../" class="link-primary">🏠 进入论坛</a>
                    <a href="../backend/" class="link-outline">🔧 API 入口</a>
                </div>
            </div>
        <?php elseif ($step === 1): ?>
            <!-- 步骤1: 环境检查 -->
            <form method="post">
                <h3 style="margin-bottom:12px">📋 环境检查</h3>
                <div class="env-check">
                    <?php
                    $checks = [
                        'PHP 版本 ≥ 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
                        'PDO 扩展' => extension_loaded('pdo'),
                        'PDO MySQL 驱动' => extension_loaded('pdo_mysql'),
                        'JSON 扩展' => extension_loaded('json'),
                        'Fileinfo 扩展' => extension_loaded('fileinfo'),
                        'OpenSSL 扩展（用于JWT）' => extension_loaded('openssl'),
                    ];
                    $allOk = true;
                    foreach ($checks as $label => $ok):
                        if (!$ok) $allOk = false;
                    ?>
                    <div class="env-item">
                        <span><?= $label ?></span>
                        <span class="<?= $ok ? 'env-ok' : 'env-fail' ?>"><?= $ok ? '✅ 通过' : '❌ 未通过' ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="env-item">
                        <span>当前 PHP 版本</span>
                        <span><?= PHP_VERSION ?></span>
                    </div>
                </div>
                <br>
                <?php if ($allOk): ?>
                    <div class="alert alert-success">✅ 环境检查全部通过，可以继续安装。</div>
                    <input type="hidden" name="step" value="1">
                    <button type="submit" class="btn btn-primary">下一步 → 数据库配置</button>
                <?php else: ?>
                    <div class="alert alert-error">❌ 部分环境检查未通过，请先配置好 PHP 环境。</div>
                <?php endif; ?>
            </form>

        <?php elseif ($step === 2): ?>
            <!-- 步骤2: 数据库配置 -->
            <form method="post">
                <h3 style="margin-bottom:16px">🗄️ 数据库配置</h3>
                <div class="form-group">
                    <label>数据库主机 <span class="hint">（通常 127.0.0.1）</span></label>
                    <input type="text" name="db_host" value="127.0.0.1" required>
                </div>
                <div class="form-group">
                    <label>端口</label>
                    <input type="text" name="db_port" value="3306" required>
                </div>
                <div class="form-group">
                    <label>数据库名</label>
                    <input type="text" name="db_name" value="forum" required>
                </div>
                <div class="form-group">
                    <label>数据库用户名</label>
                    <input type="text" name="db_user" value="root" required>
                </div>
                <div class="form-group">
                    <label>数据库密码</label>
                    <input type="password" name="db_pass" placeholder="留空则不设密码">
                </div>
                <input type="hidden" name="step" value="2">
                <button type="submit" class="btn btn-primary">测试连接 & 下一步 →</button>
                <button type="button" class="btn" style="background:#e5e7eb;color:#374151;margin-top:8px" onclick="window.location='?step=1'">← 返回上一步</button>
            </form>

        <?php elseif ($step === 3): ?>
            <!-- 步骤3: 站点设置 -->
            <form method="post">
                <h3 style="margin-bottom:16px">⚙️ 站点 & 管理员设置</h3>
                <div class="form-group">
                    <label>🏷️ 论坛名称</label>
                    <input type="text" name="site_name" value="我的论坛" required placeholder="起个好名字吧">
                </div>
                <hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">
                <h4 style="margin-bottom:12px;color:#4f46e5">👤 管理员账号</h4>
                <div class="form-group">
                    <label>管理员用户名</label>
                    <input type="text" name="admin_user" value="admin" required>
                </div>
                <div class="form-group">
                    <label>管理员邮箱</label>
                    <input type="email" name="admin_email" value="admin@forum.com" required>
                </div>
                <div class="form-group">
                    <label>管理员密码 <span class="hint">（至少6位，请牢记）</span></label>
                    <input type="password" name="admin_pass" placeholder="至少6位密码" required minlength="6">
                </div>
                <input type="hidden" name="step" value="3">
                <button type="submit" class="btn btn-success">🎉 开始安装</button>
                <button type="button" class="btn" style="background:#e5e7eb;color:#374151;margin-top:8px" onclick="window.location='?step=2'">← 返回上一步</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
