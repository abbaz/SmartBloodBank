<?php
// api/allocation.php - Smart Allocation Recommendation Engine
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$currentUser = requireAuth(['STAFF', 'ADMIN', 'HOSPITAL_REQUESTER']);
$userId = $currentUser['userId'] ?? 1;
$userInstitution = trim((string)($currentUser['institutionId'] ?? $currentUser['institution_id'] ?? ''));
$institutionCodes = userInstitutionCodes($currentUser);
if (($currentUser['role'] ?? '') === 'HOSPITAL_REQUESTER' && $userInstitution === '') {
    jsonResponse(['success' => false, 'message' => 'Your account is not linked to a hospital institution.'], 403);
}

$conn = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$data = getRequestData();
$action = $data['action'] ?? $_GET['action'] ?? '';

$POLICY_CONFIG = json_decode(file_get_contents(__DIR__ . '/../config/allocation-policy.json'), true);
if (!is_array($POLICY_CONFIG) || empty($POLICY_CONFIG['compatibility']) || empty($POLICY_CONFIG['urgency_weights'])) {
    jsonResponse(['success' => false, 'message' => 'Allocation policy configuration is unavailable.'], 500);
}
$COMPATIBILITY_RULES = $POLICY_CONFIG['compatibility'];

// =========================================================================
// 1. GET: Fetch Blood Requests
// =========================================================================
if ($method === 'GET' && ($action === 'requests' || empty($action))) {
    $statusFilter = trim($_GET['status'] ?? '');
        $sql = "SELECT r.request_id, r.submitted_by, r.requested_blood_group, r.quantity,
                       (SELECT COUNT(*) FROM transactions tx WHERE tx.request_id = r.request_id) AS allocated_count,
                       r.urgency, r.status, r.request_date,
                   u.full_name AS requester_name, u.institution_id AS requester_institution
            FROM blood_requests r
            LEFT JOIN users u ON r.submitted_by = u.user_id
            WHERE 1=1";
    $params = [];
    $types = "";

    if (($currentUser['role'] ?? '') === 'HOSPITAL_REQUESTER') {
        if (!empty($currentUser['institutionVerified'])) {
            $sql .= " AND (r.submitted_by = ? OR u.institution_id = ?)";
            $params[] = (int)$userId;
            $params[] = $userInstitution;
            $types .= 'is';
        } else {
            $sql .= " AND r.submitted_by = ?";
            $params[] = (int)$userId;
            $types .= 'i';
        }
    } else {
        $queueFacility = requireInventoryInstitutionId($currentUser);
        $sql .= " AND r.serving_institution_id = ?";
        $params[] = $queueFacility;
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
        $requests = [];

        while ($row = $res->fetch_assoc()) {
            $requests[] = [
                'request_id'            => (int)$row['request_id'],
                'submitted_by'          => (int)$row['submitted_by'],
                'requester_name'        => $row['requester_name'] ?: 'Clinical Requester',
                'requester_institution' => $row['requester_institution'] ?: 'General Hospital',
                'requested_blood_group_db' => $row['requested_blood_group'],
                'requested_blood_group' => toDisplayBloodGroup($row['requested_blood_group']),
                'quantity'              => (int)$row['quantity'],
                'allocated_count'       => (int)$row['allocated_count'],
                'urgency'               => $row['urgency'],
                'status'                => $row['status'],
                'request_date'          => $row['request_date']
            ];
        }
        $stmt->close();
        jsonResponse(['success' => true, 'requests' => $requests]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
}

// =========================================================================
// 2. GET: Run Allocation Engine & Get Ranked Recommendations (FR-09)
// =========================================================================
if ($method === 'GET' && $action === 'recommendations') {
    if (!in_array($currentUser['role'] ?? '', ['STAFF', 'ADMIN'], true)) {
        jsonResponse(['success' => false, 'message' => 'Allocation recommendations are restricted to blood-bank staff.'], 403);
    }
    $institutionId = requireInventoryInstitutionId($currentUser);
    $requestId = (int)($_GET['request_id'] ?? 0);
    if (!$requestId) {
        jsonResponse(['success' => false, 'message' => 'Request ID is required.'], 400);
    }

    // Retrieve request details
    $rStmt = $conn->prepare("SELECT * FROM blood_requests WHERE request_id = ? AND serving_institution_id = ? LIMIT 1");
    if (!$rStmt) jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
    $rStmt->bind_param("ii", $requestId, $institutionId);
    $rStmt->execute();
    $rRes = $rStmt->get_result();
    if (!$rRes || $rRes->num_rows === 0) {
        $rStmt->close();
        jsonResponse(['success' => false, 'message' => 'Request not found.'], 404);
    }
    $req = $rRes->fetch_assoc();
    $rStmt->close();

    $alreadyStmt = $conn->prepare("SELECT COUNT(*) AS allocated FROM transactions WHERE request_id = ?");
    $alreadyStmt->bind_param('i', $requestId); $alreadyStmt->execute();
    $alreadyAllocated = (int)$alreadyStmt->get_result()->fetch_assoc()['allocated']; $alreadyStmt->close();
    $targetGroupDb = $req['requested_blood_group'];
    $urgency       = $req['urgency'];
    $quantityReq   = max(0, (int)$req['quantity'] - $alreadyAllocated);
    if ($quantityReq === 0 || $req['status'] === 'FULFILLED') jsonResponse(['success' => false, 'message' => 'This request has no units remaining.'], 409);

    // Step 1: Filter compatible blood groups
    $compatibleGroups = $COMPATIBILITY_RULES[$targetGroupDb] ?? [$targetGroupDb];
    $inClause = "'" . implode("','", $compatibleGroups) . "'";

    // Query candidate units: Must be AVAILABLE, not expired (CURDATE() <= expiry_date)
    $qSql = "SELECT u.unit_id, u.donor_id, u.blood_group, u.collection_date, u.expiry_date, u.status,
                    d.name AS donor_name, d.contact_details AS donor_contact
             FROM blood_units u
             LEFT JOIN donors d ON u.donor_id = d.donor_id AND d.managed_by IN (SELECT user_id FROM users WHERE institution_id IN (?, ?))
             WHERE u.status = 'AVAILABLE'
               AND u.expiry_date >= CURDATE()
               AND u.blood_group IN ($inClause)
               AND u.institution_id = ?";

    $candStmt = $conn->prepare($qSql);
    if (!$candStmt) jsonResponse(['success' => false, 'message' => 'Could not prepare scoped inventory query.'], 500);
    $candStmt->bind_param('ssi', $institutionCodes[0], $institutionCodes[1], $institutionId);
    $candStmt->execute();
    $candRes = $candStmt->get_result();
    $candidates = [];
    $today = new DateTime('today');

    if ($candRes) {
        while ($row = $candRes->fetch_assoc()) {
            $expDate = new DateTime($row['expiry_date']);
            $diff = $today->diff($expDate);
            $daysToExpiry = $diff->invert ? -$diff->days : $diff->days;

            // Step 2 & 3: Multi-Factor Scoring Formulation
            $isExactMatch = ($row['blood_group'] === $targetGroupDb);
            $isUniversalO = ($row['blood_group'] === 'O_NEGATIVE');
            $patientIsONeg = ($targetGroupDb === 'O_NEGATIVE');

            // Dynamic weights based on Urgency
            $weights = $POLICY_CONFIG['urgency_weights'][$urgency] ?? $POLICY_CONFIG['urgency_weights']['ROUTINE'];
            $wMatch = (float)$weights['match'];
            $wFefo = (float)$weights['expiry'];
            $wScarcity = (float)$weights['scarcity'];

            // Component 1: Match Quality Score
            $matchScore = $isExactMatch ? $wMatch : ($wMatch * (float)$POLICY_CONFIG['substitute_match_ratio']);

            // Component 2: FEFO Expiry Proximity Score (earlier expiry gets higher priority)
            // Normal shelf life is 35 days. If unit has 3 days left, it gets ~91% of wFefo score.
            $referenceShelfLife = (float)$POLICY_CONFIG['reference_shelf_life_days'];
            $fefoRatio = max(0.0, min(1.0, ($referenceShelfLife - $daysToExpiry) / $referenceShelfLife));
            $fefoScore = $wFefo * $fefoRatio;

            // Component 3: O-Negative Scarcity Penalty
            $scarcityPenalty = 0.0;
            if ($isUniversalO && !$patientIsONeg) {
                $scarcityPenalty = $wScarcity;
            }

            // Total Composite Score
            $totalScore = round($matchScore + $fefoScore - $scarcityPenalty, 1);

            // Clinical Explanation
            $reason = "";
            if ($isExactMatch) {
                $reason = "Exact match (" . toDisplayBloodGroup($row['blood_group']) . ") with {$daysToExpiry}d shelf life. Recommended by FEFO policy.";
            } else {
                if ($isUniversalO && !$patientIsONeg) {
                    $reason = $urgency === 'CRITICAL'
                        ? "Universal O- substitute utilized for critical clinical resuscitation."
                        : "Compatible O- substitute. Scarcity penalty applied to preserve O- reserves.";
                } else {
                    $reason = "Compatible substitute (" . toDisplayBloodGroup($row['blood_group']) . ") suitable for transfusion.";
                }
            }

            $candidates[] = [
                'unit_id'          => (int)$row['unit_id'],
                'donor_name'       => $row['donor_name'] ?: 'Voluntary Donor',
                'blood_group_db'   => $row['blood_group'],
                'blood_group'      => toDisplayBloodGroup($row['blood_group']),
                'collection_date'  => $row['collection_date'],
                'expiry_date'      => $row['expiry_date'],
                'days_to_expiry'   => $daysToExpiry,
                'is_exact_match'   => $isExactMatch,
                'match_score'      => round($matchScore, 1),
                'fefo_score'       => round($fefoScore, 1),
                'scarcity_penalty' => round($scarcityPenalty, 1),
                'total_score'      => $totalScore,
                'clinical_reason'  => $reason
            ];
        }
    }
    $candStmt->close();

    // Sort descending by total score
    usort($candidates, function($a, $b) {
        if ($b['total_score'] == $a['total_score']) {
            $expiryOrder = $a['days_to_expiry'] <=> $b['days_to_expiry'];
            return $expiryOrder !== 0 ? $expiryOrder : ($a['unit_id'] <=> $b['unit_id']);
        }
        return ($b['total_score'] > $a['total_score']) ? 1 : -1;
    });

    // Assign rank numbers and pre-select top N units up to requested quantity
    foreach ($candidates as $idx => &$cand) {
        $cand['rank'] = $idx + 1;
        $cand['recommended'] = ($idx < $quantityReq);
    }
    unset($cand);

    jsonResponse([
        'success'        => true,
        'request'        => [
            'request_id'            => (int)$req['request_id'],
            'requested_blood_group' => toDisplayBloodGroup($req['requested_blood_group']),
            'quantity'              => $quantityReq,
            'total_quantity'        => (int)$req['quantity'],
            'already_allocated'     => $alreadyAllocated,
            'urgency'               => $urgency,
            'status'                => $req['status']
        ],
        'candidate_count' => count($candidates),
        'recommendations' => $candidates
    ]);
}

// =========================================================================
// 3. POST: Create Blood Request (Clinical Order)
// =========================================================================
if ($action === 'create_request') {
    $bloodGroup = toDbBloodGroup($data['blood_group'] ?? '');
    if (!isValidIntegerRange($data['quantity'] ?? null, 1, 10)) {
        jsonResponse(['success' => false, 'message' => 'Quantity must be a whole number between 1 and 10.'], 422);
    }
    $quantity   = (int)$data['quantity'];
    $urgency    = strtoupper(trim($data['urgency'] ?? 'ROUTINE'));

    $allowedUrgency = ['ROUTINE', 'URGENT', 'CRITICAL'];
    if (!in_array($urgency, $allowedUrgency, true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid clinical urgency.'], 422);
    }

    if (empty($bloodGroup)) {
        jsonResponse(['success' => false, 'message' => 'Blood group is required.'], 400);
    }

    $servingInstitutionId = in_array($currentUser['role'] ?? '', ['STAFF', 'ADMIN'], true)
        ? requireInventoryInstitutionId($currentUser)
        : defaultServingInstitutionId();
    $stmt = $conn->prepare(
        "INSERT INTO blood_requests (submitted_by, serving_institution_id, requested_blood_group, quantity, urgency, status, request_date)
         VALUES (?, ?, ?, ?, ?, 'PENDING', NOW())"
    );
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);

    $stmt->bind_param("iisis", $userId, $servingInstitutionId, $bloodGroup, $quantity, $urgency);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Failed to place request: ' . $err], 500);
    }

    $requestId = $stmt->insert_id;
    $stmt->close();

    logAudit($conn, $userId, 'BLOOD_REQUEST_CREATED', $requestId, "Order #$requestId for $quantity unit(s) of " . toDisplayBloodGroup($bloodGroup) . " (Urgency: $urgency)");

    jsonResponse([
        'success'    => true,
        'message'    => "Blood request #$requestId placed successfully in priority queue.",
        'request_id' => $requestId
    ], 201);
}

// =========================================================================
// 4. POST: Confirm Allocation & Atomic Issue (FR-10, FR-11)
// =========================================================================
if ($action === 'confirm_allocation') {
    requireAuth(['STAFF', 'ADMIN']);
    $institutionId = requireInventoryInstitutionId($currentUser);

    $requestId = (int)($data['request_id'] ?? 0);
    $unitIds   = $data['unit_ids'] ?? [];

    if (!$requestId || empty($unitIds) || !is_array($unitIds)) {
        jsonResponse(['success' => false, 'message' => 'Request ID and at least one selected unit are required.'], 400);
    }
    $normalizedUnitIds = [];
    foreach ($unitIds as $rawUnitId) {
        if (!is_scalar($rawUnitId) || !ctype_digit((string)$rawUnitId) || (int)$rawUnitId < 1) {
            jsonResponse(['success' => false, 'message' => 'Every selected unit ID must be a positive integer.'], 422);
        }
        $normalizedUnitIds[] = (int)$rawUnitId;
    }
    if (count($normalizedUnitIds) !== count(array_unique($normalizedUnitIds))) {
        jsonResponse(['success' => false, 'message' => 'A unit cannot be selected more than once.'], 422);
    }
    $unitIds = $normalizedUnitIds;

    // Fetch request to verify
    $rStmt = $conn->prepare("SELECT requested_blood_group, quantity, urgency, status FROM blood_requests WHERE request_id = ? AND serving_institution_id = ?");
    $rStmt->bind_param("ii", $requestId, $institutionId);
    $rStmt->execute();
    $rRes = $rStmt->get_result();
    if (!$rRes || $rRes->num_rows === 0) {
        $rStmt->close();
        jsonResponse(['success' => false, 'message' => 'Request record not found.'], 404);
    }
    $req = $rRes->fetch_assoc();
    $rStmt->close();

    if ($req['status'] === 'FULFILLED') {
        jsonResponse(['success' => false, 'message' => 'This request has already been fulfilled.'], 400);
    }

    $targetGroup = $req['requested_blood_group'];
    $compatibleGroups = $COMPATIBILITY_RULES[$targetGroup] ?? [$targetGroup];

    // BEGIN ATOMIC TRANSACTION (FR-11)
    $conn->begin_transaction();

    try {
        // Lock the request so concurrent staff cannot fulfill it twice.
        $lockReq = $conn->prepare("SELECT quantity, requested_blood_group, status FROM blood_requests WHERE request_id = ? AND serving_institution_id = ? FOR UPDATE");
        if (!$lockReq) throw new Exception('Could not lock the clinical request.');
        $lockReq->bind_param('ii', $requestId, $institutionId);
        $lockReq->execute();
        $lockedReq = $lockReq->get_result()->fetch_assoc();
        $lockReq->close();
        if (!$lockedReq) throw new Exception('Request record not found.');
        if ($lockedReq['status'] === 'FULFILLED') throw new Exception('This request has already been fulfilled.');
        $countStmt = $conn->prepare("SELECT COUNT(*) AS allocated FROM transactions WHERE request_id = ?");
        $countStmt->bind_param('i', $requestId); $countStmt->execute();
        $previouslyAllocated = (int)$countStmt->get_result()->fetch_assoc()['allocated']; $countStmt->close();
        $remaining = max(0, (int)$lockedReq['quantity'] - $previouslyAllocated);
        if ($remaining < 1 || count($unitIds) > $remaining) throw new Exception('Selected units exceed the remaining request quantity.');
        $targetGroup = $lockedReq['requested_blood_group'];
        $compatibleGroups = $COMPATIBILITY_RULES[$targetGroup] ?? [$targetGroup];

        $allocatedUnits = [];

        foreach ($unitIds as $unitId) {

            // Safety Guardrail (FR-10): Lock row and verify unit is AVAILABLE, unexpired, and compatible
            $uStmt = $conn->prepare("SELECT unit_id, blood_group, status, expiry_date FROM blood_units WHERE unit_id = ? AND institution_id = ? FOR UPDATE");
            $uStmt->bind_param("ii", $unitId, $institutionId);
            $uStmt->execute();
            $uRes = $uStmt->get_result();
            if (!$uRes || $uRes->num_rows === 0) {
                throw new Exception("Unit #$unitId does not exist in inventory.");
            }
            $unit = $uRes->fetch_assoc();
            $uStmt->close();

            if ($unit['status'] !== 'AVAILABLE') {
                throw new Exception("Unit #$unitId cannot be allocated because its status is {$unit['status']} (Must be AVAILABLE).");
            }

            if (strtotime($unit['expiry_date']) < strtotime(date('Y-m-d'))) {
                throw new Exception("Unit #$unitId has expired ({$unit['expiry_date']}). Issuing expired blood is strictly blocked.");
            }

            if (!in_array($unit['blood_group'], $compatibleGroups, true)) {
                throw new Exception("Unit #$unitId group {$unit['blood_group']} is biologically incompatible with recipient {$targetGroup}.");
            }

            // 1. Update unit status to ISSUED
            $upUnit = $conn->prepare("UPDATE blood_units SET status = 'ISSUED' WHERE unit_id = ?");
            $upUnit->bind_param("i", $unitId);
            $upUnit->execute();
            $upUnit->close();

            // 2. Insert into transactions table
            $insTx = $conn->prepare("INSERT INTO transactions (unit_id, request_id, allocation_date, quantity, institution_id) VALUES (?, ?, NOW(), 1, ?)");
            $insTx->bind_param("iii", $unitId, $requestId, $institutionId);
            $insTx->execute();
            $insTx->close();

            $allocatedUnits[] = "#$unitId (" . toDisplayBloodGroup($unit['blood_group']) . ")";
        }

        // Leave partially served requests pending; mark fulfilled only at full quantity.
        $isFulfilled = ($previouslyAllocated + count($allocatedUnits)) >= (int)$lockedReq['quantity'];
        if ($isFulfilled) {
            $upReq = $conn->prepare("UPDATE blood_requests SET status = 'FULFILLED' WHERE request_id = ?");
            $upReq->bind_param("i", $requestId); $upReq->execute(); $upReq->close();
        }

        // 4. Log to audit_logs
        $unitsStr = implode(", ", $allocatedUnits);
        $auditAction = $isFulfilled ? 'REQUEST_FULFILLED' : 'REQUEST_PARTIALLY_FULFILLED';
        if (!logAudit($conn, $userId, $auditAction, $requestId, "Request #$requestId allocated " . count($allocatedUnits) . " unit(s); total allocated " . ($previouslyAllocated + count($allocatedUnits)) . '/' . (int)$lockedReq['quantity'] . ". Units: $unitsStr")) {
            throw new RuntimeException('Allocation audit recording failed; changes were rolled back.');
        }

        // Commit transaction
        $conn->commit();

        jsonResponse([
            'success'         => true,
            'message'         => $isFulfilled ? "Request #$requestId is fully fulfilled." : "Partial allocation saved; request #$requestId remains pending.",
            'allocated_units' => $allocatedUnits,
            'status'          => $isFulfilled ? 'FULFILLED' : 'PENDING',
            'remaining'       => (int)$lockedReq['quantity'] - $previouslyAllocated - count($allocatedUnits)
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        jsonResponse([
            'success' => false,
            'message' => 'Allocation failed and was rolled back: ' . $e->getMessage()
        ], 400);
    }
}

// =========================================================================
// 5. POST: Seed Demo Stock (Convenience helper for pitching & demos)
// =========================================================================
if ($action === 'seed_demo_stock') {
    requireAuth(['STAFF', 'ADMIN']);
    $institutionId = requireInventoryInstitutionId($currentUser);

    $sampleUnits = [
        ['group' => 'O_POSITIVE',  'days' => 4,  'donor' => 'Ali Raza',        'contact' => '03001112233'],
        ['group' => 'O_POSITIVE',  'days' => 19, 'donor' => 'Zainab Bibi',     'contact' => '03012223344'],
        ['group' => 'A_POSITIVE',  'days' => 6,  'donor' => 'Hamza Tariq',     'contact' => '03023334455'],
        ['group' => 'A_POSITIVE',  'days' => 24, 'donor' => 'Bilal Ahmed',     'contact' => '03034445566'],
        ['group' => 'B_POSITIVE',  'days' => 8,  'donor' => 'Fatima Noor',     'contact' => '03045556677'],
        ['group' => 'O_NEGATIVE',  'days' => 5,  'donor' => 'Usman Khalid',    'contact' => '03056667788'],
        ['group' => 'O_NEGATIVE',  'days' => 28, 'donor' => 'Ayesha Siddiqua', 'contact' => '03067778899'],
        ['group' => 'AB_POSITIVE', 'days' => 14, 'donor' => 'Danish Ali',      'contact' => '03078889900'],
    ];

    $created = 0;
    foreach ($sampleUnits as $s) {
        // Find or create donor
        $dCheck = $conn->prepare("SELECT d.donor_id FROM donors d JOIN users manager ON d.managed_by = manager.user_id WHERE d.name = ? AND manager.institution_id IN (?, ?) LIMIT 1");
        $dCheck->bind_param("sss", $s['donor'], $institutionCodes[0], $institutionCodes[1]);
        $dCheck->execute();
        $dRes = $dCheck->get_result();
        $donorId = 0;
        if ($dRes && $dRes->num_rows > 0) {
            $donorId = (int)$dRes->fetch_assoc()['donor_id'];
        } else {
            $dIns = $conn->prepare("INSERT INTO donors (managed_by, name, contact_details, blood_group, last_donation_date) VALUES (?, ?, ?, ?, CURDATE())");
            $dIns->bind_param("isss", $userId, $s['donor'], $s['contact'], $s['group']);
            $dIns->execute();
            $donorId = $dIns->insert_id;
            $dIns->close();
        }
        $dCheck->close();

        // Calculate dates
        $colDate = date('Y-m-d', strtotime('-' . (35 - $s['days']) . ' days'));
        $expDate = date('Y-m-d', strtotime('+' . $s['days'] . ' days'));

        $uIns = $conn->prepare("INSERT INTO blood_units (donor_id, blood_group, collection_date, expiry_date, status, institution_id) VALUES (?, ?, ?, ?, 'AVAILABLE', ?)");
        $uIns->bind_param("isssi", $donorId, $s['group'], $colDate, $expDate, $institutionId);
        if ($uIns->execute()) $created++;
        $uIns->close();
    }

    jsonResponse([
        'success' => true,
        'message' => "Successfully populated $created verified units with varied expiry countdowns and blood groups for demonstration."
    ]);
}

jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
