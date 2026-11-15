<?php
// api/stats.php - Inventory & Request Statistics for Home Dashboard
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$currentUser = requireAuth(['ADMIN', 'STAFF', 'LAB_TECHNICIAN', 'HOSPITAL_REQUESTER']);

try {
    $conn = getDb();
    $userRole = $currentUser['role'] ?? '';
    $inventoryInstitutionId = in_array($userRole, ['STAFF', 'ADMIN', 'LAB_TECHNICIAN'], true)
        ? requireInventoryInstitutionId($currentUser)
        : null;
    $institutionCodes = $inventoryInstitutionId !== null ? userInstitutionCodes($currentUser) : userInstitutionCodes($currentUser);

    // Total available units
    $totalUnits = 0;
    if ($inventoryInstitutionId !== null) {
        $res = $conn->prepare("SELECT COUNT(*) FROM blood_units WHERE status = 'AVAILABLE' AND institution_id = ?");
        if (!$res) throw new RuntimeException('Could not prepare scoped inventory count.');
        $res->bind_param('i', $inventoryInstitutionId);
        $res->execute();
        $totalUnits = (int)$res->get_result()->fetch_array()[0];
        $res->close();
    }

    // Near-expiry warning window is configurable by an administrator.
    $nearExpiryDays = 7;
    $nearExpiry = 0;
    $windowRes = false;
    if ($inventoryInstitutionId !== null) {
        $nearKey = 'threshold_' . $inventoryInstitutionId . '_NEAR_EXPIRY_DAYS';
        $windowStmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $windowStmt->bind_param('s', $nearKey); $windowStmt->execute(); $windowRes = $windowStmt->get_result();
    }
    if ($windowRes && $windowRes->num_rows) $nearExpiryDays = min(60, max(1, (int)$windowRes->fetch_assoc()['setting_value']));
    if (isset($windowStmt)) $windowStmt->close();
    if ($inventoryInstitutionId !== null) {
        $resExp = $conn->prepare("SELECT COUNT(*) FROM blood_units WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND status IN ('AVAILABLE','QUARANTINED','RESERVED') AND institution_id = ?");
        $resExp->bind_param('ii', $nearExpiryDays, $inventoryInstitutionId);
        $resExp->execute();
        $nearExpiry = (int)$resExp->get_result()->fetch_array()[0];
        $resExp->close();
    }
    $groupStock = [];
    $nearExpiryUnits = [];
    if (in_array($userRole, ['STAFF', 'ADMIN', 'LAB_TECHNICIAN'], true)) {
        $groups = ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'];
        foreach ($groups as $group) {
            $dbGroup = toDbBloodGroup($group);
            $stmt = $conn->prepare("SELECT COUNT(*) AS qty FROM blood_units WHERE blood_group = ? AND status = 'AVAILABLE' AND institution_id = ?");
            $stmt->bind_param('si', $dbGroup, $inventoryInstitutionId);
            $stmt->execute();
            $groupStock[$group] = (int)$stmt->get_result()->fetch_assoc()['qty'];
            $stmt->close();
        }
        $expiryStmt = $conn->prepare("SELECT unit_id, blood_group, expiry_date FROM blood_units WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND status IN ('AVAILABLE','QUARANTINED','RESERVED') AND institution_id = ? ORDER BY expiry_date ASC LIMIT 100");
        $expiryStmt->bind_param('ii', $nearExpiryDays, $inventoryInstitutionId);
        $expiryStmt->execute();
        $expiryRes = $expiryStmt->get_result();
        while ($row = $expiryRes->fetch_assoc()) {
            $nearExpiryUnits[] = ['unit_id' => (int)$row['unit_id'], 'blood_group' => toDisplayBloodGroup($row['blood_group']), 'expiry_date' => $row['expiry_date']];
        }
        $expiryStmt->close();
    }

    // Pending requests
    $pendingRequests = 0;
    if ($inventoryInstitutionId !== null) {
        $resReq = $conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE status = 'PENDING' AND serving_institution_id = ?");
        $resReq->bind_param('i', $inventoryInstitutionId);
    } else {
        $requesterId = (int)($currentUser['userId'] ?? $currentUser['user_id'] ?? 0);
        if (!empty($currentUser['institutionVerified'])) {
            $userInstitution = (string)($currentUser['institutionId'] ?? $currentUser['institution_id'] ?? '');
            $resReq = $conn->prepare("SELECT COUNT(*) FROM blood_requests r JOIN users u ON u.user_id = r.submitted_by WHERE r.status = 'PENDING' AND (r.submitted_by = ? OR u.institution_id = ?)");
            $resReq->bind_param('is', $requesterId, $userInstitution);
        } else {
            $resReq = $conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE status = 'PENDING' AND submitted_by = ?");
            $resReq->bind_param('i', $requesterId);
        }
    }
    if ($resReq) {
        $resReq->execute();
        $pendingRequests = (int)$resReq->get_result()->fetch_array()[0];
        $resReq->close();
    }

    // Total registered users
    $totalUsers = 0;
    if ($inventoryInstitutionId !== null) {
        $resUsers = $conn->prepare("SELECT COUNT(*) FROM users WHERE institution_id IN (?, ?)");
        $resUsers->bind_param('ss', $institutionCodes[0], $institutionCodes[1]);
    } else {
        $userInstitution = (string)($currentUser['institutionId'] ?? $currentUser['institution_id'] ?? '');
        $resUsers = $conn->prepare("SELECT COUNT(*) FROM users WHERE institution_id = ?");
        $resUsers->bind_param('s', $userInstitution);
    }
    $resUsers->execute(); $totalUsers = (int)$resUsers->get_result()->fetch_array()[0]; $resUsers->close();

    // Total registered donors
    $totalDonors = 0;
    if ($inventoryInstitutionId !== null) {
        $resDonors = $conn->prepare("SELECT COUNT(*) FROM donors d JOIN users manager ON d.managed_by = manager.user_id WHERE manager.institution_id IN (?, ?)");
        $resDonors->bind_param('ss', $institutionCodes[0], $institutionCodes[1]);
        $resDonors->execute();
        $totalDonors = (int)$resDonors->get_result()->fetch_array()[0];
        $resDonors->close();
    }

    jsonResponse([
        'success'           => true,
        'units_in_stock'    => $totalUnits,
        'near_expiry'       => $nearExpiry,
        'near_expiry_days'  => $nearExpiryDays,
        'group_stock'       => $groupStock,
        'near_expiry_units' => $nearExpiryUnits,
        'pending_requests'  => $pendingRequests,
        'violations'        => 0,
        'total_users'       => $totalUsers,
        'total_donors'      => $totalDonors
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
