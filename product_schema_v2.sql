USE `login_system`;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'image_path'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `products` ADD COLUMN `image_path` VARCHAR(255) NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'archived_at'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `products` ADD COLUMN `archived_at` DATETIME NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_archived_at'
);
SET @sql = IF(@idx_exists = 0, "ALTER TABLE `products` ADD INDEX `idx_products_archived_at` (`archived_at`)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
