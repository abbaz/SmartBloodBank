-- Local classroom demo account reset for SmartBloodBank.
-- Import into the configured Smart Blood Bank database after the app has run
-- once so api/db.php can apply current schema migrations.
-- Existing user rows and all linked history are retained. Old logins are
-- revoked by replacing their credentials and setting approval_status=REJECTED.
-- Exactly one active ADMIN is created by this script.

SET @revoked_password_hash := '$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG';

START TRANSACTION;

UPDATE users
SET email = CONCAT('disabled+', user_id, '@invalid.local'),
    phone = NULL,
    password_hash = @revoked_password_hash,
    role = 'USER',
    approval_status = 'REJECTED',
    institution_verified = 0,
    must_change_password = 1;

INSERT INTO users
  (username, full_name, email, phone, password_hash, must_change_password,
   role, blood_group, institution_id, institution_verified, approval_status)
VALUES
  ('admin_demo', 'Demo Administrator', 'admin.demo@example.test', '03000000001', '$2y$12$qimWnEQxFVcuEb5LrM5gAO36DibfwmG33ZSIPf8ygUWU6zNxjyJ1e', 0, 'ADMIN', 'O+', 'FUSST-BB', 1, 'APPROVED'),
  ('staff_demo', 'Demo Blood Bank Staff', 'staff.demo@example.test', '03000000002', '$2y$12$L1QO/9O9yoB23tiCFgKZROgPvTB2QfPp7ffFC5IuoTzqTOs98EuKi', 0, 'STAFF', 'A+', 'FUSST-BB', 1, 'APPROVED'),
  ('hospital_demo', 'Demo Hospital Requester', 'hospital.demo@example.test', '03000000003', '$2y$12$.y/w8mQlnu.leD7A0YDCzuuMsPp8xJ8wC447I6WcFFyuYkGRghifC', 0, 'HOSPITAL_REQUESTER', 'O+', 'HOSP-DEMO', 1, 'APPROVED'),
  ('lab_demo', 'Demo Lab Technician', 'lab.demo@example.test', '03000000004', '$2y$12$QgYDXvIWjbJeDJB.UUCMsehEB.0vUsQ5ZXCBLibyzFSRqmIKWCRRS', 0, 'LAB_TECHNICIAN', 'B+', 'FUSST-BB', 1, 'APPROVED'),
  ('donor_demo', 'Demo Donor', 'donor.demo@example.test', '03000000005', '$2y$12$n.kr7CA0UiVAAJH4pdbeUek8DmseulX5kHMn1dZMjDNh3qTzlrDqK', 0, 'USER', 'O+', '1', 0, 'APPROVED')
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name), email = VALUES(email), phone = VALUES(phone),
  password_hash = VALUES(password_hash), must_change_password = VALUES(must_change_password),
  role = VALUES(role), blood_group = VALUES(blood_group),
  institution_id = VALUES(institution_id), institution_verified = VALUES(institution_verified),
  approval_status = VALUES(approval_status);

INSERT INTO donor_profiles (user_id, facility_id, blood_group, profile_status, consent_at)
SELECT user_id, 1, 'O+', 'PENDING', NOW()
FROM users WHERE username = 'donor_demo'
ON DUPLICATE KEY UPDATE
  facility_id = VALUES(facility_id), blood_group = VALUES(blood_group),
  profile_status = 'PENDING', consent_at = VALUES(consent_at), donor_id = NULL,
  reviewed_by = NULL, reviewed_at = NULL;

INSERT INTO audit_logs (user_id, action, entity_id, details)
SELECT user_id, 'DEMO_ACCOUNT_RESET', NULL,
       'Local demo credentials reset; previous user logins revoked and historical rows retained.'
FROM users WHERE username = 'admin_demo';

COMMIT;

-- Verify only the seeded administrator is active:
SELECT user_id, username, role, approval_status
FROM users
WHERE role = 'ADMIN' AND approval_status = 'APPROVED';
