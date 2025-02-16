
-- ... keep existing code (database creation and devices table)

-- Enhanced Parameters table
CREATE TABLE IF NOT EXISTS parameters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    param_name VARCHAR(255),
    param_value TEXT,
    param_type VARCHAR(32),
    writable BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    UNIQUE KEY unique_param (device_id, param_name)
);

-- Enhanced Sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_serial VARCHAR(64),
    session_id VARCHAR(64) UNIQUE,
    created_at DATETIME,
    expires_at DATETIME,
    last_activity DATETIME,
    FOREIGN KEY (device_serial) REFERENCES devices(serial_number)
);

-- Enhanced Events table
CREATE TABLE IF NOT EXISTS events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    event_code VARCHAR(64),
    event_type VARCHAR(64),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id)
);

-- Tasks table for queued operations
CREATE TABLE IF NOT EXISTS tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    task_type VARCHAR(32),
    parameters TEXT,
    status ENUM('pending', 'in_progress', 'completed', 'failed'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME,
    FOREIGN KEY (device_id) REFERENCES devices(id)
);

-- Create additional indexes
CREATE INDEX idx_device_serial ON devices(serial_number);
CREATE INDEX idx_device_status ON devices(status);
CREATE INDEX idx_device_last_contact ON devices(last_contact);
CREATE INDEX idx_session_id ON sessions(session_id);
CREATE INDEX idx_session_expires ON sessions(expires_at);
CREATE INDEX idx_param_name ON parameters(param_name);
CREATE INDEX idx_event_code ON events(event_code);
CREATE INDEX idx_task_status ON tasks(status);
