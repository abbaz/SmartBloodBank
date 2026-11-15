<?php
// api/audit.php - Regulatory Audit Trail & Compliance Ledger (FR-14, NFR-08)
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$currentUser = requireAuth(['STAFF', 'ADMIN']);
$userId = $currentUser['userId'] ?? 1;
$institutionId = requireInventoryInstitutionId($currentUser);
$institutionCodes = userInstitutionCodes($currentUser);

$conn = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =========================================================================
// 1. GET: Fetch Audit Logs with Filtering
// =========================================================================
if ($method === 'GET' && ($action === 'logs' || empty($action))) {
    $actionFilter = trim($_GET['action_type'] ?? '');
    $dateFrom     = trim($_GET['date_from'] ?? '');
    $dateTo       = trim($_GET['date_to'] ?? '');
    $q            = trim($_GET['q'] ?? '');

    $sql = "SELECT a.log_id, a.user_id, a.action, a.entity_id, a.timestamp, a.details,
                   u.full_name AS operator_name, u.username AS operator_username, u.role AS operator_role
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE (u.institution_id IN (?, ?)
              OR (a.action IN ('BLOOD_REQUEST_CREATED','HOSPITAL_ORDER_PLACED','REQUEST_FULFILLED','REQUEST_PARTIALLY_FULFILLED')
                  AND EXISTS (SELECT 1 FROM blood_requests r WHERE r.request_id = a.entity_id AND r.serving_institution_id = ?))
              OR (a.action IN ('DONATION_LOGGED','INVENTORY_UPDATED','INVENTORY_DELETED','UNIT_DISCARDED','UNIT_AUTO_EXPIRED','LAB_UNIT_CLEARED','LAB_UNIT_REJECTED')
                  AND EXISTS (SELECT 1 FROM blood_units bu WHERE bu.unit_id = a.entity_id AND bu.institution_id = ?)))";
    
    $params = [$institutionCodes[0], $institutionCodes[1], $institutionId, $institutionId];
    $types = "ssii";

    if (!empty($actionFilter)) {
        $sql .= " AND a.action = ?";
        $params[] = $actionFilter;
        $types .= "s";
    }

    if (!empty($dateFrom)) {
        $sql .= " AND DATE(a.timestamp) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }

    if (!empty($dateTo)) {
        $sql .= " AND DATE(a.timestamp) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }

    if (!empty($q)) {
        $sql .= " AND (a.details LIKE ? OR a.action LIKE ? OR u.full_name LIKE ? OR u.username LIKE ? OR a.entity_id = ?)";
        $searchTerm = "%$q%";
        $intVal = intval($q);
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $intVal;
        $types .= "ssssi";
    }

    $countSql = preg_replace('/^SELECT .*? FROM audit_logs/s', 'SELECT COUNT(*) AS total FROM audit_logs', $sql, 1);
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) jsonResponse(['success' => false, 'message' => 'Could not prepare audit count.'], 500);
    if (!empty($types)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $totalFiltered = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $pageSize = 100;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $totalPages = max(1, (int)ceil($totalFiltered / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;
    $sql .= " ORDER BY a.timestamp DESC, a.log_id DESC LIMIT ? OFFSET ?";
    $pageParams = $params;
    $pageParams[] = $pageSize;
    $pageParams[] = $offset;
    $pageTypes = $types . "ii";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($pageTypes, ...$pageParams);
        $stmt->execute();
        $res = $stmt->get_result();
        $logs = [];

        while ($row = $res->fetch_assoc()) {
            $logs[] = [
                'log_id'            => (int)$row['log_id'],
                'timestamp'         => $row['timestamp'],
                'action'            => $row['action'],
                'entity_id'         => $row['entity_id'] ? (int)$row['entity_id'] : null,
                'operator_name'     => $row['operator_name'] ?: ($row['operator_username'] ?: 'System Process'),
                'operator_role'     => $row['operator_role'] ?: 'SYSTEM',
                'details'           => $row['details']
            ];
        }
        $stmt->close();

        // Calculate audit metrics
        $totalLogs = 0;
        $metricStmt = $conn->prepare("SELECT COUNT(*) AS total_logs,
            SUM(action IN ('REQUEST_FULFILLED', 'REQUEST_PARTIALLY_FULFILLED', 'BLOOD_REQUEST_CREATED')) AS alloc_count,
            SUM(action IN ('LAB_UNIT_CLEARED', 'LAB_UNIT_REJECTED')) AS lab_count,
            SUM(action IN ('USER_REGISTERED', 'USER_LOGIN')) AS security_count
            FROM audit_logs a LEFT JOIN users u ON a.user_id = u.user_id
            WHERE (u.institution_id IN (?, ?)
              OR (a.action IN ('BLOOD_REQUEST_CREATED','HOSPITAL_ORDER_PLACED','REQUEST_FULFILLED','REQUEST_PARTIALLY_FULFILLED')
                  AND EXISTS (SELECT 1 FROM blood_requests r WHERE r.request_id = a.entity_id AND r.serving_institution_id = ?))
              OR (a.action IN ('DONATION_LOGGED','INVENTORY_UPDATED','INVENTORY_DELETED','UNIT_DISCARDED','UNIT_AUTO_EXPIRED','LAB_UNIT_CLEARED','LAB_UNIT_REJECTED')
                  AND EXISTS (SELECT 1 FROM blood_units bu WHERE bu.unit_id = a.entity_id AND bu.institution_id = ?)))");
        $metricStmt->bind_param('ssii', $institutionCodes[0], $institutionCodes[1], $institutionId, $institutionId);
        $metricStmt->execute();
        $metricRow = $metricStmt->get_result()->fetch_assoc();
        $metricStmt->close();
        $totalLogs = (int)($metricRow['total_logs'] ?? 0);

        $allocCount = 0;
        $allocCount = (int)($metricRow['alloc_count'] ?? 0);

        $labCount = 0;
        $labCount = (int)($metricRow['lab_count'] ?? 0);

        $securityCount = 0;
        $securityCount = (int)($metricRow['security_count'] ?? 0);

        jsonResponse([
            'success' => true,
            'logs'    => $logs,
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $totalFiltered, 'total_pages' => $totalPages],
            'metrics' => [
                'total_logs'     => $totalLogs,
                'alloc_count'    => $allocCount,
                'lab_count'      => $labCount,
                'security_count' => $securityCount
            ]
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Query error: ' . $conn->error], 500);
    }
}

// =========================================================================
// 2. GET: Transfusion Issuance Ledger (from `transactions` table)
// =========================================================================
if ($method === 'GET' && $action === 'transfusions') {
    $sql = "SELECT t.transaction_id, t.unit_id, t.request_id, t.allocation_date, t.quantity, t.institution_id,
                   u.blood_group, u.collection_date, u.expiry_date,
                   r.requested_blood_group, r.urgency, r.patient_ref,
                   hosp.full_name AS requester_name, hosp.institution_id AS hospital_name
            FROM transactions t
            LEFT JOIN blood_units u ON t.unit_id = u.unit_id
            LEFT JOIN blood_requests r ON t.request_id = r.request_id
            LEFT JOIN users hosp ON r.submitted_by = hosp.user_id
            WHERE t.institution_id = ? ORDER BY t.allocation_date DESC LIMIT 100";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $institutionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $transfusions = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $transfusions[] = [
                'transaction_id'        => (int)$row['transaction_id'],
                'unit_id'               => (int)$row['unit_id'],
                'request_id'            => (int)$row['request_id'],
                'allocation_date'       => $row['allocation_date'],
                'unit_blood_group'      => toDisplayBloodGroup($row['blood_group']),
                'requested_blood_group' => toDisplayBloodGroup($row['requested_blood_group']),
                'urgency'               => $row['urgency'],
                'patient_ref'           => $row['patient_ref'] ?: "Order #{$row['request_id']}",
                'hospital_name'         => $row['hospital_name'] ?: 'Clinical Center',
                'requester_name'        => $row['requester_name'] ?: 'Attending Staff'
            ];
        }
    }
    $stmt->close();

    jsonResponse(['success' => true, 'transfusions' => $transfusions]);
}

jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
