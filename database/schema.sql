-- ============================================================
-- 1. CORE USER & AUTHENTICATION
-- ============================================================

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255), 
    full_name VARCHAR(255) NOT NULL,
    country VARCHAR(100),
    currency_code VARCHAR(10),
    currency_symbol VARCHAR(10),
    role ENUM('farmer', 'customer', 'admin', 'delivery_agent', 'delivery_staff') NOT NULL DEFAULT 'customer',
    hub_id INT DEFAULT NULL, 
    phone VARCHAR(20),
    address TEXT,
    google_id VARCHAR(255) UNIQUE, 
    profile_image VARCHAR(500),
    is_verified BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    admin_access ENUM('all', 'delivery_only') DEFAULT 'all',
    revenue_target DECIMAL(12, 6) DEFAULT 50000,
    low_stock_threshold INT DEFAULT 100,
    reset_token VARCHAR(255) DEFAULT NULL,
    reset_token_sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    FOREIGN KEY (hub_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 2. PRODUCTS & INVENTORY
-- ============================================================

-- Products table (for farmers to add their spices)
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    farmer_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    price DECIMAL(12, 6) NOT NULL,
    base_currency VARCHAR(10) DEFAULT 'INR',
    farmer_country VARCHAR(100),
    quantity DECIMAL(10, 2) NOT NULL,
    unit VARCHAR(20) DEFAULT 'kg',
    image_url VARCHAR(500),
    harvest_date DATE,
    expiry_date DATE,
    is_organic BOOLEAN DEFAULT FALSE,
    is_available BOOLEAN DEFAULT TRUE,
    is_featured BOOLEAN DEFAULT FALSE,
    is_new_arrival BOOLEAN DEFAULT TRUE,
    view_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_farmer (farmer_id),
    INDEX idx_category (category),
    INDEX idx_product_name (product_name)
) ENGINE=InnoDB;

-- Inventory table (for tracking stock)
CREATE TABLE inventory (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    farmer_id INT NOT NULL,
    current_stock DECIMAL(10, 2) NOT NULL,
    reserved_stock DECIMAL(10, 2) DEFAULT 0,
    available_stock DECIMAL(10, 2) GENERATED ALWAYS AS (current_stock - reserved_stock) STORED,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_product_farmer (product_id, farmer_id),
    INDEX idx_product (product_id),
    INDEX idx_farmer (farmer_id)
) ENGINE=InnoDB;

-- Inventory Transaction Logs
CREATE TABLE inventory_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    change_amount DECIMAL(10, 2) NOT NULL,
    type ENUM('restock', 'sale', 'adjustment', 'return') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
) ENGINE=InnoDB;

-- Product Tracking History
CREATE TABLE product_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    quantity DECIMAL(10, 2),
    price DECIMAL(12, 6),
    unit VARCHAR(20),
    category VARCHAR(50),
    comment TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
) ENGINE=InnoDB;

-- ============================================================
-- 3. ORDERS & TRANSACTIONS
-- ============================================================

-- Orders table
CREATE TABLE orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    farmer_id INT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit_price DECIMAL(12, 6) NOT NULL,
    total_price DECIMAL(12, 6) NOT NULL,
    currency_code VARCHAR(10) DEFAULT 'INR',
    exchange_rate DECIMAL(10, 6) DEFAULT 1.000000,
    status ENUM('ordered', 'shipped', 'delivered', 'cancelled', 'shipped_pending') DEFAULT 'ordered',
    delivery_address TEXT NOT NULL,
    delivery_agent_id INT NULL DEFAULT NULL,
    delivery_staff_id INT NULL DEFAULT NULL,
    payment_method VARCHAR(50),
    payment_status ENUM('pending', 'paid', 'refunded') DEFAULT 'pending',
    shipped_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    delivery_otp VARCHAR(4) DEFAULT NULL,
    delivery_otp_sent_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_agent_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (delivery_staff_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_farmer (farmer_id),
    INDEX idx_status (status),
    INDEX idx_order_date (order_date),
    INDEX idx_delivery_agent (delivery_agent_id),
    INDEX idx_delivery_staff (delivery_staff_id)
) ENGINE=InnoDB;

