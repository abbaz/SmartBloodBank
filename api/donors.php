<?php
// api/donors.php - Donor Management API (FR-04)
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
$data = getRequestData();
$action = $data['action'] ?? $_GET['action'] ?? '';

// GET: Fetch list of donors
if ($method === 'GET') {
    $currentUser = requireAuth(['STAFF', 'ADMIN']);
    $institutionId = trim((string)($currentUser['institutionId'] ?? $currentUser['institution_id'] ?? ''));
    if ($institutionId === '') jsonResponse(['success' => false, 'message' => 'Your account is not linked to an institution.'], 403);
    $institutionCodes = userInstitutionCodes($currentUser);
    $conn = getDb();
    $q = trim($_GET['q'] ?? '');
    $filterGroup = trim($_GET['blood_group'] ?? '');

    $sql = "SELECT d.donor_id, d.managed_by, d.name, d.contact_details, d.blood_group, d.last_donation_date
            FROM donors d JOIN users manager ON d.managed_by = manager.user_id
            WHERE manager.institution_id IN (?, ?)";
    $params = $institutionCodes;
    $types = "ss";

    if (!empty($q)) {
        $sql .= " AND (name LIKE ? OR contact_details LIKE ?)";
        $searchTerm = "%$q%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ss";
    }

    if (!empty($filterGroup)) {
        $dbGroup = toDbBloodGroup($filterGroup);
        $sql .= " AND blood_group = ?";
        $params[] = $dbGroup;
        $types .= "s";
    }

    $sql .= " ORDER BY name ASC";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $donors = [];
        $today = new DateTime();

        while ($row = $res->fetch_assoc()) {
            $lastDate = $row['last_donation_date'];
            $daysSince = null;
            $isEligible = true;
            $nextEligible = null;

            if ($lastDate) {
                $lastDt = new DateTime($lastDate);
                $diff = $today->diff($lastDt);
                $daysSince = $diff->invert ? $diff->days : -$diff->days;
                
                // Red-cell donation cooldown is 56 days (8 weeks)
                if ($daysSince < 56) {
                    $isEligible = false;
                    $nextDt = clone $lastDt;
                    $nextDt->modify('+56 days');
                    $nextEligible = $nextDt->format('Y-m-d');
                }
            }

            $donors[] = [
                'donor_id'           => (int)$row['donor_id'],
                'name'               => $row['name'],
                'contact_details'    => $row['contact_details'],
                'blood_group_db'     => $row['blood_group'],
                'blood_group'        => toDisplayBloodGroup($row['blood_group']),
                'last_donation_date' => $row['last_donation_date'],
                'days_since'         => $daysSince,
                'is_eligible'        => $isEligible,
                'next_eligible_date' => $nextEligible
            ];
        }
        $stmt->close();
        jsonResponse(['success' => true, 'donors' => $donors]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Query error: ' . $conn->error], 500);
    }
}

// Write operations require authenticated Staff or Admin
$currentUser = $currentUser ?? requireAuth(['STAFF', 'ADMIN']);
$userId = (int)($currentUser['userId'] ?? $currentUser['user_id'] ?? 0);
$institutionId = trim((string)($currentUser['institutionId'] ?? $currentUser['institution_id'] ?? ''));
if ($institutionId === '') jsonResponse(['success' => false, 'message' => 'Your account is not linked to an institution.'], 403);
$institutionCodes = userInstitutionCodes($currentUser);
$conn = getDb();

function donorBelongsToInstitution($conn, $donorId, $institutionCodes) {
    $check = $conn->prepare("SELECT d.donor_id FROM donors d JOIN users manager ON d.managed_by = manager.user_id WHERE d.donor_id = ? AND manager.institution_id IN (?, ?) LIMIT 1");
    if (!$check) return false;
    $check->bind_param('iss', $donorId, $institutionCodes[0], $institutionCodes[1]);
    $check->execute();
    $owned = $check->get_result()->num_rows > 0;
    $check->close();
    return $owned;
}

