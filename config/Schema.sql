-- ===================================================================
-- JWG RESTO POS - Complete Database Schema
-- Version: 1.0.0
-- Author: Jefri Wahyu Gunawan
-- ===================================================================

-- Drop existing tables if exists (for fresh install)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS payment_splits;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS customer_orders;
DROP TABLE IF EXISTS recipe_ingredients;
DROP TABLE IF EXISTS inventory_movements;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS table_qr_codes;
DROP TABLE IF EXISTS tables;
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS discounts;
SET FOREIGN_KEY_CHECKS = 1;

-- ===================================================================
-- 1. USERS & AUTHENTICATION
-- ===================================================================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'manager', 'cashier', 'waiter', 'chef') DEFAULT 'cashier',
    avatar VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 2. MENU & CATEGORIES
-- ===================================================================

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    cost_price DECIMAL(10,2),
    image VARCHAR(255),
    is_available TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,
    preparation_time INT DEFAULT 15 COMMENT 'Minutes',
    stock_quantity INT,
    calories INT,
    spicy_level ENUM('none', 'mild', 'medium', 'hot', 'very_hot') DEFAULT 'none',
    allergens TEXT COMMENT 'JSON array of allergens',
    tags TEXT COMMENT 'JSON array of tags',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_category (category_id),
    INDEX idx_available (is_available),
    INDEX idx_featured (is_featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 3. TABLES & RESERVATIONS
-- ===================================================================

CREATE TABLE tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(20) UNIQUE NOT NULL,
    capacity INT NOT NULL,
    location VARCHAR(50) COMMENT 'indoor/outdoor/vip',
    status ENUM('available', 'occupied', 'reserved', 'cleaning') DEFAULT 'available',
    qr_code VARCHAR(255) COMMENT 'Path to QR code image',
    current_order_id INT,
    assigned_waiter_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_waiter_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_number (table_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_id INT NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    customer_email VARCHAR(100),
    party_size INT NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    duration_minutes INT DEFAULT 120,
    status ENUM('pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show') DEFAULT 'pending',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_date_time (reservation_date, reservation_time),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 4. ORDERS
-- ===================================================================

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    table_id INT,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    customer_email VARCHAR(100),
    user_id INT COMMENT 'Staff who created order',
    order_type ENUM('dine_in', 'takeaway', 'delivery', 'qr_order') DEFAULT 'dine_in',
    order_source ENUM('admin', 'customer_qr', 'waiter', 'online') DEFAULT 'admin',
    status ENUM('pending', 'confirmed', 'preparing', 'ready', 'served', 'completed', 'cancelled') DEFAULT 'pending',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_percentage DECIMAL(5,2) DEFAULT 10.00,
    service_charge DECIMAL(10,2) DEFAULT 0.00,
    service_charge_percentage DECIMAL(5,2) DEFAULT 5.00,
    discount DECIMAL(10,2) DEFAULT 0.00,
    discount_type ENUM('fixed', 'percentage') DEFAULT 'fixed',
    discount_code VARCHAR(50),
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    special_requests TEXT,
    is_paid TINYINT(1) DEFAULT 0,
    paid_at DATETIME,
    completed_at DATETIME,
    cancelled_at DATETIME,
    cancellation_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_number (order_number),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_order_type (order_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    notes TEXT COMMENT 'Special instructions for this item',
    status ENUM('pending', 'preparing', 'ready', 'served', 'cancelled') DEFAULT 'pending',
    prepared_by INT COMMENT 'Chef who prepared this item',
    prepared_at DATETIME,
    served_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_prepared_by (prepared_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 5. PAYMENTS
-- ===================================================================

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_number VARCHAR(50) UNIQUE NOT NULL,
    order_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'qris', 'gopay', 'ovo', 'dana', 'shopee', 'transfer') NOT NULL,
    payment_status ENUM('pending', 'completed', 'failed', 'refunded', 'partial') DEFAULT 'pending',
    transaction_id VARCHAR(100) COMMENT 'Gateway transaction ID',
    gateway_response TEXT COMMENT 'JSON response from payment gateway',
    change_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT 'For cash payments',
    paid_amount DECIMAL(10,2) COMMENT 'Actual amount paid',
    notes TEXT,
    processed_by INT,
    paid_at DATETIME,
    refunded_at DATETIME,
    refund_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payment_number (payment_number),
    INDEX idx_order (order_id),
    INDEX idx_status (payment_status),
    INDEX idx_method (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    split_number INT NOT NULL COMMENT '1, 2, 3 for person 1, 2, 3',
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'qris', 'gopay', 'ovo', 'dana', 'shopee', 'transfer') NOT NULL,
    payment_status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    transaction_id VARCHAR(100),
    paid_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 6. INVENTORY
-- ===================================================================

CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) COMMENT 'ingredients/drinks/packaging/supplies',
    unit VARCHAR(20) NOT NULL COMMENT 'kg/liter/pcs/box',
    current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    minimum_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    unit_price DECIMAL(10,2),
    supplier VARCHAR(100),
    supplier_phone VARCHAR(20),
    last_restock_date DATE,
    restock_quantity DECIMAL(10,2),
    expiry_date DATE,
    storage_location VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (item_name),
    INDEX idx_category (category),
    INDEX idx_low_stock (current_stock, minimum_stock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipe_ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    inventory_item_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL COMMENT 'Amount needed per serving',
    unit VARCHAR(20) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_item_id) REFERENCES inventory(id) ON DELETE CASCADE,
    INDEX idx_menu_item (menu_item_id),
    INDEX idx_inventory_item (inventory_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT NOT NULL,
    movement_type ENUM('in', 'out', 'waste', 'adjustment', 'return') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    reference_type ENUM('purchase', 'order', 'manual', 'auto_deduct') COMMENT 'What triggered this movement',
    reference_id INT COMMENT 'ID of related record (order_id, purchase_id)',
    reason TEXT,
    cost DECIMAL(10,2),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_item_id) REFERENCES inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inventory (inventory_item_id),
    INDEX idx_type (movement_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 7. DISCOUNTS & PROMOTIONS
-- ===================================================================

CREATE TABLE discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE,
    description TEXT,
    discount_type ENUM('percentage', 'fixed', 'buy_x_get_y', 'happy_hour') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL COMMENT 'Percentage or fixed amount',
    min_purchase DECIMAL(10,2) DEFAULT 0.00,
    max_discount DECIMAL(10,2) COMMENT 'Maximum discount amount for percentage',
    apply_to ENUM('total', 'item', 'category') DEFAULT 'total',
    target_id INT COMMENT 'Menu item ID or category ID if apply_to is item/category',
    usage_limit INT COMMENT 'How many times this can be used',
    usage_count INT DEFAULT 0,
    start_date DATE,
    end_date DATE,
    start_time TIME COMMENT 'For happy hour',
    end_time TIME COMMENT 'For happy hour',
    active_days VARCHAR(50) COMMENT 'JSON array of active days (mon,tue,wed...)',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_active (is_active),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 8. SETTINGS
-- ===================================================================

CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'string' COMMENT 'string/number/boolean/json',
    category VARCHAR(50) COMMENT 'general/payment/notification/print',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 9. ACTIVITY LOGS
-- ===================================================================

CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL COMMENT 'login/logout/create_order/update_menu/etc',
    entity_type VARCHAR(50) COMMENT 'order/menu/payment/user',
    entity_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    details TEXT COMMENT 'JSON details of the action',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 10. INITIAL DATA
-- ===================================================================

-- Default Admin User (password: admin123)
INSERT INTO users (username, email, password, full_name, role, phone) VALUES
('admin', 'admin@jwg.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin', '081234567890');

-- Default Categories
INSERT INTO categories (name, description, icon, sort_order) VALUES
('Seafood', 'Fresh seafood dishes', '🦐', 1),
('Main Course', 'Main dishes', '🍽️', 2),
('Appetizers', 'Starters and appetizers', '🥗', 3),
('Beverages', 'Drinks and beverages', '🥤', 4),
('Desserts', 'Sweet desserts', '🍰', 5);

-- Default Tables
INSERT INTO tables (table_number, capacity, location, status) VALUES
('T01', 2, 'indoor', 'available'),
('T02', 2, 'indoor', 'available'),
('T03', 4, 'indoor', 'available'),
('T04', 4, 'indoor', 'available'),
('T05', 6, 'indoor', 'available'),
('T06', 6, 'outdoor', 'available'),
('T07', 8, 'vip', 'available');

-- Default Settings
INSERT INTO settings (setting_key, setting_value, setting_type, category, description) VALUES
('restaurant_name', 'Restaurant Demo', 'string', 'general', 'Restaurant name'),
('restaurant_address', 'Jl. Example No. 123', 'string', 'general', 'Restaurant address'),
('restaurant_phone', '021-1234567', 'string', 'general', 'Restaurant phone'),
('tax_percentage', '10', 'number', 'general', 'Tax percentage (PPN)'),
('service_charge_percentage', '5', 'number', 'general', 'Service charge percentage'),
('currency', 'IDR', 'string', 'general', 'Currency code'),
('timezone', 'Asia/Jakarta', 'string', 'general', 'Timezone'),
('payment_gateway', 'midtrans', 'string', 'payment', 'Payment gateway provider'),
('enable_qr_ordering', '1', 'boolean', 'general', 'Enable QR code self-ordering'),
('auto_deduct_inventory', '1', 'boolean', 'inventory', 'Auto-deduct inventory on order');

-- ===================================================================
-- END OF SCHEMA
-- ===================================================================
