-- Repeatable demo data for Smart Blood Bank.
-- In phpMyAdmin, first select the database used by api/db.php
-- (usually `smart blood bank` on this installation), then import this file.
-- All created records are marked DEMO. This script does not delete or update
-- existing application records.

SET @demo_facility_id := 1;
SET @demo_hospital_institution := 'HOSP-DEMO';
SET @demo_password_hash := '$2y$12$.y/w8mQlnu.leD7A0YDCzuuMsPp8xJ8wC447I6WcFFyuYkGRghifC'; -- HospitalDemo!2026

CREATE TABLE IF NOT EXISTS demo_seed_metadata (
    setting_key varchar(50) NOT NULL PRIMARY KEY,
    setting_value varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO demo_seed_metadata (setting_key, setting_value)
VALUES ('anchor_date', DATE_FORMAT(CURDATE(), '%Y-%m-%d'));
SELECT setting_value INTO @demo_anchor_date FROM demo_seed_metadata WHERE setting_key = 'anchor_date';

-- Select an existing staff/admin account for donor ownership.
SELECT user_id INTO @demo_staff_id
FROM users
WHERE role IN ('STAFF','ADMIN')
  AND institution_id IN ('FUSST-BB', '1')
ORDER BY (institution_id = '1') DESC, user_id ASC
LIMIT 1;

-- Create a clearly labeled requester account for the demo queue if needed.
INSERT INTO users (username, full_name, email, phone, password_hash, role, blood_group, institution_id)
SELECT 'hospital_demo', 'DEMO Hospital Requester', 'hospital.demo@example.test', '03000000003',
       @demo_password_hash, 'HOSPITAL_REQUESTER', 'O+', @demo_hospital_institution
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'hospital_demo');

-- 48 synthetic donor profiles, each visibly labeled as demo data.
INSERT INTO donors (managed_by, name, contact_details, blood_group, last_donation_date)
WITH RECURSIVE seq(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 48
)
SELECT @demo_staff_id,
       CONCAT('DEMO Donor ', LPAD(seq.n, 3, '0')),
       'DEMO ONLY - no real contact details',
       CASE MOD(seq.n - 1, 8)
         WHEN 0 THEN 'O_NEGATIVE' WHEN 1 THEN 'O_POSITIVE'
         WHEN 2 THEN 'A_NEGATIVE' WHEN 3 THEN 'A_POSITIVE'
         WHEN 4 THEN 'B_NEGATIVE' WHEN 5 THEN 'B_POSITIVE'
         WHEN 6 THEN 'AB_NEGATIVE' ELSE 'AB_POSITIVE' END,
       DATE_SUB(@demo_anchor_date, INTERVAL (70 + MOD(seq.n, 120)) DAY)
FROM seq
WHERE @demo_staff_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM donors d
      WHERE d.managed_by = @demo_staff_id
        AND d.name = CONCAT('DEMO Donor ', LPAD(seq.n, 3, '0'))
  );

-- 96 synthetic blood units across all groups and life-cycle states.
-- Expiry dates are exactly 35 days after collection; expired entries are historical.
INSERT INTO blood_units (donor_id, blood_group, collection_date, expiry_date, status, institution_id)
WITH RECURSIVE seq(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 96
), rows_to_add AS (
    SELECT seq.n,
           CONCAT('DEMO Donor ', LPAD(FLOOR((seq.n - 1) / 2) + 1, 3, '0')) AS donor_name,
           CASE MOD(FLOOR((seq.n - 1) / 2), 8)
             WHEN 0 THEN 'O_NEGATIVE' WHEN 1 THEN 'O_POSITIVE'
             WHEN 2 THEN 'A_NEGATIVE' WHEN 3 THEN 'A_POSITIVE'
             WHEN 4 THEN 'B_NEGATIVE' WHEN 5 THEN 'B_POSITIVE'
             WHEN 6 THEN 'AB_NEGATIVE' ELSE 'AB_POSITIVE' END AS blood_group,
           (MOD(seq.n * 7, 51) - 15) AS days_to_expiry
    FROM seq
)
SELECT d.donor_id, rows_to_add.blood_group,
       DATE_SUB(@demo_anchor_date, INTERVAL (35 - rows_to_add.days_to_expiry) DAY),
       DATE_ADD(DATE_SUB(@demo_anchor_date, INTERVAL (35 - rows_to_add.days_to_expiry) DAY), INTERVAL 35 DAY),
       CASE
         WHEN rows_to_add.days_to_expiry < 0 THEN 'EXPIRED'
         WHEN MOD(rows_to_add.n, 11) = 0 THEN 'QUARANTINED'
         WHEN MOD(rows_to_add.n, 13) = 0 THEN 'RESERVED'
         ELSE 'AVAILABLE'
       END,
       @demo_facility_id
