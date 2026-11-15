-- Smart Blood Bank Management System
-- Database Schema for MySQL 8+ / MariaDB
-- WAMP Database Name: smart_blood_bank (or `smart blood bank`)

CREATE DATABASE IF NOT EXISTS `smart_blood_bank` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `smart_blood_bank`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `full_name` varchar(60) NOT NULL,
  `email` varchar(120) NOT NULL UNIQUE,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER') NOT NULL,
  `blood_group` varchar(8) DEFAULT NULL,
  `institution_id` varchar(12) NOT NULL,
  `institution_verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Contact Messages Table
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `message_id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(60) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER') NOT NULL,
  `subject` varchar(80) NOT NULL,
  `message` varchar(1000) NOT NULL,
  `sent_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Donors Table
CREATE TABLE IF NOT EXISTS `donors` (
  `donor_id` int NOT NULL AUTO_INCREMENT,
  `managed_by` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `contact_details` varchar(150) DEFAULT NULL,
  `blood_group` enum('O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE') NOT NULL,
  `last_donation_date` date DEFAULT NULL,
  PRIMARY KEY (`donor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Blood Units Table
CREATE TABLE IF NOT EXISTS `blood_units` (
  `unit_id` int NOT NULL AUTO_INCREMENT,
  `donor_id` int DEFAULT NULL,
  `blood_group` enum('O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE') NOT NULL,
  `collection_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `status` enum('QUARANTINED','AVAILABLE','RESERVED','ISSUED','EXPIRED') NOT NULL DEFAULT 'QUARANTINED',
  `institution_id` int DEFAULT NULL,
  PRIMARY KEY (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Blood Requests Table
CREATE TABLE IF NOT EXISTS `blood_requests` (
  `request_id` int NOT NULL AUTO_INCREMENT,
  `submitted_by` int NOT NULL,
  `serving_institution_id` int NOT NULL DEFAULT 1,
  `requested_blood_group` enum('O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE') NOT NULL,
  `quantity` int NOT NULL DEFAULT 1,
  `urgency` enum('ROUTINE','URGENT','CRITICAL') NOT NULL DEFAULT 'ROUTINE',
  `status` enum('PENDING','FULFILLED') NOT NULL DEFAULT 'PENDING',
  `request_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `patient_ref` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Administrator-controlled alert thresholds
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Transactions Table
CREATE TABLE IF NOT EXISTS `transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `unit_id` int NOT NULL,
  `request_id` int NOT NULL,
  `allocation_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `quantity` int NOT NULL DEFAULT 1,
  `institution_id` int NOT NULL,
  PRIMARY KEY (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_id` int DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Demo Seed Accounts (Password for all demo accounts: Staff@123)
-- Password bcrypt hash for 'Staff@123'
INSERT INTO `users` (`username`, `full_name`, `email`, `phone`, `password_hash`, `role`, `blood_group`, `institution_id`)
VALUES 
  ('staffdemo', 'Demo Staff', 'staff@bloodbank.pk', '03001234567', '$2y$10$4OdqEQXMrw5gotQewF4HB.LfTuk4P1pXIFZSBrzVbxbKdsGNJUlJq', 'STAFF', 'O+', 'FUSST-BB')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);
