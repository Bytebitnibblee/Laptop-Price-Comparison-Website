-- Create database
CREATE DATABASE IF NOT EXISTS laptop_comparison;
USE laptop_comparison;

------------------------------------------------------------
-- USERS TABLE
------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_admin TINYINT(1) DEFAULT 0,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

------------------------------------------------------------
-- LAPTOPS TABLE
------------------------------------------------------------
CREATE TABLE laptops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    brand VARCHAR(100) NOT NULL,
    processor VARCHAR(100),
    ram VARCHAR(50),
    storage VARCHAR(50),
    screen_size VARCHAR(50),
    image_url VARCHAR(500),
    specs TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_brand (brand),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

------------------------------------------------------------
-- PRICES TABLE
------------------------------------------------------------
CREATE TABLE prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    retailer VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    product_url VARCHAR(500),
    in_stock TINYINT(1) DEFAULT 1,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
        ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    INDEX idx_laptop_retailer (laptop_id, retailer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

------------------------------------------------------------
-- PRICE ALERTS TABLE
------------------------------------------------------------
CREATE TABLE price_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    laptop_id INT NOT NULL,
    target_price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    INDEX idx_user_alerts (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

------------------------------------------------------------
-- ALERT NOTIFICATIONS TABLE
------------------------------------------------------------
CREATE TABLE alert_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_id INT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    price_at_alert DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (alert_id) REFERENCES price_alerts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

------------------------------------------------------------
-- PASSWORD RESET TOKENS TABLE
------------------------------------------------------------
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) DEFAULT 0,
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

------------------------------------------------------------
-- INSERT SAMPLE ADMIN USER
-- Password: admin123
------------------------------------------------------------
INSERT INTO users (email, password, name, is_admin) VALUES 
(
    'admin@laptopcompare.com',
    '$2y$12$fVchtUI/S9eMRhy.C693y.cpN5zO9F6EvU2Y2Ga9RLQhIJ/ac16Za',
    'Admin User',
    1
);

------------------------------------------------------------
-- INSERT SAMPLE LAPTOPS
------------------------------------------------------------
INSERT INTO laptops (name, brand, processor, ram, storage, screen_size, image_url, specs) VALUES
('Dell XPS 13', 'Dell', 'Intel Core i7-1165G7', '16GB', '512GB SSD', '13.4"', 'https://via.placeholder.com/300x200?text=Dell+XPS+13', 'Intel Iris Xe Graphics, Windows 11, Thunderbolt 4'),
('MacBook Air M2', 'Apple', 'Apple M2', '8GB', '256GB SSD', '13.6"', 'https://via.placeholder.com/300x200?text=MacBook+Air', '8-core CPU, 8-core GPU, macOS Ventura'),
('HP Pavilion 15', 'HP', 'AMD Ryzen 5 5600H', '8GB', '512GB SSD', '15.6"', 'https://via.placeholder.com/300x200?text=HP+Pavilion', 'NVIDIA GTX 1650, Windows 11, Backlit Keyboard'),
('Lenovo ThinkPad X1', 'Lenovo', 'Intel Core i5-1135G7', '16GB', '512GB SSD', '14"', 'https://via.placeholder.com/300x200?text=ThinkPad+X1', 'Intel Iris Xe, Windows 11 Pro, MIL-STD Tested'),
('ASUS ROG Strix G15', 'ASUS', 'AMD Ryzen 7 5800H', '16GB', '1TB SSD', '15.6"', 'https://via.placeholder.com/300x200?text=ROG+Strix', 'NVIDIA RTX 3060, 144Hz Display, RGB Keyboard');

------------------------------------------------------------
-- INSERT SAMPLE PRICES
------------------------------------------------------------
INSERT INTO prices (laptop_id, retailer, price, product_url, in_stock) VALUES
(1, 'Amazon', 89999.00, 'https://amazon.in/sample', 1),
(1, 'Flipkart', 87999.00, 'https://flipkart.com/sample', 1);