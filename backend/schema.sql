
-- Create the database
CREATE DATABASE IF NOT EXISTS tr069_acs;
USE tr069_acs;

-- Devices table
CREATE TABLE IF NOT EXISTS devices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    serial_number VARCHAR(64) UNIQUE,
    oui VARCHAR(6),
    manufacturer VARCHAR(64),
    model_name VARCHAR(64),
    software_version VARCHAR(32),
    hardware_version VARCHAR(32),
    ip_address VARCHAR(45),
    last_contact DATETIME,
    status ENUM('online', 'offline', 'provisioning'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Parameters table
CREATE TABLE IF NOT EXISTS parameters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    param_name VARCHAR(255),
    param_value TEXT,
    param_type VARCHAR(32),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    UNIQUE KEY unique_param (device_id, param_name)
);

-- Sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_serial VARCHAR(64),
    session_id VARCHAR(64) UNIQUE,
    created_at DATETIME,
    expires_at DATETIME,
    FOREIGN KEY (device_serial) REFERENCES devices(serial_number)
);

-- Events table
CREATE TABLE IF NOT EXISTS events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    event_type VARCHAR(64),
    event_code VARCHAR(64),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id)
);

-- Create indexes
CREATE INDEX idx_device_serial ON devices(serial_number);
CREATE INDEX idx_device_status ON devices(status);
CREATE INDEX idx_device_last_contact ON devices(last_contact);
CREATE INDEX idx_session_id ON sessions(session_id);
CREATE INDEX idx_param_name ON parameters(param_name);
