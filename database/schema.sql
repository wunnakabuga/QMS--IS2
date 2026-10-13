-- =====================================================================
-- Web-Based Queue Management System with Predictive Waiting Time
-- Database schema  (MySQL / MariaDB)
-- Implements the logical design in Chapter 4, Table 4.6
-- =====================================================================

DROP DATABASE IF EXISTS qms;
CREATE DATABASE qms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qms;

-- ---------------------------------------------------------------- USER
CREATE TABLE user (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    full_name      VARCHAR(120)  NOT NULL,
    phone_number   VARCHAR(20)   NOT NULL,
    email          VARCHAR(120)  DEFAULT NULL,
    password_hash  VARCHAR(255)  DEFAULT NULL,
    role           ENUM('client','staff','admin') NOT NULL DEFAULT 'client',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_phone (phone_number),
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------ SERVICE_COUNTER
CREATE TABLE service_counter (
    counter_id        INT AUTO_INCREMENT PRIMARY KEY,
    counter_name      VARCHAR(60)  NOT NULL,
    service_type      VARCHAR(80)  NOT NULL,
    status            ENUM('open','closed') NOT NULL DEFAULT 'closed',
    assigned_staff_id INT DEFAULT NULL,
    CONSTRAINT fk_counter_staff FOREIGN KEY (assigned_staff_id)
        REFERENCES user (user_id) ON DELETE SET NULL,
    INDEX idx_counter_service (service_type, status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------- QUEUE_ENTRY
CREATE TABLE queue_entry (
    queue_entry_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    counter_id          INT DEFAULT NULL,
    queue_number        VARCHAR(12) NOT NULL,
    service_type        VARCHAR(80) NOT NULL,
    status              ENUM('waiting','checked_in','called','in_service',
                             'completed','expired','cancelled')
                        NOT NULL DEFAULT 'waiting',
    join_time           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scheduled_time      DATETIME DEFAULT NULL,
    check_in_time       DATETIME DEFAULT NULL,
    called_time         DATETIME DEFAULT NULL,
    service_start_time  DATETIME DEFAULT NULL,
    service_end_time    DATETIME DEFAULT NULL,
    expiry_time         DATETIME DEFAULT NULL,
    predicted_wait_time DECIMAL(6,2) DEFAULT NULL,
    prediction_source   ENUM('model','fallback') DEFAULT NULL,
    delivery_channel    ENUM('e-ticket','sms') NOT NULL DEFAULT 'e-ticket',
    CONSTRAINT fk_entry_user FOREIGN KEY (user_id)
        REFERENCES user (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_entry_counter FOREIGN KEY (counter_id)
        REFERENCES service_counter (counter_id) ON DELETE SET NULL,
    UNIQUE KEY uq_queue_number (queue_number),
    INDEX idx_entry_status (status, service_type),
    INDEX idx_entry_join (join_time)
) ENGINE=InnoDB;

-- ----------------------------------------------------------- SERVICE_LOG
CREATE TABLE service_log (
    service_log_id   INT AUTO_INCREMENT PRIMARY KEY,
    queue_entry_id   INT NOT NULL,
    counter_id       INT DEFAULT NULL,
    status           VARCHAR(30) NOT NULL,
    event_time       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    service_duration INT DEFAULT NULL,      -- seconds
    CONSTRAINT fk_log_entry FOREIGN KEY (queue_entry_id)
        REFERENCES queue_entry (queue_entry_id) ON DELETE CASCADE,
    CONSTRAINT fk_log_counter FOREIGN KEY (counter_id)
        REFERENCES service_counter (counter_id) ON DELETE SET NULL,
    INDEX idx_log_time (event_time),
    INDEX idx_log_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------- NOTIFICATION
CREATE TABLE notification (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    queue_entry_id  INT NOT NULL,
    channel         ENUM('e-ticket','sms') NOT NULL,
    sent_time       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivery_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    message_content TEXT,
    CONSTRAINT fk_notif_entry FOREIGN KEY (queue_entry_id)
        REFERENCES queue_entry (queue_entry_id) ON DELETE CASCADE,
    INDEX idx_notif_time (sent_time)
) ENGINE=InnoDB;

-- =====================================================================
-- Seed data
-- Default password for every seeded staff/admin account: Password123
-- =====================================================================
INSERT INTO user (full_name, phone_number, email, password_hash, role) VALUES
('System Administrator', '+254700000001', 'admin@hudumacentre.go.ke',
 '$2y$12$8KQjeD.jke5Vbknl0RJf3.xuF.bDXM9HQJrEVqbWwUQ4fWBzxxAM6', 'admin'),
('Alice Mwangi',  '+254700000002', 'a.mwangi@hudumacentre.go.ke',
 '$2y$12$8KQjeD.jke5Vbknl0RJf3.xuF.bDXM9HQJrEVqbWwUQ4fWBzxxAM6', 'staff'),
('Brian Chebet',  '+254700000003', 'b.chebet@hudumacentre.go.ke',
 '$2y$12$8KQjeD.jke5Vbknl0RJf3.xuF.bDXM9HQJrEVqbWwUQ4fWBzxxAM6', 'staff'),
('Joan Wanjiru',  '+254700000004', 'j.wanjiru@hudumacentre.go.ke',
 '$2y$12$8KQjeD.jke5Vbknl0RJf3.xuF.bDXM9HQJrEVqbWwUQ4fWBzxxAM6', 'staff');

INSERT INTO service_counter (counter_name, service_type, status, assigned_staff_id) VALUES
('Counter 1', 'Birth Certificate', 'open',   2),
('Counter 2', 'ID Registration',   'open',   3),
('Counter 3', 'ID Registration',   'open',   4),
('Counter 4', 'Business Permit',   'open',   2),
('Counter 5', 'Licence Renewal',   'closed', NULL);