-- Cart table
CREATE TABLE cart (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_cart_item (customer_id, product_id),
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB;

-- Order Tracking History
CREATE TABLE order_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    type ENUM('order', 'auction') DEFAULT 'order',
    location VARCHAR(255),
    comment TEXT,
    created_by INT NULL DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_type (order_id, type),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB;

-- ============================================================
-- 4. AUCTIONS
-- ============================================================

-- Auctions table
CREATE TABLE auctions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    farmer_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    starting_price DECIMAL(12, 6) NOT NULL,
    base_currency VARCHAR(10) DEFAULT 'INR',
    farmer_country VARCHAR(100),
    current_bid DECIMAL(12, 6) DEFAULT 0.00,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
    unit VARCHAR(20) DEFAULT 'kg',
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    image_url VARCHAR(500),
    status ENUM('scheduled', 'active', 'completed', 'cancelled') DEFAULT 'scheduled',
    winner_id INT, 
    payment_status ENUM('pending', 'paid') DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    shipping_status ENUM('pending', 'shipped', 'delivered', 'shipped_pending') DEFAULT 'pending',
    shipping_address TEXT,
    phone VARCHAR(20),
    delivery_agent_id INT NULL DEFAULT NULL,
    delivery_staff_id INT NULL DEFAULT NULL,
    tracking_number VARCHAR(100),
    delivery_otp VARCHAR(4) DEFAULT NULL,
    delivery_otp_sent_at TIMESTAMP NULL DEFAULT NULL,
    shipped_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (winner_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (delivery_agent_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (delivery_staff_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_farmer (farmer_id),
    INDEX idx_status (status),
    INDEX idx_delivery_agent (delivery_agent_id),
    INDEX idx_delivery_staff (delivery_staff_id),
    INDEX idx_winner (winner_id)
) ENGINE=InnoDB;

-- Bids table
CREATE TABLE bids (
    id INT PRIMARY KEY AUTO_INCREMENT,
    auction_id INT NOT NULL,
    customer_id INT NOT NULL,
    bid_amount DECIMAL(12, 6) NOT NULL,
    bid_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_auction (auction_id),
    INDEX idx_customer (customer_id),
    INDEX idx_bid_amount (bid_amount)
) ENGINE=InnoDB;

-- ============================================================
-- 5. EXPORT SYSTEM
-- ============================================================

-- Export Requests table
CREATE TABLE export_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit VARCHAR(20) DEFAULT 'kg',
    target_country VARCHAR(100) NOT NULL,
    shipping_port VARCHAR(150),
    preferred_shipping_mode ENUM('sea', 'air') DEFAULT 'sea',
    business_name VARCHAR(255),
    business_registration_no VARCHAR(100),
    importer_license_no VARCHAR(100),
    contact_person VARCHAR(150) DEFAULT NULL,
    contact_email VARCHAR(200) DEFAULT NULL,
    contact_phone VARCHAR(50) DEFAULT NULL,
    delivery_street VARCHAR(255) DEFAULT NULL,
    delivery_city VARCHAR(100) DEFAULT NULL,
    delivery_postal_code VARCHAR(20) DEFAULT NULL,
    incoterms VARCHAR(10) DEFAULT NULL,
    order_type ENUM('bulk','sample') DEFAULT 'bulk',
    required_delivery_date DATE DEFAULT NULL,
    offered_price DECIMAL(12, 6),
    currency_code VARCHAR(10) DEFAULT 'INR',
    exchange_rate DECIMAL(10, 6) DEFAULT 1.000000,
    payment_terms ENUM('advance', 'lc', 'dp', 'da') DEFAULT 'advance',
    requires_organic_cert BOOLEAN DEFAULT FALSE,
    requires_phytosanitary BOOLEAN DEFAULT FALSE,
    requires_quality_test BOOLEAN DEFAULT FALSE,
    packaging_requirements TEXT,
    special_notes TEXT,
    farmer_notes TEXT,
    admin_notes TEXT,
    admin_reviewed_by INT NULL,
    admin_reviewed_at TIMESTAMP NULL,
    status ENUM('pending','under_review','approved','rejected','quality_testing','documentation','shipped','delivered','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_farmer (farmer_id),
    INDEX idx_product (product_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Export Documents table (for tracking required export documentation per request)
CREATE TABLE export_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    export_request_id INT NOT NULL,
    document_type ENUM('commercial_invoice','packing_list','bill_of_lading','certificate_of_origin','phytosanitary_certificate','quality_certificate','insurance_certificate','iec_certificate','spices_board_cert','fssai_license','other') NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (export_request_id) REFERENCES export_requests(id) ON DELETE CASCADE,
    INDEX idx_export_request (export_request_id)
) ENGINE=InnoDB;

-- Export status tracking history
CREATE TABLE export_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    export_request_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    notes TEXT,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (export_request_id) REFERENCES export_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_export_request (export_request_id)
) ENGINE=InnoDB;

-- Farmer Compliance Documents (Reusable certifications across multiple exports)
CREATE TABLE farmer_compliance_docs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    farmer_id INT NOT NULL,
    document_type ENUM(
        'iec_certificate','spices_board_cert','fssai_license',
        'quality_certificate','phytosanitary_certificate',
        'commercial_invoice','packing_list','bill_of_lading',
        'certificate_of_origin','insurance_certificate','other'
    ) NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_farmer_doc_type (farmer_id, document_type),
    INDEX idx_farmer (farmer_id)
) ENGINE=InnoDB;

-- Farmer specialized certificates
CREATE TABLE farmer_certificates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    farmer_id INT NOT NULL,
    cert_type ENUM(
        'organic_certificate',
        'fssai_license',
        'spice_board_registration',
        'gst_certificate',
        'farm_ownership_proof',
        'quality_testing_report'
    ) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    admin_notes TEXT DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_farmer_cert_type (farmer_id, cert_type),
    INDEX idx_farmer (farmer_id),
    INDEX idx_status (verification_status)
) ENGINE=InnoDB;

-- ============================================================
-- 6. RETURNS & WALLET
-- ============================================================

-- Returns table
CREATE TABLE `returns` (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id    INT NOT NULL,
    customer_id INT NOT NULL,
    farmer_id   INT NOT NULL,
    product_id  INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    description TEXT,
    image_path  VARCHAR(500) DEFAULT NULL,
    refund_method  ENUM('wallet','original') DEFAULT 'wallet',
    refund_amount  DECIMAL(12,6) DEFAULT 0,
    currency_code  VARCHAR(10)  DEFAULT 'INR',
    status ENUM(
        'requested',
        'under_review',
        'approved',
        'rejected',
        'refund_processing',
        'refund_completed'
    ) DEFAULT 'requested',
    admin_notes VARCHAR(1000) DEFAULT NULL,
    reviewed_by INT           DEFAULT NULL,
    reviewed_at TIMESTAMP     NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id)    REFERENCES orders(id)  ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (farmer_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id)   ON DELETE SET NULL,
    INDEX idx_order    (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status   (status),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB;

-- Wallet table (One row per customer â€” balance is cumulative)
CREATE TABLE wallet (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    user_id    INT NOT NULL UNIQUE,
    balance    DECIMAL(12, 6) DEFAULT 0.000000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- Wallet Transactions table (Audit trail for every credit / debit)
CREATE TABLE wallet_transactions (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    user_id        INT NOT NULL,
    amount         DECIMAL(12, 6) NOT NULL,
    type           ENUM('credit','debit') NOT NULL,
    description    VARCHAR(500) DEFAULT NULL,
    reference_id   INT          DEFAULT NULL,
    reference_type VARCHAR(50)  DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user    (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- 7. REVIEWS, NOTIFICATIONS & LOGS
-- ============================================================

-- Reviews table
CREATE TABLE reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NOT NULL,
    rating INT NOT NULL DEFAULT 5,
    review_text TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY unique_review (customer_id, order_id)
) ENGINE=InnoDB;

-- Notifications table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'system',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Admin activity logs
CREATE TABLE admin_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    target_table VARCHAR(50),
    target_id INT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin (admin_id),
    INDEX idx_action (action_type)
) ENGINE=InnoDB;

-- ============================================================
-- 8. DEFAULT DATA (SEEDS)
-- ============================================================

-- Insert default admin user
INSERT INTO users (email, password, full_name, country, currency_code, currency_symbol, role, is_verified, is_active, admin_access) 
VALUES ('admin@gmail.com', '$argon2id$v=19$m=65536,t=4,p=1$NE14V3o1WFpZWHhzM0hoeg$Hlu3RJendjqJbiZhdLXBvqM6E53Zd8VjMcC7JxKruLE', 'Super Admin', 'India', 'INR', '₹', 'admin', TRUE, TRUE, 'all');
-- Default password is: admin 
