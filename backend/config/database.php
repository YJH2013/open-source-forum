<?php
/**
 * 数据库配置 & PDO 连接
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'forum');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET NAMES utf8mb4");
    }
    return $pdo;
}

/**
 * 首次运行时初始化数据库（创建表 + 默认数据）
 */
function initDatabase(): void {
    $pdo = getDB();
    // 检查表是否存在
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) return; // 已初始化

    $sql = file_get_contents(__DIR__ . '/../../database/schema.sql');
    // 替换数据库名占位符
    $sql = str_replace('{DB_NAME}', DB_NAME, $sql);
    // 移除 CREATE DATABASE / USE 语句（数据库应已存在）
    $sql = preg_replace('/CREATE DATABASE.*?;/si', '', $sql);
    $sql = preg_replace('/USE.*?;/si', '', $sql);
    // 按 ; 后跟换行符拆分，逐条执行
    $statements = preg_split('/;\s*\n/', $sql);
    $statements = array_filter(array_map('trim', $statements), fn($s) => !empty($s) && !str_starts_with($s, '--'));
    foreach ($statements as $stmt) {
        try { $pdo->exec($stmt); } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists')) throw $e;
        }
    }

    // 插入管理员（密码: admin123）
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetch()['cnt'] == 0) {
        $pdo->prepare("INSERT INTO users (username, email, password, role, signature) VALUES (?, ?, ?, ?, ?)")
            ->execute(['admin', 'admin@forum.com', $hash, 'admin', '论坛管理员']);
    }
}
