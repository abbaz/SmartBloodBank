<?php
// api/register.php - User Registration API
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$data = getRequestData();

// Promote an already approved regular User to Admin. This action is available
// only to an authenticated Admin and is scoped to that Admin's facility.
if (($data['action'] ?? '') === 'promote_to_admin') {
    $currentAdmin = requireAuth(['ADMIN']);
    $targetIdentity = trim((string)($data['username_or_email'] ?? ''));
    if ($targetIdentity === '') {
        jsonResponse(['success' => false, 'message' => 'Enter the regular User username or email address.'], 400);
    }

    $adminInstitution = strtoupper(trim((string)($currentAdmin['institutionId'] ?? $currentAdmin['institution_id'] ?? '')));
    $facilityId = requireInventoryInstitutionId($currentAdmin);
    if ($adminInstitution === '') {
        jsonResponse(['success' => false, 'message' => 'Your admin account is not linked to a facility.'], 403);
    }

    $conn = getDb();
    $conn->begin_transaction();
    try {
        $find = $conn->prepare(
            "SELECT user_id, username, full_name, role, approval_status FROM users " .
            "WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) LIMIT 1 FOR UPDATE"
        );
        if (!$find) throw new RuntimeException('Could not look up the User account.');
        $find->bind_param('ss', $targetIdentity, $targetIdentity);
        $find->execute();
        $target = $find->get_result()->fetch_assoc();
        $find->close();

        if (!$target) {
            $conn->rollback();
            jsonResponse(['success' => false, 'message' => 'No account was found for that username or email.'], 404);
        }
        if ($target['role'] !== 'USER' || ($target['approval_status'] ?? 'APPROVED') !== 'APPROVED') {
            $conn->rollback();
            jsonResponse(['success' => false, 'message' => 'Only an approved regular User can be promoted with this action.'], 409);
        }

        $update = $conn->prepare("UPDATE users SET role = 'ADMIN', institution_id = ?, institution_verified = 1 WHERE user_id = ? AND role = 'USER' AND approval_status = 'APPROVED'");
        if (!$update) throw new RuntimeException('Could not prepare the role update.');
        $targetId = (int)$target['user_id'];
        $update->bind_param('si', $adminInstitution, $targetId);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('The account could not be promoted. It may have changed; refresh and try again.');
        }
        $update->close();

        logAudit(
            $conn,
            (int)($currentAdmin['userId'] ?? $currentAdmin['user_id'] ?? 0),
            'USER_ROLE_PROMOTED',
            $targetId,
            "Regular user {$target['username']} promoted to ADMIN for facility {$facilityId}."
        );

        jsonResponse([
            'success' => true,
            'message' => "{$target['username']} was promoted to Admin for your facility. They must sign in again for the new role to take effect.",
            'user' => [
                'userId' => $targetId,
                'username' => $target['username'],
                'fullName' => $target['full_name'],
                'role' => 'ADMIN',
                'institutionId' => $adminInstitution,
                'institutionVerified' => true
            ]
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// Extract & sanitize fields
$fullName      = trim($data['fullName'] ?? $data['full_name'] ?? '');
$username      = trim($data['username'] ?? '');
$email         = trim(strtolower($data['email'] ?? ''));
$phone         = preg_replace('/[\s-]/', '', trim($data['phone'] ?? ''));
$institutionId = strtoupper(trim($data['institutionId'] ?? $data['institution_id'] ?? ''));
$adminProvision = false;
if (($_SESSION['user']['role'] ?? '') === 'ADMIN') {
    requireAuth(['ADMIN']);
    $adminProvision = true;
}
$role          = $adminProvision ? trim($data['role'] ?? '') : trim((string)($data['role'] ?? 'USER'));
$allowedSignupRoles = ['USER', 'STAFF', 'HOSPITAL_REQUESTER', 'LAB_TECHNICIAN'];
$approvalStatus = $adminProvision || $role === 'USER' ? 'APPROVED' : 'PENDING';
$institutionVerified = $adminProvision ? 1 : 0;
if ($adminProvision && in_array($role, ['STAFF', 'LAB_TECHNICIAN'], true)) {
    $institutionId = strtoupper(trim((string)($_SESSION['user']['institutionId'] ?? $_SESSION['user']['institution_id'] ?? '')));
}
$bloodGroup    = trim($data['bloodGroup'] ?? $data['blood_group'] ?? '');
$password      = $data['password'] ?? '';

// Validation
$allowedBlood = ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'];

if (!isValidFullName($fullName)) {
    jsonResponse(['success' => false, 'message' => 'Full name must be 3-60 characters using letters, spaces, apostrophes, periods, or hyphens.'], 400);
}
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{3,19}$/', $username)) {
    jsonResponse(['success' => false, 'message' => 'Username must be 4-20 characters, start with a letter, and contain only letters, numbers, or underscores.'], 400);
}
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Please provide a valid email address.'], 400);
}
if (!isValidPhoneNumber($phone)) {
    jsonResponse(['success' => false, 'message' => 'Enter a valid Pakistani mobile number, such as 03001234567 or +923001234567.'], 400);
}
if ($role === 'USER') {
    if (!isValidIntegerRange($institutionId, 1, 2147483647)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid preferred blood bank facility ID.'], 400);
    }
    if (!filter_var($data['donorConsent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        jsonResponse(['success' => false, 'message' => 'Consent is required to create and review your donor profile.'], 400);
    }
} elseif (!preg_match('/^[A-Z0-9-]{2,12}$/', $institutionId)) {
    jsonResponse(['success' => false, 'message' => 'Institution ID must contain 2-12 letters, numbers, or hyphens.'], 400);
}
if (!$adminProvision && !in_array($role, $allowedSignupRoles, true)) {
    jsonResponse(['success' => false, 'message' => 'Choose User, Blood Bank Staff, Hospital Requester, or Laboratory Technician. Admin access is assigned only by an existing Admin.'], 400);
}
if ($adminProvision && !in_array($role, ['STAFF', 'LAB_TECHNICIAN', 'HOSPITAL_REQUESTER'], true)) {
    jsonResponse(['success' => false, 'message' => 'Administrators may provision staff, lab technicians, or hospital requesters.'], 400);
}
if (!in_array($bloodGroup, $allowedBlood, true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid blood group selected.'], 400);
}
if (!isValidStrongPassword($password)) {
    jsonResponse(['success' => false, 'message' => 'Password must be 8-64 characters and include uppercase, lowercase, a number, and a special character.'], 400);
}

$conn = getDb();
if ($role === 'USER' && !isKnownInventoryFacilityId($conn, (int)$institutionId)) {
    jsonResponse(['success' => false, 'message' => 'That blood bank facility is not configured in this installation.'], 422);
}

// Check if username already exists
$checkUser = $conn->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
$checkUser->bind_param("s", $username);
$checkUser->execute();
$resUser = $checkUser->get_result();
if ($resUser && $resUser->num_rows > 0) {
    $checkUser->close();
    jsonResponse(['success' => false, 'message' => 'That username is already taken.'], 409);
}
$checkUser->close();

// Check if email already exists
$checkEmail = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
$checkEmail->bind_param("s", $email);
$checkEmail->execute();
$resEmail = $checkEmail->get_result();
if ($resEmail && $resEmail->num_rows > 0) {
    $checkEmail->close();
    jsonResponse(['success' => false, 'message' => 'An account with this email already exists.'], 409);
}
$checkEmail->close();

// Hash password
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

// Insert user
$conn->begin_transaction();
$insert = $conn->prepare(
    "INSERT INTO users (username, full_name, email, phone, password_hash, role, blood_group, institution_id, institution_verified, approval_status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
);

if (!$insert) {
    jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
}

$insert->bind_param(
    "ssssssssis",
    $username,
    $fullName,
    $email,
    $phone,
    $passwordHash,
    $role,
    $bloodGroup,
    $institutionId,
    $institutionVerified,
    $approvalStatus
);

if (!$insert->execute()) {
    $err = $insert->error;
    $insert->close();
    jsonResponse(['success' => false, 'message' => 'Failed to create user account: ' . $err], 500);
}

$userId = $insert->insert_id;
$insert->close();

if ($role === 'USER') {
    $facilityId = (int)$institutionId;
    $donorGroup = toDbBloodGroup($bloodGroup);
    $profileInsert = $conn->prepare(
        "INSERT INTO donor_profiles (user_id, facility_id, blood_group, profile_status, consent_at) VALUES (?, ?, ?, 'PENDING', NOW())"
    );
    if (!$profileInsert) {
        jsonResponse(['success' => false, 'message' => 'Could not create your donor profile. No account changes were saved.'], 500);
    }
    $profileInsert->bind_param('iis', $userId, $facilityId, $donorGroup);
    if (!$profileInsert->execute()) {
        $profileInsert->close();
        jsonResponse(['success' => false, 'message' => 'Could not create your donor profile. No account changes were saved.'], 500);
    }
    $profileInsert->close();
    logAudit($conn, $userId, 'DONOR_PROFILE_SUBMITTED', $userId, "Donor profile submitted for facility {$facilityId}; staff verification pending.");
}

// Session payload
$userSession = [
    'userId'        => $userId,
    'username'      => $username,
    'email'         => $email,
    'role'          => $role,
    'fullName'      => $fullName,
    'institutionId' => $institutionId,
    'institutionVerified' => (bool)$institutionVerified,
    'approvalStatus' => $approvalStatus,
    'donorProfileStatus' => $role === 'USER' ? 'PENDING' : null,
    'bloodGroup'    => $bloodGroup
];

logAudit($conn, $adminProvision ? (int)($_SESSION['user']['userId'] ?? 0) : $userId, $adminProvision ? 'USER_PROVISIONED' : ($approvalStatus === 'PENDING' ? 'ROLE_APPLICATION_SUBMITTED' : 'USER_REGISTERED'), $userId, "User account created with role $role and status $approvalStatus" . ($adminProvision ? ' by administrator.' : ' through public registration.'));
if ($approvalStatus === 'APPROVED' && !$adminProvision) $_SESSION['user'] = $userSession;

$responseMessage = $approvalStatus === 'PENDING'
    ? 'Your role application was submitted. An Admin must approve it before you can sign in.'
    : ($adminProvision ? 'Account provisioned successfully.' : 'Donor account created. Your donor profile is awaiting staff verification.');

jsonResponse([
    'success' => true,
    'message' => $responseMessage,
    'approval_status' => $approvalStatus,
    'user'    => $approvalStatus === 'APPROVED' ? $userSession : null
], $approvalStatus === 'PENDING' ? 202 : 201);
