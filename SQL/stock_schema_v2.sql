USE `login_system`;

-- Add approval columns to stock_movements
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'approval_status'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'approved_by'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `approved_by` INT UNSIGNED NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'approved_at'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `approved_at` DATETIME NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'rejected_reason'
);
SET @sql = IF(@col_exists = 0, "ALTER TABLE `stock_movements` ADD COLUMN `rejected_reason` VARCHAR(255) NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND INDEX_NAME = 'idx_stock_movements_approval_status'
);
SET @sql = IF(@idx_exists = 0, "ALTER TABLE `stock_movements` ADD INDEX `idx_stock_movements_approval_status` (`approval_status`)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND INDEX_NAME = 'idx_stock_movements_approved_by'
);
SET @sql = IF(@idx_exists = 0, "ALTER TABLE `stock_movements` ADD INDEX `idx_stock_movements_approved_by` (`approved_by`)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND CONSTRAINT_NAME = 'fk_stock_movements_approved_by'
);
SET @sql = IF(@fk_exists = 0, "ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed new permission movement.approve
INSERT INTO permissions (perm_key, description)
SELECT 'movement.approve', 'Approve/reject stock movements'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'movement.approve');

-- Assign movement.approve to head_admin and limited_admin
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.perm_key = 'movement.approve'
WHERE r.name IN ('head_admin', 'limited_admin')
ON DUPLICATE KEY UPDATE permission_id = permission_id;
