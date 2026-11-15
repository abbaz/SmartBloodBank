<?php
// api/inventory.php - Blood Unit Inventory Management (FR-05, FR-07)
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
$data = getRequestData();
$action = $data['action'] ?? $_GET['action'] ?? '';
$currentUser = requireAuth(['STAFF', 'ADMIN', 'LAB_TECHNICIAN']);
$userId = (int)($currentUser['userId'] ?? $currentUser['user_id'] ?? 0);
$institutionId = requireInventoryInstitutionId($currentUser);
$institutionCodes = userInstitutionCodes($currentUser);
$conn = getDb();

// GET: Fetch inventory units with calculated expiry statuses and summary metrics
if ($method === 'GET') {
    $statusFilter = trim($_GET['status'] ?? '');
    $groupFilter  = trim($_GET['blood_group'] ?? '');
    $nearExpiryOnly = !empty($_GET['near_expiry']);

    $sql = "SELECT u.unit_id, u.donor_id, u.blood_group, u.collection_date, u.expiry_date, u.status, u.institution_id,
                   EXISTS(SELECT 1 FROM transactions tx WHERE tx.unit_id = u.unit_id) AS has_transaction,
                   d.name AS donor_name, d.contact_details AS donor_contact
            FROM blood_units u
            LEFT JOIN donors d ON u.donor_id = d.donor_id AND d.managed_by IN (SELECT user_id FROM users WHERE institution_id IN (?, ?))
            WHERE u.institution_id = ?";
    $params = [$institutionCodes[0], $institutionCodes[1], $institutionId];
    $types = "ssi";

    if (!empty($statusFilter)) {
        $sql .= " AND u.status = ?";
        $params[] = strtoupper($statusFilter);
        $types .= "s";
    }

    if (!empty($groupFilter)) {
        $sql .= " AND u.blood_group = ?";
        $params[] = toDbBloodGroup($groupFilter);
        $types .= "s";
    }

    if ($nearExpiryOnly) {
        $sql .= " AND u.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND u.status != 'EXPIRED'";
    }

    $sql .= " ORDER BY u.expiry_date ASC, u.unit_id DESC";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $units = [];
        $today = new DateTime('today');

        while ($row = $res->fetch_assoc()) {
            $expDate = new DateTime($row['expiry_date']);
            $diff = $today->diff($expDate);
            $daysToExpiry = $diff->invert ? -$diff->days : $diff->days;
            $isExpired = $daysToExpiry < 0;
            $isNearExpiry = !$isExpired && $daysToExpiry <= 7;

            // Auto-update status to EXPIRED if past expiry date and still available/quarantined
            if ($isExpired && in_array($row['status'], ['AVAILABLE', 'QUARANTINED', 'RESERVED'], true)) {
                $conn->begin_transaction();
                $up = $conn->prepare("UPDATE blood_units SET status = 'EXPIRED' WHERE unit_id = ? AND institution_id = ? AND status = ?");
                if ($up) {
                    $oldStatus = $row['status'];
                    $up->bind_param("iis", $row['unit_id'], $institutionId, $oldStatus);
                    $changed = $up->execute() && $up->affected_rows === 1;
                    $up->close();
                    if ($changed && logAudit($conn, $userId, 'UNIT_AUTO_EXPIRED', (int)$row['unit_id'], "Unit #{$row['unit_id']} automatically marked EXPIRED after its expiry date.")) {
                        $conn->commit();
                        $row['status'] = 'EXPIRED';
                    } else {
                        $conn->rollback();
                    }
                } else {
                    $conn->rollback();
                }
            }

            $units[] = [
                'unit_id'         => (int)$row['unit_id'],
                'donor_id'        => $row['donor_id'] ? (int)$row['donor_id'] : null,
                'donor_name'      => $row['donor_name'] ?: 'Anonymous / External',
                'donor_contact'   => $row['donor_contact'],
                'blood_group_db'  => $row['blood_group'],
                'blood_group'     => toDisplayBloodGroup($row['blood_group']),
                'collection_date' => $row['collection_date'],
                'expiry_date'     => $row['expiry_date'],
                'status'          => $row['status'],
                'has_transaction' => (bool)$row['has_transaction'],
                'days_to_expiry'  => $daysToExpiry,
                'is_expired'      => $isExpired,
                'is_near_expiry'  => $isNearExpiry
            ];
        }
        $stmt->close();

        // Calculate summary counts across whole inventory
        $stats = [
            'total'       => 0,
            'available'   => 0,
            'quarantined' => 0,
            'reserved'    => 0,
            'issued'      => 0,
            'expired'     => 0,
            'near_expiry' => 0
        ];

        $statStmt = $conn->prepare(" 
            SELECT status, COUNT(*) AS cnt,
                   SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status != 'EXPIRED' THEN 1 ELSE 0 END) as near_exp
            FROM blood_units
            WHERE institution_id = ?
            GROUP BY status
        ");

        if ($statStmt) {
            $statStmt->bind_param('i', $institutionId);
            $statStmt->execute();
            $statRes = $statStmt->get_result();
            while ($s = $statRes->fetch_assoc()) {
                $st = strtolower($s['status']);
                if (isset($stats[$st])) {
                    $stats[$st] = (int)$s['cnt'];
                }
                $stats['total'] += (int)$s['cnt'];
                $stats['near_expiry'] += (int)$s['near_exp'];
            }
            $statStmt->close();
        }

        jsonResponse([
            'success' => true,
            'units'   => $units,
            'stats'   => $stats
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Query error: ' . $conn->error], 500);
    }
}

// Write operations require authenticated Staff or Admin.
requireAuth(['STAFF', 'ADMIN']);

// POST Action: Log Donation (FR-05)
if ($action === 'log_donation') {
    $rawDonorId     = $data['donor_id'] ?? null;
    $rawAppointmentId = $data['appointment_id'] ?? null;
    if (!isValidIntegerRange($rawDonorId, 1, PHP_INT_MAX)) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid registered donor.'], 400);
    }
    $donorId        = (int)$rawDonorId;
    if ($rawAppointmentId !== null && $rawAppointmentId !== '' && !isValidIntegerRange($rawAppointmentId, 1, PHP_INT_MAX)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid donor appointment.'], 400);
    }
    $appointmentId  = $rawAppointmentId === null || $rawAppointmentId === '' ? null : (int)$rawAppointmentId;
    $collectionDate = trim((string)($data['collection_date'] ?? ''));
    $shelfLifeDays  = $data['shelf_life_days'] ?? null;
    $inputGroup     = trim($data['blood_group'] ?? '');

    if (!isDateNoLaterThanToday($collectionDate)) {
        jsonResponse(['success' => false, 'message' => 'Collection date must be a valid date no later than today.'], 422);
    }
    if (!isValidIntegerRange($shelfLifeDays, 21, 42)) {
        jsonResponse(['success' => false, 'message' => 'Shelf life must be a whole number from 21 to 42 days.'], 422);
    }
    if (!empty($inputGroup) && !in_array(toDbBloodGroup($inputGroup), ['O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE'], true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid blood group.'], 422);
    }

    // Verify donor exists and retrieve registered blood group
    $dCheck = $conn->prepare("SELECT d.name, d.blood_group, d.last_donation_date FROM donors d JOIN users manager ON d.managed_by = manager.user_id WHERE d.donor_id = ? AND manager.institution_id IN (?, ?) LIMIT 1");
    if (!$dCheck) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    $dCheck->bind_param("iss", $donorId, $institutionCodes[0], $institutionCodes[1]);
    $dCheck->execute();
    $dRes = $dCheck->get_result();
    if (!$dRes || $dRes->num_rows === 0) {
        $dCheck->close();
        jsonResponse(['success' => false, 'message' => 'Donor record not found.'], 404);
    }
    $donor = $dRes->fetch_assoc();
    $dCheck->close();

    if ($appointmentId !== null) {
        $appointmentCheck = $conn->prepare(
            "SELECT a.appointment_id FROM donation_appointments a JOIN donor_profiles p ON p.donor_id = a.donor_id " .
            "WHERE a.appointment_id = ? AND a.donor_id = ? AND a.facility_id = ? AND a.status = 'CONFIRMED' AND p.profile_status = 'VERIFIED' LIMIT 1 FOR UPDATE"
        );
        if (!$appointmentCheck) jsonResponse(['success' => false, 'message' => 'Could not verify the confirmed donor appointment.'], 500);
        $appointmentCheck->bind_param('iii', $appointmentId, $donorId, $institutionId);
        $appointmentCheck->execute();
        $appointmentFound = $appointmentCheck->get_result()->num_rows === 1;
        $appointmentCheck->close();
        if (!$appointmentFound) jsonResponse(['success' => false, 'message' => 'The confirmed appointment does not match this donor and facility.'], 409);
    }

    $bloodGroup = !empty($inputGroup) ? toDbBloodGroup($inputGroup) : $donor['blood_group'];

    // Calculate expiry date automatically: collection_date + shelfLifeDays
    $shelfLifeDays = (int)$shelfLifeDays;
    $colDt = new DateTime($collectionDate);
    $colDt->modify("+$shelfLifeDays days");
    $expiryDate = $colDt->format('Y-m-d');

    // New donations enter as QUARANTINED until tested by lab technician (FR-06)
    $status = 'QUARANTINED';

    $insert = $conn->prepare(
        "INSERT INTO blood_units (donor_id, blood_group, collection_date, expiry_date, status, institution_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$insert) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }

    $insert->bind_param("issssi", $donorId, $bloodGroup, $collectionDate, $expiryDate, $status, $institutionId);

    if (!$insert->execute()) {
        $err = $insert->error;
        $insert->close();
        jsonResponse(['success' => false, 'message' => 'Failed to log donation: ' . $err], 500);
    }

    $unitId = $insert->insert_id;
    $insert->close();

    // Update donor's last_donation_date
    $upDonor = $conn->prepare("UPDATE donors SET last_donation_date = ? WHERE donor_id = ?");
    if ($upDonor) {
        $upDonor->bind_param("si", $collectionDate, $donorId);
        $upDonor->execute();
        $upDonor->close();
    }

    if ($appointmentId !== null) {
        $complete = $conn->prepare("UPDATE donation_appointments SET status = 'COMPLETED', reviewed_by = ? WHERE appointment_id = ? AND donor_id = ? AND facility_id = ? AND status = 'CONFIRMED'");
        if (!$complete) jsonResponse(['success' => false, 'message' => 'Could not complete the linked appointment.'], 500);
        $complete->bind_param('iiii', $userId, $appointmentId, $donorId, $institutionId);
        if (!$complete->execute() || $complete->affected_rows !== 1) {
            $complete->close();
            jsonResponse(['success' => false, 'message' => 'Could not complete the linked appointment.'], 409);
        }
        $complete->close();
        logAudit($conn, $userId, 'DONATION_APPOINTMENT_COMPLETED', $appointmentId, "Staff recorded collection for appointment #{$appointmentId}; unit #{$unitId} entered quarantine.");
    }

    logAudit($conn, $userId, 'DONATION_LOGGED', $unitId, "Unit #$unitId from donor #{$donorId} ($bloodGroup). Status: QUARANTINED");

    jsonResponse([
        'success'     => true,
        'message'     => "Donation logged successfully! Unit #$unitId has been added as QUARANTINED awaiting lab test clearance.",
        'unit_id'     => $unitId,
        'expiry_date' => $expiryDate
    ], 201);
}

// POST Action: Permanently delete an unissued unit after recording its snapshot.
if ($action === 'delete') {
    $unitId = (int)($data['unit_id'] ?? 0);
    $reason = trim($data['reason'] ?? 'Deleted by authorized staff');
    if (!$unitId) jsonResponse(['success' => false, 'message' => 'Unit ID is required.'], 400);
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare("SELECT unit_id, donor_id, blood_group, collection_date, expiry_date, status FROM blood_units WHERE unit_id = ? AND institution_id = ? FOR UPDATE");
        $lock->bind_param('ii', $unitId, $institutionId); $lock->execute();
        $unit = $lock->get_result()->fetch_assoc(); $lock->close();
        if (!$unit) throw new RuntimeException('Unit not found in your facility.');
        if ($unit['status'] === 'ISSUED') throw new RuntimeException('Issued units cannot be deleted.');
        $txCheck = $conn->prepare("SELECT transaction_id FROM transactions WHERE unit_id = ? LIMIT 1");
        $txCheck->bind_param('i', $unitId); $txCheck->execute();
        $hasTransaction = $txCheck->get_result()->num_rows > 0; $txCheck->close();
        if ($hasTransaction) throw new RuntimeException('A unit linked to a transfusion transaction cannot be deleted.');

        $snapshot = "Deleted unit #$unitId; donor_id={$unit['donor_id']}; group={$unit['blood_group']}; collection={$unit['collection_date']}; expiry={$unit['expiry_date']}; status={$unit['status']}; reason={$reason}";
        $delete = $conn->prepare("DELETE FROM blood_units WHERE unit_id = ? AND institution_id = ?");
        $delete->bind_param('ii', $unitId, $institutionId); $delete->execute();
        $deleted = $delete->affected_rows === 1; $delete->close();
        if (!$deleted || !logAudit($conn, $userId, 'INVENTORY_DELETED', $unitId, $snapshot)) {
            throw new RuntimeException('Deletion or audit recording failed; no changes were committed.');
        }
        $conn->commit();
        jsonResponse(['success' => true, 'message' => "Unit #$unitId deleted and its record retained in the audit trail."]);
    } catch (Throwable $e) {
        $conn->rollback();
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 409);
    }
}

