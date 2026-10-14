-- Values are stored as JSON so booleans, numbers, and lists keep their type and are never unserialized.
CREATE TABLE {prefix}settings (
    setting_name VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
