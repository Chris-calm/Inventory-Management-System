USE `login_system`;

-- Create locations table
SET @table_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations'
);
SET @sql = IF(@table_exists = 0,
  "CREATE TABLE `locations` (
     `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
     `name` VARCHAR(120) NOT NULL,
     `code` VARCHAR(50) NULL,
     `notes` VARCHAR(255) NULL,
     `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
     `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     UNIQUE KEY `uk_locations_name` (`name`),
     UNIQUE KEY `uk_locations_code` (`code`),
     KEY `idx_locations_status` (`status`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed permissions for locations
INSERT INTO permissions (perm_key, description)
SELECT 'location.view', 'View locations'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'location.view');

INSERT INTO permissions (perm_key, description)
SELECT 'location.create', 'Create locations'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'location.create');

INSERT INTO permissions (perm_key, description)
SELECT 'location.edit', 'Edit locations'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'location.edit');

INSERT INTO permissions (perm_key, description)
SELECT 'location.delete', 'Delete locations'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'location.delete');

-- Assign to roles
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.perm_key IN ('location.view','location.create','location.edit','location.delete')
WHERE r.name = 'head_admin'
ON DUPLICATE KEY UPDATE permission_id = permission_id;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.perm_key IN ('location.view','location.create','location.edit')
WHERE r.name = 'limited_admin'
ON DUPLICATE KEY UPDATE permission_id = permission_id;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.perm_key IN ('location.view')
WHERE r.name = 'staff'
ON DUPLICATE KEY UPDATE permission_id = permission_id;
