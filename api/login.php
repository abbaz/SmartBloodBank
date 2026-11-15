<?php
// api/login.php - User Login API
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$data = getRequestData();
$identity = trim($data['identity'] ?? '');
$password = $data['password'] ?? '';

if (empty($identity)) {
    jsonResponse(['success' => false, 'message' => 'Username or email is required.'], 400);
}
if (empty($password)) {
    jsonResponse(['success' => false, 'message' => 'Password is required.'], 400);
}

$conn = getDb();

$stmt = $conn->prepare(
    "SELECT user_id, username, full_name, email, phone, password_hash, must_change_password, role, blood_group, institution_id, institution_verified, approval_status
     FROM users
     WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)
     LIMIT 1"
);

if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
}

$stmt->bind_param("ss", $identity, $identity);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    $stmt->close();
    jsonResponse(['success' => false, 'message' => 'No account found for that username or email.'], 404);
}

$user = $result->fetch_assoc();
$stmt->close();

$storedHash = $user['password_hash'];
$authenticated = false;
$needsRehash = false;

// 1. Check standard PHP bcrypt / argon2 hash
if (password_verify($password, $storedHash)) {
    $authenticated = true;
    if (password_needs_rehash($storedHash, PASSWORD_BCRYPT)) {
        $needsRehash = true;
    }
}
// 2. Check SHA-256 hash (prototype legacy)
elseif (hash('sha256', $password) === $storedHash) {
    $authenticated = true;
    $needsRehash = true;
}
// 3. Check plain text (for initial phpMyAdmin test seeds like @123)
elseif ($password === $storedHash) {
    $authenticated = true;
    $needsRehash = true;
}

if (!$authenticated) {
    jsonResponse(['success' => false, 'message' => 'Incorrect password.'], 401);
}

if (($user['approval_status'] ?? 'APPROVED') !== 'APPROVED') {
    $message = $user['approval_status'] === 'REJECTED'
        ? 'This role application was not approved. Contact the blood bank administrator for help.'
        : 'Your account request is waiting for Admin approval. You can sign in after it is approved.';
    jsonResponse(['success' => false, 'message' => $message, 'approval_status' => $user['approval_status']], 403);
}

// Upgrade password hash to bcrypt if it was legacy/plain
if ($needsRehash) {
    $newHash = password_hash($password, PASSWORD_BCRYPT);
    $upStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    if ($upStmt) {
        $upStmt->bind_param("si", $newHash, $user['user_id']);
        $upStmt->execute();
        $upStmt->close();
    }
}

$userSession = [
    'userId'        => (int)$user['user_id'],
    'username'      => $user['username'],
    'email'         => $user['email'],
    'role'          => $user['role'],
    'fullName'      => $user['full_name'],
    'institutionId' => $user['institution_id'],
    'institutionVerified' => (bool)$user['institution_verified'],
    'approvalStatus' => $user['approval_status'] ?? 'APPROVED',
    'mustChangePassword' => (bool)$user['must_change_password'],
    'bloodGroup'    => $user['blood_group'] ?? ''
];

logAudit($conn, $user['user_id'], 'USER_LOGIN', $user['user_id'], 'User signed in');
$_SESSION['user'] = $userSession;

jsonResponse([
    'success' => true,
    'message' => 'Signed in successfully.',
    'user'    => $userSession
]);