// POST Action: Delete donor
if ($action === 'delete' || $method === 'DELETE') {
    $donorId = (int)($data['donor_id'] ?? $_GET['donor_id'] ?? 0);
    if (!$donorId) {
        jsonResponse(['success' => false, 'message' => 'Donor ID is required for deletion.'], 400);
    }
    if (!donorBelongsToInstitution($conn, $donorId, $institutionCodes)) {
        jsonResponse(['success' => false, 'message' => 'Donor not found in your institution.'], 404);
    }

    $stmt = $conn->prepare("DELETE FROM donors WHERE donor_id = ?");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    $stmt->bind_param("i", $donorId);
    $stmt->execute();
    $stmt->close();

    logAudit($conn, $userId, 'DONOR_DELETED', $donorId, "Donor #$donorId removed");

    jsonResponse(['success' => true, 'message' => 'Donor profile deleted successfully.']);
}

// POST Action: Update existing donor
if ($action === 'update' || $method === 'PUT') {
    $donorId        = (int)($data['donor_id'] ?? 0);
    $name           = trim($data['name'] ?? '');
    $contact        = trim($data['contact_details'] ?? $data['contact'] ?? '');
    $bloodGroup     = toDbBloodGroup($data['blood_group'] ?? '');
    $lastDonation   = !empty($data['last_donation_date']) ? $data['last_donation_date'] : null;

    if (!isValidIntegerRange($donorId, 1, PHP_INT_MAX) || !isValidFullName($name, 2, 100)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid donor ID and a donor name of 2-100 letters, spaces, apostrophes, periods, or hyphens.'], 400);
    }
    if (!isValidPhoneOrEmail($contact)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid Pakistani mobile number or email address.'], 422);
    }
    if (!in_array($bloodGroup, ['O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE'], true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid donor blood group.'], 422);
    }
    if ($lastDonation !== null && !isDateNoLaterThanToday((string)$lastDonation)) {
        jsonResponse(['success' => false, 'message' => 'Last donation date must be valid and no later than today.'], 422);
    }
    if (!donorBelongsToInstitution($conn, $donorId, $institutionCodes)) {
        jsonResponse(['success' => false, 'message' => 'Donor not found in your institution.'], 404);
    }

    $stmt = $conn->prepare(
        "UPDATE donors SET name = ?, contact_details = ?, blood_group = ?, last_donation_date = ? WHERE donor_id = ?"
    );
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    $stmt->bind_param("ssssi", $name, $contact, $bloodGroup, $lastDonation, $donorId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Update failed: ' . $err], 500);
    }
    $stmt->close();

    logAudit($conn, $userId, 'DONOR_UPDATED', $donorId, "Donor #$donorId updated ($name, $bloodGroup)");

    jsonResponse(['success' => true, 'message' => 'Donor profile updated successfully.']);
}

// POST Action: Create new donor
if ($method === 'POST') {
    $name         = trim($data['name'] ?? '');
    $contact      = trim($data['contact_details'] ?? $data['contact'] ?? '');
    $bloodGroup   = toDbBloodGroup($data['blood_group'] ?? '');
    $lastDonation = !empty($data['last_donation_date']) ? $data['last_donation_date'] : null;

    if (!isValidFullName($name, 2, 100)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid donor name using 2-100 letters, spaces, apostrophes, periods, or hyphens.'], 400);
    }
    if (!isValidPhoneOrEmail($contact)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid Pakistani mobile number or email address.'], 422);
    }
    if (!in_array($bloodGroup, ['O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE'], true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid donor blood group.'], 400);
    }
    if ($lastDonation !== null && !isDateNoLaterThanToday((string)$lastDonation)) {
        jsonResponse(['success' => false, 'message' => 'Last donation date must be valid and no later than today.'], 422);
    }

    $stmt = $conn->prepare(
        "INSERT INTO donors (managed_by, name, contact_details, blood_group, last_donation_date)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }

    $stmt->bind_param("issss", $userId, $name, $contact, $bloodGroup, $lastDonation);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Could not save donor: ' . $err], 500);
    }

    $donorId = $stmt->insert_id;
    $stmt->close();

    logAudit($conn, $userId, 'DONOR_CREATED', $donorId, "New donor registered: $name ($bloodGroup)");

    jsonResponse([
        'success'  => true,
        'message'  => 'Donor profile registered successfully.',
        'donor_id' => $donorId
    ], 201);
}
