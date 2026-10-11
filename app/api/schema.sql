-- Base schema for Intelligent Laundry Management System
-- Import once in phpMyAdmin (Import tab) or run: mysql -u root < schema.sql
-- The remaining tables (settings, flags, match_logs) are created automatically by api/db.php.

CREATE DATABASE IF NOT EXISTS `laundry_mgmt_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `laundry_mgmt_db`;

CREATE TABLE IF NOT EXISTS `users` (
  `user_id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(50)  NOT NULL,
  `full_name`      VARCHAR(100) NOT NULL DEFAULT '',
  `password_hash`  VARCHAR(255) NOT NULL,
  `plain_password` VARCHAR(255) NOT NULL DEFAULT '',
  `role`           ENUM('Staff','Admin') NOT NULL DEFAULT 'Staff',
  `avatar`         VARCHAR(255) NULL DEFAULT NULL,
  `on_status`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
  `customer_id` INT(11)      NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `batches` (
  `batch_id`    INT(11)   NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11)   NOT NULL,
  `staff_id`    INT(11)   NULL,
  `status`      ENUM('Open','Completed') NOT NULL DEFAULT 'Open',
  `item_count`  INT(11)   NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`batch_id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `batches_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `garments` (
  `garment_id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `garment_name`      VARCHAR(100) NOT NULL DEFAULT 'Item',
  `batch_id`          INT(11)      NOT NULL,
  `intake_image_path` VARCHAR(255) NULL,
  `intake_image`      MEDIUMTEXT   NULL,
  `match_status`      ENUM('Pending','Matched') NOT NULL DEFAULT 'Pending',
  `match_type`        ENUM('auto','manual','pending') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`garment_id`),
  KEY `batch_id` (`batch_id`),
  CONSTRAINT `garments_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rescans` (
  `rescan_id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `batch_id`           INT(11)      NULL,
  `matched_garment_id` INT(11)      NULL,
  `similarity_score`   FLOAT        NULL,
  `decision`           VARCHAR(20)  NOT NULL,
  `confirmed_by`       INT(11)      NULL,
  `rescan_image_path`  VARCHAR(255) NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rescan_id`),
  KEY `batch_id` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default logins (staff / staff123, admin / admin123)
INSERT IGNORE INTO `users` (`username`, `full_name`, `password_hash`, `plain_password`, `role`, `on_status`) VALUES
  ('admin', 'System Administrator', '', 'admin123', 'Admin', 1),
  ('staff', 'Laundry Staff', '', 'staff123', 'Staff', 1);
