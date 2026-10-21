-- What the protections blocked: locked sign-ins, spam, rejected registrations. Kept apart from the audit log, which
-- records what administrators did, because this one grows with what attackers do and is trimmed by age. The detail is a
-- short note and never holds anything a visitor typed.
CREATE TABLE {prefix}security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(40) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    detail VARCHAR(200) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    KEY idx_created_at (created_at),
    KEY idx_event_created (event, created_at),
    KEY idx_ip_event_created (ip_address, event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
