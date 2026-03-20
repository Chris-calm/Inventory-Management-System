USE `login_system`;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'status'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `categories` ADD COLUMN `status` ENUM('active','inactive') NOT NULL DEFAULT 'active'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'notes'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `categories` ADD COLUMN `notes` VARCHAR(255) NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
