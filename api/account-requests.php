<?php
// Admin review queue for public staff, hospital, and lab role applications.
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');
$admin = requireAuth(['ADMIN']);
$conn = getDb();
$adminId = (int)($admin['userId'] ?? $admin['user_id'] ?? 0);
$codes = userInstitutionCodes($admin);

function accountRequestIsInScope($role, $institutionId, $codes) {
    // Hospital requester institutions are partner organizations; staff and lab
    // roles belong to the blood bank facility managed by this Admin.
    if ($role === 'HOSPITAL_REQUESTER') return true;
    return in_array((string)$institutionId, $codes, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare(
        "SELECT user_id, username, full_name, email, phone, role, blood_group, institution_id, created_at " .
        "FROM users WHERE approval_status = 'PENDING' AND role IN ('STAFF','HOSPITAL_REQUESTER','LAB_TECHNICIAN') " .
        "AND (role = 'HOSPITAL_REQUESTER' OR institution_id IN (?, ?)) ORDER BY created_at ASC, user_id ASC"
    );
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Could not load role applications.'], 500);
    $stmt->bind_param('ss', $codes[0], $codes[1]);
    $stmt->execute();
    $result = $stmt->get_result();
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        $applications[] = [
            'userId' => (int)$row['user_id'],
            'username' => $row['username'],
            'fullName' => $row['full_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'role' => $row['role'],
            'bloodGroup' => $row['blood_group'],
            'institutionId' => $row['institution_id'],
            'submittedAt' => $row['created_at']
        ];
    }
    $stmt->close();
    jsonResponse(['success' => true, 'applications' => $applications]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$data = getRequestData();
$targetId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
$decision = strtoupper(trim((string)($data['decision'] ?? '')));
if (!$targetId || !in_array($decision, ['APPROVE', 'REJECT'], true)) {
    jsonResponse(['success' => false, 'message' => 'Select a valid role application and decision.'], 400);
}

$conn->begin_transaction();
try {
    $find = $conn->prepare(
        "SELECT user_id, username, role, institution_id FROM users WHERE user_id = ? AND approval_status = 'PENDING' " .
        "AND role IN ('STAFF','HOSPITAL_REQUESTER','LAB_TECHNICIAN') LIMIT 1 FOR UPDATE"
    );
    if (!$find) throw new RuntimeException('Could not load the selected application.');
    $find->bind_param('i', $targetId);
    $find->execute();
    $target = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$target || !accountRequestIsInScope($target['role'], $target['institution_id'], $codes)) {
        $conn->rollback();
        jsonResponse(['success' => false, 'message' => 'No pending application was found in your approval scope.'], 404);
    }

    $newStatus = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';
    $verified = $decision === 'APPROVE' ? 1 : 0;
    $update = $conn->prepare("UPDATE users SET approval_status = ?, institution_verified = ? WHERE user_id = ? AND approval_status = 'PENDING'");
    if (!$update) throw new RuntimeException('Could not prepare the approval update.');
    $update->bind_param('sii', $newStatus, $verified, $targetId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The application changed before it could be reviewed. Refresh and try again.');
    }
    $update->close();

    $action = $decision === 'APPROVE' ? 'ROLE_APPLICATION_APPROVED' : 'ROLE_APPLICATION_REJECTED';
    $decisionLabel = $decision === 'APPROVE' ? 'approved' : 'rejected';
    $details = "Admin {$admin['username']} {$decisionLabel} {$target['role']} application for {$target['username']} (institution {$target['institution_id']}).";
    logAudit($conn, $adminId, $action, (int)$targetId, $details);
    jsonResponse([
        'success' => true,
        'approval_status' => $newStatus,
        'message' => $decision === 'APPROVE'
            ? "{$target['username']}'s {$target['role']} account is approved. They can now sign in."
            : "{$target['username']}'s role application was rejected."
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
