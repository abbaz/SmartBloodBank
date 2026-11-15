<?php
// One-time, facility-scoped temporary password reset for administrators.
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$admin = requireAuth(['ADMIN']);
$data = getRequestData();
$identity = trim((string)($data['username_or_email'] ?? ''));
if ($identity === '' || strlen($identity) > 254) {
    jsonResponse(['success' => false, 'message' => 'Enter the account username or email address.'], 422);
}

$adminId = (int)($admin['userId'] ?? $admin['user_id'] ?? 0);
$facilityCodes = userInstitutionCodes($admin);
$conn = getDb();

// Generate a strong temporary password with guaranteed character classes.
$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*_-+=';
$temporaryPassword = 'Aa1!';
for ($i = 0; $i < 20; $i++) {
    $temporaryPassword .= $chars[random_int(0, strlen($chars) - 1)];
}
$tempChars = str_split($temporaryPassword);
for ($i = count($tempChars) - 1; $i > 0; $i--) {
    $j = random_int(0, $i);
    [$tempChars[$i], $tempChars[$j]] = [$tempChars[$j], $tempChars[$i]];
}
$temporaryPassword = implode('', $tempChars);
$temporaryHash = password_hash($temporaryPassword, PASSWORD_BCRYPT);

$conn->begin_transaction();
try {
    $find = $conn->prepare(
        'SELECT user_id, username FROM users ' .
        'WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)) ' .
        'AND institution_id IN (?, ?) AND user_id <> ? LIMIT 1 FOR UPDATE'
    );
    if (!$find) throw new RuntimeException('Could not look up the account.');
    $find->bind_param('ssssi', $identity, $identity, $facilityCodes[0], $facilityCodes[1], $adminId);
    if (!$find->execute()) throw new RuntimeException('Could not look up the account.');
    $target = $find->get_result()->fetch_assoc();
    $find->close();

    if (!$target) {
        $conn->rollback();
        jsonResponse(['success' => false, 'message' => 'No other account with that username or email was found in your facility.'], 404);
    }

    $targetId = (int)$target['user_id'];
    $update = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?');
    if (!$update) throw new RuntimeException('Could not prepare the password reset.');
    $update->bind_param('si', $temporaryHash, $targetId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The password reset could not be saved.');
    }
    $update->close();

    if (!logAudit($conn, $adminId, 'ADMIN_PASSWORD_RESET', $targetId, "Temporary password issued for user {$target['username']}; change required at next sign-in.")) {
        throw new RuntimeException('The reset could not be recorded.');
    }

    jsonResponse([
        'success' => true,
        'message' => 'Temporary password created. Share it with the user through a private channel; it will be shown only once.',
        'username' => $target['username'],
        'temporary_password' => $temporaryPassword,
        'must_change_password' => true
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    jsonResponse(['success' => false, 'message' => 'Password reset failed. No changes were saved.'], 500);
}
