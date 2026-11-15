-- Role applications and Admin approval workflow.
-- Existing accounts stay approved; the application auto-applies these changes too.
ALTER TABLE `users`
  MODIFY `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER','USER') NOT NULL,
  ADD COLUMN `approval_status` enum('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'APPROVED' AFTER `institution_verified`;

ALTER TABLE `contact_messages`
  MODIFY `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER','USER') NOT NULL;
