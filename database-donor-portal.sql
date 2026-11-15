-- Donor portal linkage, profile review, and appointment workflow.
-- Run once against the Smart Blood Bank database after taking a backup.
ALTER TABLE `donors`
  ADD COLUMN `user_id` int DEFAULT NULL AFTER `managed_by`,
  ADD UNIQUE KEY `uq_donors_user_id` (`user_id`);

CREATE TABLE IF NOT EXISTS `donor_profiles` (
  `user_id` int NOT NULL,
  `facility_id` int NOT NULL,
  `blood_group` varchar(16) NOT NULL,
  `profile_status` enum('PENDING','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `consent_at` datetime NOT NULL,
  `donor_id` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_donor_profiles_donor_id` (`donor_id`),
  KEY `idx_donor_profiles_facility_status` (`facility_id`, `profile_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `donation_appointments` (
  `appointment_id` int NOT NULL AUTO_INCREMENT,
  `donor_id` int NOT NULL,
  `facility_id` int NOT NULL,
  `requested_date` date NOT NULL,
  `requested_time` time NOT NULL,
  `scheduled_date` date DEFAULT NULL,
  `scheduled_time` time DEFAULT NULL,
  `status` enum('PENDING','CONFIRMED','DECLINED','CANCELLED','COMPLETED','NO_SHOW') NOT NULL DEFAULT 'PENDING',
  `staff_note` varchar(300) DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`appointment_id`),
  KEY `idx_donation_appointments_facility_status` (`facility_id`, `status`, `requested_date`),
  KEY `idx_donation_appointments_donor` (`donor_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
