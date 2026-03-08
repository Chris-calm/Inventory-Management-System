USE `login_system`;

-- Expand movement_type enum to include transfer
SET @sql = "ALTER TABLE `stock_movements` MODIFY COLUMN `movement_type` ENUM('in','out','adjust','transfer') NOT NULL";
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create per-location stock table
SET @table_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'location_stocks'
);
SET @sql = IF(@table_exists = 0,
  "CREATE TABLE `location_stocks` (
     `location_id` INT UNSIGNED NOT NULL,
     `product_id` INT UNSIGNED NOT NULL,
     `qty` INT NOT NULL DEFAULT 0,
     `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`location_id`, `product_id`),
     KEY `idx_location_stocks_product` (`product_id`),
     CONSTRAINT `fk_location_stocks_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
     CONSTRAINT `fk_location_stocks_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add transfer columns
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'source_type'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `source_type` ENUM('location','supplier') NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'source_location_id'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `source_location_id` INT UNSIGNED NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'source_name'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `source_name` VARCHAR(120) NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'dest_type'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `dest_type` ENUM('location','customer') NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'dest_location_id'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `dest_location_id` INT UNSIGNED NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'dest_name'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `dest_name` VARCHAR(120) NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND INDEX_NAME = 'idx_stock_movements_source_location'
);
SET @sql = IF(@idx_exists = 0, "ALTER TABLE `stock_movements` ADD INDEX `idx_stock_movements_source_location` (`source_location_id`)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND INDEX_NAME = 'idx_stock_movements_dest_location'
);
SET @sql = IF(@idx_exists = 0, "ALTER TABLE `stock_movements` ADD INDEX `idx_stock_movements_dest_location` (`dest_location_id`)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Foreign keys to locations (requires locations table)
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND CONSTRAINT_NAME = 'fk_stock_movements_source_location'
);
SET @sql = IF(@fk_exists = 0, "ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_source_location` FOREIGN KEY (`source_location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND CONSTRAINT_NAME = 'fk_stock_movements_dest_location'
);
SET @sql = IF(@fk_exists = 0, "ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_dest_location` FOREIGN KEY (`dest_location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
