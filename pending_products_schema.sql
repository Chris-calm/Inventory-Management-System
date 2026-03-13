-- Create pending_products table for product approval workflow
CREATE TABLE IF NOT EXISTS pending_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category_id INT NULL,
    unit_cost DECIMAL(10, 2) DEFAULT 0.00,
    unit_price DECIMAL(10, 2) DEFAULT 0.00,
    reorder_level INT DEFAULT 5,
    status VARCHAR(20) DEFAULT 'pending',
    image_path VARCHAR(255) NULL,
    requested_by INT NULL,
    rejection_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
