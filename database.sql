CREATE DATABASE IF NOT EXISTS smart_city_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_city_db;

CREATE TABLE departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(24) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('citizen','officer','admin') NOT NULL DEFAULT 'citizen',
    department_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(role),
    FOREIGN KEY(department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE complaints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(24) NOT NULL UNIQUE,
    citizen_id BIGINT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    category VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(255) NOT NULL,
    landmark VARCHAR(150) NULL,
    priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'submitted',
    is_sample TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(status),
    INDEX(department_id),
    INDEX(citizen_id),
    FOREIGN KEY(citizen_id) REFERENCES users(id),
    FOREIGN KEY(department_id) REFERENCES departments(id),
    FOREIGN KEY(assigned_to) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE department_staff (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(24) NULL,
    FOREIGN KEY(department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE complaint_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY(staff_id) REFERENCES department_staff(id),
    FOREIGN KEY(assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE complaint_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL,
    note TEXT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(complaint_id),
    FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY(changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE complaint_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    kind ENUM('evidence','resolution') NOT NULL DEFAULT 'evidence',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE internal_remarks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    remark TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO departments(name, description) VALUES
    ('Municipal Corporation', 'General city services and drainage'),
    ('Road and Transport Department', 'Road maintenance and traffic management'),
    ('Water Supply Department', 'Water supply and distribution'),
    ('Sanitation Department', 'Waste collection and sanitation'),
    ('Electricity and Streetlight Department', 'Electricity and public lighting'),
    ('Public Health Department', 'Public health and community safety'),
    ('Police Department', 'Public safety and police services');

INSERT INTO system_settings(setting_key, setting_value) VALUES
    ('site_name', 'CivicPulse'),
    ('max_upload_mb', '2');
