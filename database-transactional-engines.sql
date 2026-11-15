-- Import once into the active Smart Blood Bank database after taking a backup.
-- Core application writes and their audit events must commit or roll back together.
ALTER TABLE `users` ENGINE=InnoDB;
ALTER TABLE `contact_messages` ENGINE=InnoDB;
ALTER TABLE `donors` ENGINE=InnoDB;
ALTER TABLE `blood_units` ENGINE=InnoDB;
ALTER TABLE `blood_requests` ENGINE=InnoDB;
ALTER TABLE `transactions` ENGINE=InnoDB;
ALTER TABLE `audit_logs` ENGINE=InnoDB;
ALTER TABLE `settings` ENGINE=InnoDB;

-- Verify that all core tables now support transactions.
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'contact_messages', 'donors', 'blood_units', 'blood_requests', 'transactions', 'audit_logs', 'settings')
ORDER BY TABLE_NAME;
