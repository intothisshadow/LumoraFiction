-- Failed sign-in attempts per address, used only to decide lockouts. The name that was typed is
-- deliberately not stored: people sometimes type their password into the name field.
CREATE TABLE {prefix}login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_ip_attempted (ip_address, attempted_at),
    KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
