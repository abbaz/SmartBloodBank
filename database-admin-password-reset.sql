-- Run once on an existing Smart Blood Bank database to enable forced password rotation.
ALTER TABLE `users`
  ADD COLUMN `must_change_password` tinyint(1) NOT NULL DEFAULT 0 AFTER `password_hash`;