FROM rows_to_add
JOIN donors d ON d.managed_by = @demo_staff_id AND d.name = rows_to_add.donor_name
WHERE @demo_staff_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM blood_units u
      WHERE u.donor_id = d.donor_id
        AND u.collection_date = DATE_SUB(@demo_anchor_date, INTERVAL (35 - rows_to_add.days_to_expiry) DAY)
        AND u.expiry_date = DATE_ADD(DATE_SUB(@demo_anchor_date, INTERVAL (35 - rows_to_add.days_to_expiry) DAY), INTERVAL 35 DAY)
        AND u.institution_id = @demo_facility_id
  );

-- 30 synthetic clinical requests (no real patient identifiers).
INSERT INTO blood_requests (submitted_by, requested_blood_group, quantity, urgency, status, request_date, patient_ref)
WITH RECURSIVE seq(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 30
)
SELECT h.user_id,
       CASE MOD(seq.n - 1, 8)
         WHEN 0 THEN 'O_NEGATIVE' WHEN 1 THEN 'O_POSITIVE'
         WHEN 2 THEN 'A_NEGATIVE' WHEN 3 THEN 'A_POSITIVE'
         WHEN 4 THEN 'B_NEGATIVE' WHEN 5 THEN 'B_POSITIVE'
         WHEN 6 THEN 'AB_NEGATIVE' ELSE 'AB_POSITIVE' END,
       1 + MOD(seq.n, 3),
       CASE WHEN MOD(seq.n, 10) = 0 THEN 'CRITICAL'
            WHEN MOD(seq.n, 4) = 0 THEN 'URGENT' ELSE 'ROUTINE' END,
       'PENDING', DATE_SUB(CONCAT(@demo_anchor_date, ' 12:00:00'), INTERVAL MOD(seq.n, 20) DAY),
       CONCAT('DEMO-REQUEST-', LPAD(seq.n, 3, '0'))
FROM seq
JOIN users h ON h.username = 'hospital_demo'
WHERE NOT EXISTS (
    SELECT 1 FROM blood_requests r
    WHERE r.submitted_by = h.user_id
      AND r.patient_ref = CONCAT('DEMO-REQUEST-', LPAD(seq.n, 3, '0'))
);

-- A single audit marker helps distinguish seed imports in the ledger.
INSERT INTO audit_logs (user_id, action, entity_id, details)
SELECT @demo_staff_id, 'DEMO_DATA_SEEDED', NULL,
       'Repeatable demo import: synthetic donors, inventory units, and hospital requests.'
WHERE @demo_staff_id IS NOT NULL;

-- Sign-in for the synthetic requester account: hospital_demo / HospitalDemo!2026
-- Change the password before using this account outside a classroom demo.
SELECT @demo_staff_id AS demo_staff_manager_id, @demo_facility_id AS demo_inventory_facility_id,
       @demo_anchor_date AS seed_anchor_date;
SELECT status, COUNT(*) AS demo_unit_count
FROM blood_units
WHERE institution_id = @demo_facility_id
  AND donor_id IN (SELECT donor_id FROM donors WHERE name LIKE 'DEMO Donor %')
GROUP BY status;
SELECT COUNT(*) AS demo_pending_request_count
FROM blood_requests
WHERE patient_ref LIKE 'DEMO-REQUEST-%';
