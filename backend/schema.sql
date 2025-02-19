
-- Simplified Devices table with additional Mikrotik parameters
CREATE TABLE IF NOT EXISTS devices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    serial_number VARCHAR(64) UNIQUE,
    manufacturer VARCHAR(64),
    model_name VARCHAR(64),
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    last_contact DATETIME,
    status ENUM('online', 'offline', 'provisioning'),
    software_version VARCHAR(32),
    hardware_version VARCHAR(32),
    ssid VARCHAR(64),
    ssid_password VARCHAR(64),
    uptime INT UNSIGNED,
    local_admin_password VARCHAR(255),
    tr069_password VARCHAR(255),
    connected_clients INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- WiFi Connected Clients table with enhanced details
CREATE TABLE IF NOT EXISTS connected_clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    hostname VARCHAR(64),
    signal_strength INT,
    connected_since DATETIME,
    last_seen DATETIME,
    FOREIGN KEY (device_id) REFERENCES devices(id)
);

-- Sessions table remains unchanged
CREATE TABLE IF NOT EXISTS sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_serial VARCHAR(64),
    session_id VARCHAR(64) UNIQUE,
    created_at DATETIME,
    expires_at DATETIME,
    FOREIGN KEY (device_serial) REFERENCES devices(serial_number)
);

-- New Users table for ACS authentication
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(64) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default admin user (password: admin)
INSERT INTO users (username, password, role) 
VALUES ('admin', '$2y$10$92O8.ej9F0ltpVbRGvOTOuWmF.ZFCC1ku0zGHXBaQA5mGqJM5fVGS', 'admin')
ON DUPLICATE KEY UPDATE username=username;

-- Create indexes
CREATE INDEX idx_device_serial ON devices(serial_number);
CREATE INDEX idx_device_status ON devices(status);
CREATE INDEX idx_device_last_contact ON devices(last_contact);
CREATE INDEX idx_device_mac ON devices(mac_address);
CREATE INDEX idx_username ON users(username);
