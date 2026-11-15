<?php
// api/hospital.php - Hospital Requisition & Transfusion Dispatch Portal (FR-08, NFR-07)
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$currentUser = requireAuth(['HOSPITAL_REQUESTER', 'STAFF', 'ADMIN']);
$userId = $currentUser['userId'] ?? 1;
$userRole = $currentUser['role'] ?? 'HOSPITAL_REQUESTER';
$userInstitution = $currentUser['institutionId'] ?? 'General Hospital';
if ($userRole === 'HOSPITAL_REQUESTER' && trim((string)$userInstitution) === '') {
    jsonResponse(['success' => false, 'message' => 'Your account is not linked to a hospital institution.'], 403);
}

$conn = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$data = getRequestData();
$action = $data['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// 1. GET: Fetch Hospital Orders with Institutional Isolation (NFR-07)
// =========================================================================
if ($method === 'GET' && ($action === 'my_requests' || empty($action))) {
    $statusFilter = trim($_GET['status'] ?? '');

    // Hospital Requesters only see their own hospital's orders (NFR-07)
    // Staff & Admin can see all hospital orders
    $sql = "SELECT r.request_id, r.submitted_by, r.requested_blood_group, r.quantity, r.urgency, r.status, r.request_date, r.patient_ref,
                   u.full_name AS requester_name, u.institution_id AS hospital_name, u.email AS requester_email
            FROM blood_requests r
            LEFT JOIN users u ON r.submitted_by = u.user_id
            WHERE 1=1";
    
    $params = [];
    $types = "";

    if ($userRole === 'HOSPITAL_REQUESTER') {
        if (!empty($currentUser['institutionVerified'])) {
            $sql .= " AND (r.submitted_by = ? OR u.institution_id = ?)";
            $params[] = $userId;
            $params[] = $userInstitution;
            $types .= "is";
        } else {
            $sql .= " AND r.submitted_by = ?";
            $params[] = $userId;
            $types .= 'i';
        }
    } else {
        $facilityId = requireInventoryInstitutionId($currentUser);
        $sql .= " AND r.serving_institution_id = ?";
        $params[] = $facilityId;
        $types .= 'i';
    }

    if (!empty($statusFilter)) {
        $sql .= " AND r.status = ?";
        $params[] = strtoupper($statusFilter);
        $types .= "s";
    }

    $sql .= " ORDER BY 
              CASE r.urgency 
                WHEN 'CRITICAL' THEN 1 
                WHEN 'URGENT' THEN 2 
                ELSE 3 
              END ASC, 
              r.request_date DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $orders = [];

        while ($row = $res->fetch_assoc()) {
            $reqId = (int)$row['request_id'];
            $isFulfilled = ($row['status'] === 'FULFILLED');
            $allocatedUnits = [];

            // Show all allocated units, including partial allocations on pending requests.
            {
                $txStmt = $conn->prepare("
                    SELECT t.transaction_id, t.allocation_date, u.unit_id, u.blood_group, u.expiry_date
                    FROM transactions t
                    JOIN blood_units u ON t.unit_id = u.unit_id
                    WHERE t.request_id = ?
                ");
                if ($txStmt) {
                    $txStmt->bind_param("i", $reqId);
                    $txStmt->execute();
                    $txRes = $txStmt->get_result();
                    while ($txRow = $txRes->fetch_assoc()) {
                        $allocatedUnits[] = [
                            'unit_id'         => (int)$txRow['unit_id'],
                            'blood_group'     => toDisplayBloodGroup($txRow['blood_group']),
                            'expiry_date'     => $txRow['expiry_date'],
                            'allocation_date' => $txRow['allocation_date']
                        ];
                    }
                    $txStmt->close();
                }
            }

            $orders[] = [
                'request_id'            => $reqId,
                'patient_ref'           => $row['patient_ref'] ?: "Order #$reqId",
                'hospital_name'         => $row['hospital_name'] ?: $userInstitution,
                'requester_name'        => $row['requester_name'] ?: 'Attending Physician',
                'requested_blood_group' => toDisplayBloodGroup($row['requested_blood_group']),
                'quantity'              => (int)$row['quantity'],
                'urgency'               => $row['urgency'],
                'status'                => $row['status'],
                'request_date'          => $row['request_date'],
                'allocated_units'       => $allocatedUnits
            ];
        }
        $stmt->close();

        // Calculate hospital metrics
        $pendingCount = 0;
        $fulfilledCount = 0;
        $criticalCount = 0;
        $totalUnitsReceived = 0;

        foreach ($orders as $o) {
            if ($o['status'] === 'PENDING') {
                $pendingCount++;
                if ($o['urgency'] === 'CRITICAL') $criticalCount++;
            } elseif ($o['status'] === 'FULFILLED') {
                $fulfilledCount++;
            }
            $totalUnitsReceived += count($o['allocated_units']);
        }

        jsonResponse([
            'success' => true,
            'hospital' => $userInstitution,
            'orders'   => $orders,
            'metrics'  => [
                'pending_count'        => $pendingCount,
                'fulfilled_count'      => $fulfilledCount,
                'critical_count'       => $criticalCount,
                'total_units_received' => $totalUnitsReceived
            ]
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Query error: ' . $conn->error], 500);
    }
}

// =========================================================================
// 2. POST: Place Clinical Requisition (FR-08)
// =========================================================================
if ($action === 'place_requisition') {
    $bloodGroup = toDbBloodGroup($data['blood_group'] ?? '');
    if (!isValidIntegerRange($data['quantity'] ?? null, 1, 10)) {
        jsonResponse(['success' => false, 'message' => 'Quantity must be a whole number between 1 and 10.'], 422);
    }
    $quantity   = (int)$data['quantity'];
    $urgency    = strtoupper(trim($data['urgency'] ?? 'ROUTINE'));
    $patientRef = trim($data['patient_ref'] ?? '');
    $indication = trim($data['clinical_indication'] ?? '');

    $allowedUrgency = ['ROUTINE', 'URGENT', 'CRITICAL'];
    if (!in_array($urgency, $allowedUrgency, true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid clinical urgency.'], 422);
    }

    if (empty($bloodGroup)) {
        jsonResponse(['success' => false, 'message' => 'Please select the required blood group.'], 400);
    }
    if ($patientRef === '' || strlen($patientRef) > 120) {
        jsonResponse(['success' => false, 'message' => 'Patient or ward reference is required and must be 120 characters or fewer.'], 422);
    }
    $allowedIndications = ['Acute Hemorrhagic Shock', 'Elective Surgery', 'Trauma Resuscitation', 'Obstetric Emergency', 'Severe Anemia / Hematology', 'General Intensive Care'];
    if (!in_array($indication, $allowedIndications, true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid clinical indication.'], 422);
    }

    $refString = !empty($patientRef) ? $patientRef : "Ward Order";
    if (!empty($indication)) $refString .= " (" . $indication . ")";

    $stmt = $conn->prepare(
        "INSERT INTO blood_requests (submitted_by, serving_institution_id, requested_blood_group, quantity, urgency, status, request_date, patient_ref)
         VALUES (?, ?, ?, ?, ?, 'PENDING', NOW(), ?)"
    );

    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }

    $servingFacilityId = $userRole === 'HOSPITAL_REQUESTER'
        ? defaultServingInstitutionId()
        : requireInventoryInstitutionId($currentUser);
    $stmt->bind_param("iisiss", $userId, $servingFacilityId, $bloodGroup, $quantity, $urgency, $refString);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Failed to submit requisition: ' . $err], 500);
    }

    $requestId = $stmt->insert_id;
    $stmt->close();

    $dispGroup = toDisplayBloodGroup($bloodGroup);
    logAudit($conn, $userId, 'HOSPITAL_ORDER_PLACED', $requestId, "Hospital ($userInstitution) placed order #$requestId for $quantity unit(s) of $dispGroup (Urgency: $urgency, Ref: $refString)");

    jsonResponse([
        'success'    => true,
        'message'    => "Requisition #$requestId submitted successfully! Central blood bank priority queue alerted.",
        'request_id' => $requestId
    ], 201);
}

// =========================================================================
// 3. GET: Generate Clinical Transfusion Dispatch Slip
// =========================================================================
if ($method === 'GET' && $action === 'transfusion_slip') {
    $requestId = (int)($_GET['request_id'] ?? 0);
    if (!$requestId) {
        jsonResponse(['success' => false, 'message' => 'Request ID is required.'], 400);
    }

    $slipSql = "
        SELECT r.request_id, r.submitted_by, r.requested_blood_group, r.quantity, r.urgency, r.status, r.request_date, r.patient_ref,
               u.full_name AS requester_name, u.institution_id AS hospital_name
        FROM blood_requests r
        LEFT JOIN users u ON r.submitted_by = u.user_id
        WHERE r.request_id = ?";
    $slipParams = [$requestId];
    $slipTypes = 'i';
    if ($userRole === 'HOSPITAL_REQUESTER') {
        if (!empty($currentUser['institutionVerified'])) {
            $slipSql .= " AND (r.submitted_by = ? OR u.institution_id = ?)";
            $slipParams[] = $userId;
            $slipParams[] = $userInstitution;
            $slipTypes .= 'is';
        } else {
            $slipSql .= " AND r.submitted_by = ?";
            $slipParams[] = $userId;
            $slipTypes .= 'i';
        }
    } else {
        $facilityId = requireInventoryInstitutionId($currentUser);
        $slipSql .= " AND r.serving_institution_id = ?";
        $slipParams[] = $facilityId;
        $slipTypes .= 'i';
    }
    $slipSql .= " LIMIT 1";
    $stmt = $conn->prepare($slipSql);
    $stmt->bind_param($slipTypes, ...$slipParams);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Order not found.'], 404);
    }
    $req = $res->fetch_assoc();
    $stmt->close();

    // Fetch allocated units
    $unitsStmt = $conn->prepare("
        SELECT t.transaction_id, t.allocation_date, u.unit_id, u.blood_group, u.collection_date, u.expiry_date
        FROM transactions t
        JOIN blood_units u ON t.unit_id = u.unit_id
        WHERE t.request_id = ?
    ");
    $unitsStmt->bind_param("i", $requestId);
    $unitsStmt->execute();
    $uRes = $unitsStmt->get_result();
    $allocatedUnits = [];
    while ($u = $uRes->fetch_assoc()) {
        $allocatedUnits[] = [
            'unit_id'         => (int)$u['unit_id'],
            'blood_group'     => toDisplayBloodGroup($u['blood_group']),
            'collection_date' => $u['collection_date'],
            'expiry_date'     => $u['expiry_date'],
            'allocation_date' => $u['allocation_date']
        ];
    }
    $unitsStmt->close();

    jsonResponse([
        'success'         => true,
        'order'           => [
            'request_id'            => (int)$req['request_id'],
            'patient_ref'           => $req['patient_ref'],
            'hospital_name'         => $req['hospital_name'] ?: $userInstitution,
            'requester_name'        => $req['requester_name'],
            'requested_blood_group' => toDisplayBloodGroup($req['requested_blood_group']),
            'quantity'              => (int)$req['quantity'],
            'urgency'               => $req['urgency'],
            'status'                => $req['status'],
            'request_date'          => $req['request_date']
        ],
        'allocated_units' => $allocatedUnits,
        'dispatch_timestamp' => date('Y-m-d H:i:s')
    ]);
}

jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
