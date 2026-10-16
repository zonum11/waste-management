-- Waste Management Database
-- Compatible with MySQL 8 / MariaDB 10.4+
-- cPanel database: wastlpsb_waste-management
-- Select this database in phpMyAdmin before importing.

USE `wastlpsb_waste-management`;

-- Drops old tables, child tables first.
-- WARNING: This deletes existing table data.
DROP TABLE IF EXISTS report_history;
DROP TABLE IF EXISTS report_waste_types;
DROP TABLE IF EXISTS waste_reports;
DROP TABLE IF EXISTS schedule_zones;
DROP TABLE IF EXISTS collection_schedule;
DROP TABLE IF EXISTS waste_types;
DROP TABLE IF EXISTS zones;
DROP TABLE IF EXISTS users;

-- Users: residents and admins
CREATE TABLE users (
    user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fname VARCHAR(50) NOT NULL,
    lname VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NULL,
    address VARCHAR(255) NOT NULL,
    role ENUM('resident', 'admin') NOT NULL DEFAULT 'resident',
    password_hash VARCHAR(255) NOT NULL,
    date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Zones: areas, puroks, or streets
CREATE TABLE zones (
    zone_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_name VARCHAR(100) NOT NULL,
    barangay_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    PRIMARY KEY (zone_id),
    UNIQUE KEY uq_zones_name (zone_name, barangay_name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Waste types
CREATE TABLE waste_types (
    type_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_name VARCHAR(50) NOT NULL,
    description TEXT NULL,
    PRIMARY KEY (type_id),
    UNIQUE KEY uq_waste_types_name (type_name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Collection schedules
CREATE TABLE collection_schedule (
    sched_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collect_day ENUM(
        'Monday', 'Tuesday', 'Wednesday', 'Thursday',
        'Friday', 'Saturday', 'Sunday'
    ) NOT NULL,
    collect_time TIME NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (sched_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Connect schedules to zones
CREATE TABLE schedule_zones (
    sched_id INT UNSIGNED NOT NULL,
    zone_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (sched_id, zone_id),
    CONSTRAINT fk_schedzones_sched
        FOREIGN KEY (sched_id)
        REFERENCES collection_schedule (sched_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_schedzones_zone
        FOREIGN KEY (zone_id)
        REFERENCES zones (zone_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Waste reports submitted by residents
CREATE TABLE waste_reports (
    report_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    zone_id INT UNSIGNED NOT NULL,
    issue_type ENUM(
        'missed_collection',
        'improper_disposal',
        'overflowing_garbage',
        'other'
    ) NOT NULL,
    issue_description TEXT NOT NULL,
    photo VARCHAR(255) NULL,
    current_status ENUM(
        'pending', 'in_progress', 'resolved', 'rejected'
    ) NOT NULL DEFAULT 'pending',
    date_reported DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id),
    KEY idx_reports_user (user_id),
    KEY idx_reports_status (current_status),
    CONSTRAINT fk_reports_user
        FOREIGN KEY (user_id)
        REFERENCES users (user_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_reports_zone
        FOREIGN KEY (zone_id)
        REFERENCES zones (zone_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Connect reports to waste types
CREATE TABLE report_waste_types (
    report_id INT UNSIGNED NOT NULL,
    type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (report_id, type_id),
    CONSTRAINT fk_rwt_report
        FOREIGN KEY (report_id)
        REFERENCES waste_reports (report_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_rwt_type
        FOREIGN KEY (type_id)
        REFERENCES waste_types (type_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Report status history and audit trail
CREATE TABLE report_history (
    history_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    remarks TEXT NULL,
    status ENUM(
        'pending', 'in_progress', 'resolved', 'rejected'
    ) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    KEY idx_history_report (report_id),
    CONSTRAINT fk_history_report
        FOREIGN KEY (report_id)
        REFERENCES waste_reports (report_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_history_user
        FOREIGN KEY (user_id)
        REFERENCES users (user_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;