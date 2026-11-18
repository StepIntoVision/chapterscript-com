-- Import once into the empty ChapterScript database using phpMyAdmin.
CREATE TABLE IF NOT EXISTS early_access_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name VARCHAR(150) NOT NULL,
    organization VARCHAR(200) NOT NULL DEFAULT '',
    website VARCHAR(500) NOT NULL DEFAULT '',
    role VARCHAR(80) NOT NULL DEFAULT '',
    vision_pro VARCHAR(40) NOT NULL DEFAULT '',
    mac VARCHAR(40) NOT NULL DEFAULT '',
    shipped VARCHAR(40) NOT NULL DEFAULT '',
    tools JSON NOT NULL,
    making JSON NOT NULL,
    story TEXT NOT NULL,
    timeline VARCHAR(40) NOT NULL DEFAULT '',
    heard_about VARCHAR(300) NOT NULL DEFAULT '',
    updates TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY applications_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS early_access_rate_limits (
    ip_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_seconds INT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (ip_key, window_seconds),
    KEY rate_limit_expiry (expires_at)
) ENGINE=InnoDB;
