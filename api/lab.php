<?php
// api/lab.php - Laboratory Testing & Clearance Workflow (FR-06)
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$currentUser = requireAuth(['LAB_TECHNICIAN', 'STAFF', 'ADMIN']);
$userId = (int)($currentUser['userId'] ?? $currentUser['user_id'] ?? 0);
$institutionId = requireInventoryInstitutionId($currentUser);
$institutionCodes = userInstitutionCodes($currentUser);

$conn = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$data = getRequestData();
$action = $data['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// 1. GET: Fetch Quarantined Units Awaiting Lab Clearance
// =========================================================================
if ($method === 'GET' && ($action === 'quarantine_queue' || empty($action))) {
    $groupFilter = trim($_GET['blood_group'] ?? '');
    $q = trim($_GET['q'] ?? '');

    $sql = "SELECT u.unit_id, u.donor_id, u.blood_group, u.collection_date, u.expiry_date, u.status, u.institution_id,
                   d.name AS donor_name, d.contact_details AS donor_contact
            FROM blood_units u
            LEFT JOIN donors d ON u.donor_id = d.donor_id AND d.managed_by IN (SELECT user_id FROM users WHERE institution_id IN (?, ?))
            WHERE u.status = 'QUARANTINED' AND u.institution_id = ?";
    
    $params = [$institutionCodes[0], $institutionCodes[1], $institutionId];
    $types = "ssi";

    if (!empty($groupFilter)) {
        $sql .= " AND u.blood_group = ?";
        $params[] = toDbBloodGroup($groupFilter);
        $types .= "s";
    }

    if (!empty($q)) {
        $sql .= " AND (d.name LIKE ? OR u.unit_id = ?)";
        $searchTerm = "%$q%";
        $intId = intval($q);
        $params[] = $searchTerm;
        $params[] = $intId;
        $types .= "si";
    }

    $sql .= " ORDER BY u.collection_date ASC, u.unit_id ASC";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $quarantinedUnits = [];
        $today = new DateTime('today');

        while ($row = $res->fetch_assoc()) {
            $expDate = new DateTime($row['expiry_date']);
            $diff = $today->diff($expDate);
            $daysToExpiry = $diff->invert ? -$diff->days : $diff->days;

            $colDate = new DateTime($row['collection_date']);
            $colDiff = $colDate->diff($today);
            $daysInQuarantine = $colDiff->days;

            $quarantinedUnits[] = [
                'unit_id'            => (int)$row['unit_id'],
                'donor_id'           => $row['donor_id'] ? (int)$row['donor_id'] : null,
                'donor_name'         => $row['donor_name'] ?: 'Voluntary Donor',
                'donor_contact'      => $row['donor_contact'],
                'blood_group_db'     => $row['blood_group'],
                'blood_group'        => toDisplayBloodGroup($row['blood_group']),
                'collection_date'    => $row['collection_date'],
                'expiry_date'        => $row['expiry_date'],
                'status'             => $row['status'],
                'days_to_expiry'     => $daysToExpiry,
                'days_in_quarantine' => $daysInQuarantine
            ];
        }
        $stmt->close();

        // Lab metrics
        $quarantineCount = count($quarantinedUnits);
        
        $availableCount = 0;
        $avRes = $conn->prepare("SELECT COUNT(*) FROM blood_units WHERE status = 'AVAILABLE' AND institution_id = ?");
        if ($avRes) {
            $avRes->bind_param('i', $institutionId);
            $avRes->execute();
            $avCount = $avRes->get_result()->fetch_array();
            $availableCount = $avCount ? (int)$avCount[0] : 0;
            $avRes->close();
        }

        $clearedToday = 0;
        $clRes = $conn->prepare("SELECT COUNT(*) FROM audit_logs a JOIN blood_units u ON u.unit_id = a.entity_id WHERE a.action = 'LAB_UNIT_CLEARED' AND DATE(a.timestamp) = CURDATE() AND u.institution_id = ?");
        if ($clRes) {
            $clRes->bind_param('i', $institutionId);
            $clRes->execute();
            $clearedToday = (int)$clRes->get_result()->fetch_array()[0];
            $clRes->close();
        }

        $rejectedTotal = 0;
        $rjRes = $conn->prepare("SELECT COUNT(*) FROM audit_logs a JOIN blood_units u ON u.unit_id = a.entity_id WHERE a.action = 'LAB_UNIT_REJECTED' AND u.institution_id = ?");
        if ($rjRes) {
            $rjRes->bind_param('i', $institutionId);
            $rjRes->execute();
            $rejectedTotal = (int)$rjRes->get_result()->fetch_array()[0];
            $rjRes->close();
        }

        jsonResponse([
            'success' => true,
            'quarantine_queue' => $quarantinedUnits,
            'metrics' => [
                'quarantine_count' => $quarantineCount,
                'available_count'  => $availableCount,
                'cleared_today'    => $clearedToday,
                'rejected_total'   => $rejectedTotal
            ]
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Query error: ' . $conn->error], 500);
    }
}

// =========================================================================
// 2. POST: Approve & Clear Unit for Patient Issue (FR-06)
// =========================================================================
if ($action === 'clear_unit') {
    $rawUnitId = $data['unit_id'] ?? null;
    if (!isValidIntegerRange($rawUnitId, 1, PHP_INT_MAX)) {
        jsonResponse(['success' => false, 'message' => 'A valid unit ID is required.'], 400);
    }
    $unitId = (int)$rawUnitId;
    $notes = trim((string)($data['clearance_notes'] ?? ''));
    $screeningFields = ['screen_hiv', 'screen_hbv', 'screen_hcv', 'screen_syphilis', 'screen_malaria', 'verify_abo'];
    foreach ($screeningFields as $field) {
        if (!in_array($data[$field] ?? null, [true, 1, '1', 'true'], true)) {
            jsonResponse(['success' => false, 'message' => 'All required infection screens and ABO/Rh verification must be confirmed before clearance.'], 422);
        }
    }

    if ($notes === '' || strlen($notes) > 500) {
        jsonResponse(['success' => false, 'message' => 'Clearance notes are required and must be 500 characters or fewer.'], 422);
    }

    // Verify unit is QUARANTINED
    $check = $conn->prepare("SELECT unit_id, blood_group, status, expiry_date FROM blood_units WHERE unit_id = ? AND institution_id = ? LIMIT 1");
    if (!$check) jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
    $check->bind_param("ii", $unitId, $institutionId);
    $check->execute();
    $cRes = $check->get_result();
    if (!$cRes || $cRes->num_rows === 0) {
        $check->close();
        jsonResponse(['success' => false, 'message' => 'Blood unit not found.'], 404);
    }
    $unit = $cRes->fetch_assoc();
    $check->close();

    if ($unit['status'] !== 'QUARANTINED') {
        jsonResponse(['success' => false, 'message' => "Unit #$unitId cannot be cleared because its status is {$unit['status']} (Must be QUARANTINED)."], 400);
    }

    // Transition status to AVAILABLE
    $up = $conn->prepare("UPDATE blood_units SET status = 'AVAILABLE' WHERE unit_id = ? AND institution_id = ?");
    if (!$up) jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    $up->bind_param("ii", $unitId, $institutionId);
    
    if (!$up->execute()) {
        $err = $up->error;
        $up->close();
        jsonResponse(['success' => false, 'message' => 'Failed to clear unit: ' . $err], 500);
    }
    $up->close();

    // Log clearance in audit_logs
    $bloodDisp = toDisplayBloodGroup($unit['blood_group']);
    logAudit($conn, $userId, 'LAB_UNIT_CLEARED', $unitId, "Unit #$unitId ($bloodDisp) passed lab clearance and is now AVAILABLE. Notes: $notes");

    jsonResponse([
        'success' => true,
        'message' => "Unit #$unitId ($bloodDisp) verified and cleared! It is now AVAILABLE in active inventory for allocation.",
        'unit_id' => $unitId
    ]);
}

// =========================================================================
// 3. POST: Reject Biohazard / Contaminated Unit
// =========================================================================
if ($action === 'reject_unit') {
    $rawUnitId = $data['unit_id'] ?? null;
    if (!isValidIntegerRange($rawUnitId, 1, PHP_INT_MAX)) {
        jsonResponse(['success' => false, 'message' => 'A valid unit ID is required.'], 400);
    }
    $unitId = (int)$rawUnitId;
    $reason = trim((string)($data['rejection_reason'] ?? ''));
    $allowedReasons = [
        'Reactive: Hepatitis B Surface Antigen (HBsAg)', 'Reactive: Hepatitis C Virus (HCV)',
        'Reactive: HIV 1/2 Screening', 'Reactive: Syphilis Serology',
        'Positive: Malaria Parasite', 'Gross Hemolysis / Fibrin Clotting',
        'Bag Defect / Cold Chain Temperature Breach'
    ];

    if (!in_array($reason, $allowedReasons, true) || !in_array($data['disposal_confirmed'] ?? null, [true, 1, '1', 'true'], true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid rejection reason and confirm the disposal protocol.'], 422);
    }

    $check = $conn->prepare("SELECT unit_id, blood_group, status FROM blood_units WHERE unit_id = ? AND institution_id = ? LIMIT 1");
    if (!$check) jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
    $check->bind_param("ii", $unitId, $institutionId);
    $check->execute();
    $cRes = $check->get_result();
    if (!$cRes || $cRes->num_rows === 0) {
        $check->close();
        jsonResponse(['success' => false, 'message' => 'Blood unit not found.'], 404);
    }
    $unit = $cRes->fetch_assoc();
    $check->close();

    // Set to EXPIRED/discarded
    $up = $conn->prepare("UPDATE blood_units SET status = 'EXPIRED' WHERE unit_id = ? AND institution_id = ?");
    if (!$up) jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    $up->bind_param("ii", $unitId, $institutionId);
    
    if (!$up->execute()) {
        $err = $up->error;
        $up->close();
        jsonResponse(['success' => false, 'message' => 'Failed to reject unit: ' . $err], 500);
    }
    $up->close();

    $bloodDisp = toDisplayBloodGroup($unit['blood_group']);
    logAudit($conn, $userId, 'LAB_UNIT_REJECTED', $unitId, "Unit #$unitId ($bloodDisp) rejected by lab. Reason: $reason");

    jsonResponse([
        'success' => true,
        'message' => "Unit #$unitId marked as biohazard rejection. Discard protocol logged.",
        'unit_id' => $unitId
    ]);
}

// =========================================================================
// 4. GET: Recent Lab Clearance & Rejection Activity
// =========================================================================
if ($method === 'GET' && $action === 'recent_activity') {
    $sql = "SELECT a.log_id, a.user_id, a.action, a.entity_id, a.timestamp, a.details,
                   u.full_name AS technician_name, u.role AS technician_role
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.action IN ('LAB_UNIT_CLEARED', 'LAB_UNIT_REJECTED')
            AND EXISTS (SELECT 1 FROM blood_units u WHERE u.unit_id = a.entity_id AND u.institution_id = ?)
            ORDER BY a.timestamp DESC
            LIMIT 20";

    $activityStmt = $conn->prepare($sql);
    if ($activityStmt) {
        $activityStmt->bind_param('i', $institutionId);
        $activityStmt->execute();
        $res = $activityStmt->get_result();
    } else {
        $res = false;
    }
    $activity = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $activity[] = [
                'log_id'          => (int)$row['log_id'],
                'action'          => $row['action'],
                'unit_id'         => $row['entity_id'] ? (int)$row['entity_id'] : null,
                'technician_name' => $row['technician_name'] ?: 'Lab Technician',
                'timestamp'       => $row['timestamp'],
                'details'         => $row['details']
            ];
        }
    }
    if ($activityStmt) $activityStmt->close();

    jsonResponse(['success' => true, 'activity' => $activity]);
}

jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
