-- Inventory System Management - Localhost Schema
-- Import this file in phpMyAdmin (Database.create first or let it create the DB).

CREATE DATABASE IF NOT EXISTS `login_system`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `login_system`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','staff') NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock_qty` INT NOT NULL DEFAULT 0,
  `reorder_level` INT NOT NULL DEFAULT 5,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_products_sku` (`sku`),
  KEY `idx_products_category_id` (`category_id`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `movement_type` ENUM('in','out','adjust') NOT NULL,
  `qty` INT NOT NULL,
  `note` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stock_movements_product_id` (`product_id`),
  KEY `idx_stock_movements_created_by` (`created_by`),
  CONSTRAINT `fk_stock_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_movements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed data (optional)
INSERT INTO `users` (`username`, `password_hash`, `role`) VALUES
('admin', '$2y$10$yI3/2QY5yP.9GQK1XfZcduy6yYz3t7PpQH8d4hR0oC7f3JgQ6oVhK', 'admin');

INSERT INTO `categories` (`name`, `description`) VALUES
('Beverages', 'Drinks and consumables'),
('Office Supplies', 'Stationery and office items'),
('Hardware', 'Tools and hardware');

INSERT INTO `products` (`sku`, `name`, `category_id`, `unit_cost`, `unit_price`, `stock_qty`, `reorder_level`) VALUES
('SKU-0001', 'Bottled Water 500ml', 1, 8.00, 12.00, 120, 20),
('SKU-0002', 'Ballpen Black', 2, 5.00, 10.00, 8, 15),
('SKU-0003', 'Notebook A5', 2, 20.00, 35.00, 0, 10),
('SKU-0004', 'Hammer 16oz', 3, 120.00, 180.00, 5, 5);

INSERT INTO `stock_movements` (`product_id`, `movement_type`, `qty`, `note`, `created_by`) VALUES
(1, 'in', 120, 'Initial stock', 1),
(2, 'in', 8, 'Initial stock', 1),
(3, 'adjust', 0, 'Initialized with zero stock', 1),
(4, 'in', 5, 'Initial stock', 1);
