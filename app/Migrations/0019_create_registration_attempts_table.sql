-- Every submission of the registration form, valid or not, per address. The rate limits and the flood pause count
-- these rows, and old rows are removed as new ones arrive. The details of what was typed are deliberately not kept.
CREATE TABLE {prefix}registration_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_ip_attempted (ip_address, attempted_at),
    KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
