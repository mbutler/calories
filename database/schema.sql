CREATE TABLE IF NOT EXISTS foods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(32) NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    basis_amount DECIMAL(12,3) NOT NULL,
    basis_unit ENUM('g', 'ml', 'serving') NOT NULL,
    calories DECIMAL(12,3) NOT NULL,
    protein DECIMAL(12,3) NOT NULL,
    carbs DECIMAL(12,3) NOT NULL,
    fat DECIMAL(12,3) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    source_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (basis_amount > 0),
    CHECK (calories >= 0 AND protein >= 0 AND carbs >= 0 AND fat >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nutrition and basis fields are copied from foods when an entry is logged.
-- They remain independent of subsequent food edits.
CREATE TABLE IF NOT EXISTS log_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    food_id BIGINT UNSIGNED NOT NULL,
    logged_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    food_name VARCHAR(160) NOT NULL,
    basis_amount DECIMAL(12,3) NOT NULL,
    basis_unit ENUM('g', 'ml', 'serving') NOT NULL,
    calories DECIMAL(12,3) NOT NULL,
    protein DECIMAL(12,3) NOT NULL,
    carbs DECIMAL(12,3) NOT NULL,
    fat DECIMAL(12,3) NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    request_key CHAR(36) NOT NULL UNIQUE,
    FOREIGN KEY (food_id) REFERENCES foods(id),
    INDEX log_day (logged_date, id),
    CHECK (quantity > 0 AND basis_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meal_id BIGINT UNSIGNED NOT NULL,
    food_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE,
    FOREIGN KEY (food_id) REFERENCES foods(id),
    CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS targets (
    id TINYINT UNSIGNED PRIMARY KEY,
    calories DECIMAL(12,3) NOT NULL DEFAULT 0,
    protein DECIMAL(12,3) NOT NULL DEFAULT 0,
    carbs DECIMAL(12,3) NOT NULL DEFAULT 0,
    fat DECIMAL(12,3) NOT NULL DEFAULT 0,
    CHECK (id = 1),
    CHECK (calories >= 0 AND protein >= 0 AND carbs >= 0 AND fat >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO targets (id) VALUES (1);
