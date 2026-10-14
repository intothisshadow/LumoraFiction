-- Single-use tokens for emailed links (email verification, password reset). The link carries a
-- selector and a secret; only a hash of the secret is stored, so a leaked table cannot be used
-- to take over an account. A user has at most one live token per purpose.
CREATE TABLE {prefix}user_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(30) NOT NULL,
    selector VARCHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_selector (selector),
    UNIQUE KEY uniq_user_purpose (user_id, purpose),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
