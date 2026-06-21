-- ==========================================
-- 论坛数据库建表脚本 (MySQL)
-- ==========================================

CREATE DATABASE IF NOT EXISTS `{DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `{DB_NAME}`;

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `avatar` VARCHAR(255) DEFAULT '',
  `role` ENUM('user', 'admin') DEFAULT 'user',
  `signature` VARCHAR(255) DEFAULT '',
  `balance` DECIMAL(10,2) DEFAULT 0.00,
  `last_active` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 版块表
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT '',
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `icon` VARCHAR(10) DEFAULT '📋',
  `sort_order` INT DEFAULT 0,
  `post_count` INT DEFAULT 0,
  `only_admin` TINYINT(1) DEFAULT 0,
  `parent_id` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_parent` (`parent_id`),
  FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 帖子表
CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `content` TEXT NOT NULL,
  `user_id` INT NOT NULL,
  `category_id` INT NOT NULL,
  `price` DECIMAL(10,2) DEFAULT 0.00,
  `pay_mode` VARCHAR(10) DEFAULT 'full' COMMENT 'full=全部付费, partial=部分付费',
  `is_pinned` TINYINT(1) DEFAULT 0,
  `is_locked` TINYINT(1) DEFAULT 0,
  `view_count` INT DEFAULT 0,
  `reply_count` INT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category` (`category_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_created` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 回复表
CREATE TABLE IF NOT EXISTS `replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `content` TEXT NOT NULL,
  `user_id` INT NOT NULL,
  `post_id` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_post` (`post_id`),
  INDEX `idx_user_reply` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 文件表
CREATE TABLE IF NOT EXISTS `files` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` BIGINT DEFAULT 0,
  `mime_type` VARCHAR(100) DEFAULT 'application/octet-stream',
  `post_id` INT DEFAULT NULL,
  `reply_id` INT DEFAULT NULL,
  `user_id` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_file_post` (`post_id`),
  INDEX `idx_file_reply` (`reply_id`),
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reply_id`) REFERENCES `replies`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 系统设置表
CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 公告表
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `user_id` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 订单表
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_no` VARCHAR(64) NOT NULL UNIQUE,
  `trade_no` VARCHAR(64) DEFAULT '',
  `user_id` INT NOT NULL,
  `post_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `status` TINYINT(1) DEFAULT 0 COMMENT '0=未支付, 1=已支付',
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_order_user` (`user_id`),
  INDEX `idx_order_post` (`post_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 购买记录表
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `post_id` INT NOT NULL,
  `order_id` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_post` (`user_id`, `post_id`),
  INDEX `idx_purchase_order` (`order_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 余额变动日志表
CREATE TABLE IF NOT EXISTS `balance_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `type` ENUM('income', 'deduct') DEFAULT 'income',
  `ref_id` INT DEFAULT 0,
  `remark` VARCHAR(255) DEFAULT '',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_balance_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 邮箱验证码表
CREATE TABLE IF NOT EXISTS `email_codes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(100) NOT NULL,
  `code` VARCHAR(6) NOT NULL,
  `ip` VARCHAR(45) DEFAULT '',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_email_created` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 第三方登录绑定表
CREATE TABLE IF NOT EXISTS `user_oauths` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `provider` VARCHAR(20) NOT NULL COMMENT 'qq, alipay, wechat',
  `openid` VARCHAR(128) NOT NULL COMMENT 'social_uid',
  `nickname` VARCHAR(100) DEFAULT '',
  `avatar` VARCHAR(500) DEFAULT '',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_provider_openid` (`provider`, `openid`),
  INDEX `idx_oauth_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 签到表
CREATE TABLE IF NOT EXISTS `checkins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `check_date` DATE NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_date` (`user_id`, `check_date`),
  INDEX `idx_check_date` (`check_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 默认版块
INSERT INTO `categories` (`name`, `description`, `slug`, `icon`, `sort_order`) VALUES
('公告区', '论坛公告、规则发布', 'announcement', '📢', 1),
('综合讨论', '各类话题自由讨论', 'general', '💬', 2),
('技术交流', '编程、技术问题交流', 'tech', '💻', 3),
('资源分享', '学习资料、工具资源分享', 'resources', '📦', 4);

-- 默认站点设置
INSERT INTO `settings` (`key`, `value`) VALUES
('site_name', '论坛'),
('max_upload_mb', '100'),
('max_price', '50'),
('email_verify_enabled', '0'),
('smtp_host', ''),
('smtp_port', '465'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_from_email', ''),
('smtp_from_name', ''),
('oauth_enabled', '0'),
('oauth_appid', ''),
('oauth_appkey', ''),
('forum_declaration', ''),
('forum_ad', ''),
('forum_post_footer', ''),
('forum_intro', '');
