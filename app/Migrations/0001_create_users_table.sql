-- Each account has three separate identifiers: a private login name used only to sign in,
-- a public display name, and a public slug used in URLs and feeds. The login name is never
-- shown publicly. That login name differs from the display name and slug is enforced by the
-- application, because older MySQL and MariaDB versions cannot express it as a CHECK constraint.
CREATE TABLE {prefix}users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login_name VARCHAR(60) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'reader',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_login_name (login_name),
    UNIQUE KEY uniq_slug (slug),
    UNIQUE KEY uniq_email (email),
    KEY idx_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
