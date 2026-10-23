-- Alerts about the site itself that are worth keeping: an event happened, rather than a state that can be checked again.
-- One row per alert key, so a repeating problem is one row with a count and a last-seen time. A dismissed alert comes
-- back when the problem returns after a quiet period. Old rows are removed by age and by a cap on the number of rows.
-- Messages are written by code and never hold anything a visitor typed.
CREATE TABLE {prefix}site_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_key VARCHAR(100) NOT NULL,
    severity VARCHAR(10) NOT NULL,
    message VARCHAR(500) NOT NULL,
    detail VARCHAR(1000) NOT NULL DEFAULT '',
    path VARCHAR(100) NOT NULL DEFAULT '',
    occurrences INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    dismissed_at DATETIME NULL,
    emailed_at DATETIME NULL,
    UNIQUE KEY uniq_alert_key (alert_key),
    KEY idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
