CREATE DATABASE IF NOT EXISTS yellow_hauz_pos;
USE yellow_hauz_pos;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('cashier', 'admin') NOT NULL DEFAULT 'cashier',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_employee_id (employee_id),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-solid fa-utensils',
    sort_order INT DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    image_url VARCHAR(500),
    temperature ENUM('hot', 'iced', 'both') DEFAULT 'both',
    is_best_seller BOOLEAN DEFAULT FALSE,
    is_available BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_category_id (category_id),
    INDEX idx_name (name),
    INDEX idx_price (price),
    INDEX idx_is_available (is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number INT UNIQUE NOT NULL,
    capacity INT DEFAULT 4,
    status ENUM('available', 'occupied', 'reserved', 'cleaning') DEFAULT 'available',
    current_order_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (current_order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_table_number (table_number),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    table_id INT NULL,
    customer_name VARCHAR(255),
    order_type ENUM('dine_in', 'take_away', 'delivery') NOT NULL DEFAULT 'dine_in',
    payment_method ENUM('cash', 'card', 'gcash') NOT NULL DEFAULT 'cash',
    subtotal DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    tax_rate DECIMAL(5, 2) DEFAULT 12.00,
    tax_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10, 2) DEFAULT 0.00,
    status ENUM('pending', 'processing', 'completed', 'cancelled') DEFAULT 'pending',
    cashier_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE SET NULL,
    FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_order_number (order_number),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_cashier_id (cashier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    special_instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_order_id (order_id),
    INDEX idx_menu_item_id (menu_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




INSERT INTO users (employee_id, username, password, full_name, role) VALUES
('ADMIN001', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin'),
('CASHIER001', 'cashier', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sheila Mae Aledro', 'cashier');


INSERT INTO categories (name, icon, sort_order) VALUES
('Coffee', 'fa-solid fa-mug-saucer', 1),
('On The Rocks', 'fa-solid fa-glass-water', 2),
('Blended', 'fa-solid fa-blender', 3),
('Hot Drinks', 'fa-solid fa-fire', 4),
('Milk Tea', 'fa-solid fa-leaf', 5),
('Food', 'fa-solid fa-cookie', 6);


INSERT INTO menu_items (category_id, name, description, price, image_url, temperature, is_best_seller, is_available) VALUES
(1, 'Espresso', 'Pure and intense espresso shot', 100.00, 'https://plus.unsplash.com/premium_photo-1669687924558-386bff1a0469?q=80&w=688&auto=format&fit=crop', 'hot', FALSE, TRUE),
(1, 'Cappuccino', 'Espresso with steamed milk and foam', 170.00, 'https://images.unsplash.com/photo-1534778101976-62847782c213?w=500&q=80', 'hot', TRUE, TRUE),
(1, 'Spanish Latte', 'Sweet and creamy latte with condensed milk', 200.00, 'https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=500&q=80', 'iced', FALSE, TRUE),
(1, 'Flat White', 'Velvety smooth microfoam espresso', 170.00, 'https://images.unsplash.com/photo-1727080409436-356bdc609899?fm=jpg&q=60&w=3000&auto=format&fit=crop', 'hot', FALSE, TRUE),
(1, 'Macchiato', 'Espresso marked with a dollop of foam', 110.00, 'https://images.unsplash.com/photo-1485808191679-5f86510681a2?w=500&q=80', 'hot', FALSE, TRUE),
(1, 'Americano Hot', 'Espresso diluted with hot water', 140.00, 'https://images.unsplash.com/photo-1599659236990-34cc97c7e363?fm=jpg&q=60&w=3000&auto=format&fit=crop', 'hot', FALSE, TRUE),
(1, 'Cortado', 'Equal parts espresso and steamed milk', 150.00, 'https://images.unsplash.com/photo-1519532059956-a63a37af5deb?w=500&q=80', 'hot', FALSE, TRUE),
(1, 'Latte', 'Smooth espresso with steamed milk', 170.00, 'https://images.unsplash.com/photo-1610889556528-9a770e32642f?w=200&q=80', 'hot', FALSE, TRUE);


INSERT INTO tables (table_number, capacity, status) VALUES
(1, 4, 'available'),
(2, 4, 'available'),
(3, 6, 'available'),
(4, 4, 'available'),
(5, 2, 'available'),
(6, 8, 'available'),
(7, 4, 'available'),
(8, 4, 'available');


INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('tax_rate', '12', 'number', 'Default tax rate percentage'),
('currency', 'PHP', 'string', 'Currency symbol'),
('shop_name', 'Coffee at Yellow Hauz', 'string', 'Shop name for receipts'),
('shop_address', 'Yellow Hauz, Philippines', 'string', 'Shop address'),
('shop_phone', '+63 912 345 6789', 'string', 'Shop contact number'),
('receipt_footer', 'Thank you for visiting Coffee at Yellow Hauz!', 'string', 'Footer message for receipts'),
('business_hours', '07:00-22:00', 'string', 'Operating hours');