// POST Action: Discard Unit (retains the unit in inventory history)
if ($action === 'discard') {
    $unitId = (int)($data['unit_id'] ?? 0);
    $reason = trim($data['reason'] ?? 'Discarded by staff');

    if (!$unitId) {
        jsonResponse(['success' => false, 'message' => 'Unit ID is required.'], 400);
    }

    // Set to EXPIRED or delete
    $stmt = $conn->prepare("UPDATE blood_units SET status = 'EXPIRED' WHERE unit_id = ? AND institution_id = ?");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    $stmt->bind_param("ii", $unitId, $institutionId);
    $stmt->execute();
    $stmt->close();

    logAudit($conn, $userId, 'UNIT_DISCARDED', $unitId, "Unit #$unitId marked EXPIRED. Reason: $reason");

    jsonResponse(['success' => true, 'message' => "Unit #$unitId marked as expired / discarded."]);
}

// POST Action: Edit Unit Record (FR-07)
if ($action === 'update') {
    $unitId     = (int)($data['unit_id'] ?? 0);
    $status     = strtoupper(trim($data['status'] ?? ''));
    $expiryDate = trim($data['expiry_date'] ?? '');

    $allowedStatus = ['QUARANTINED', 'AVAILABLE', 'RESERVED', 'ISSUED', 'EXPIRED'];

    if (!isValidIntegerRange($unitId, 1, PHP_INT_MAX) || !in_array($status, $allowedStatus, true) || !isValidDateValue($expiryDate)) {
        jsonResponse(['success' => false, 'message' => 'Valid Unit ID and Status are required.'], 400);
    }

    $stmt = $conn->prepare("UPDATE blood_units SET status = ?, expiry_date = ? WHERE unit_id = ? AND institution_id = ?");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    $stmt->bind_param("ssii", $status, $expiryDate, $unitId, $institutionId);
    $stmt->execute();
    $stmt->close();

    logAudit($conn, $userId, 'INVENTORY_UPDATED', $unitId, "Unit #$unitId updated to status: $status");

    jsonResponse(['success' => true, 'message' => "Unit #$unitId updated successfully."]);
}

jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
