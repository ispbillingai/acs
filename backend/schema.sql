


-- Simplified Devices table
CREATE TABLE IF NOT EXISTS devices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    serial_number VARCHAR(64) UNIQUE,
    manufacturer VARCHAR(64),
    model_name VARCHAR(64),
    ip_address VARCHAR(45),
    last_contact DATETIME,
    status ENUM('online', 'offline', 'provisioning'),
    ssid VARCHAR(64),
    ssid_password VARCHAR(64),
    connected_clients INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Simplified Sessions table for TR-069 connections
CREATE TABLE IF NOT EXISTS sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_serial VARCHAR(64),
    session_id VARCHAR(64) UNIQUE,
    created_at DATETIME,
    expires_at DATETIME,
    FOREIGN KEY (device_serial) REFERENCES devices(serial_number)
);

-- Connected Clients table
CREATE TABLE IF NOT EXISTS connected_clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    hostname VARCHAR(64),
    connected_since DATETIME,
    FOREIGN KEY (device_id) REFERENCES devices(id)
);

-- Create indexes
CREATE INDEX idx_device_serial ON devices(serial_number);
CREATE INDEX idx_device_status ON devices(status);
CREATE INDEX idx_device_last_contact ON devices(last_contact);
