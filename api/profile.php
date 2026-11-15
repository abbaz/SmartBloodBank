<?php
// api/profile.php - User profile management, password change, and admin alert thresholds

require_once __DIR__ . '/db.php';

$user = requireAuth(['USER', 'STAFF', 'ADMIN', 'LAB_TECHNICIAN', 'HOSPITAL_REQUESTER'], true);
$conn = getDb();

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getRequestData();
    $action = $body['action'] ?? '';
} else {
    $action = $_GET['action'] ?? 'get_profile';
}

if (!empty($user['mustChangePassword']) && $action !== 'change_password') {
    jsonResponse([
        'success' => false,
        'password_change_required' => true,
        'message' => 'Change your temporary password before using the application.'
    ], 423);
}

// ─── Ensure settings table exists ───────────────────────────────────────────
@$conn->query("CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
    `setting_value` TEXT,
    `updated_by`    INT,
    `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── GET: return current user data ─────────────────────────────────────────
if ($action === 'get_profile') {
    $uid = (int)($user['userId'] ?? $user['user_id'] ?? 0);
    $stmt = $conn->prepare("SELECT user_id, full_name, email, username, role, institution_id, blood_group, phone, created_at FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'User record not found.'], 404);
    }

    $row['blood_group_display'] = toDisplayBloodGroup($row['blood_group'] ?? '');
    jsonResponse(['success' => true, 'user' => $row]);
}

// ─── POST: update profile ───────────────────────────────────────────────────
if ($action === 'update_profile') {
    $uid      = (int)($user['userId'] ?? $user['user_id'] ?? 0);
    $fullName = trim($body['full_name'] ?? '');
    $phone    = trim($body['phone'] ?? '');

    if (!isValidFullName($fullName, 2, 60)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid name using 2-60 letters, spaces, apostrophes, periods, or hyphens.'], 422);
    }
    if (!isValidPhoneNumber($phone, false)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid Pakistani mobile number or leave the phone field blank.'], 422);
    }

    $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
    $stmt->bind_param('ssi', $fullName, $phone, $uid);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        jsonResponse(['success' => false, 'message' => 'Profile update failed: ' . $conn->error], 500);
    }

    logAudit($conn, $uid, 'PROFILE_UPDATE', $uid, "Name updated to: {$fullName}");
    jsonResponse(['success' => true, 'message' => 'Profile updated successfully.']);
}

// ─── POST: change password ──────────────────────────────────────────────────
if ($action === 'change_password') {
    $uid     = (int)($user['userId'] ?? $user['user_id'] ?? 0);
    $oldPass = $body['old_password'] ?? '';
    $newPass = $body['new_password'] ?? '';
    $confirm = $body['confirm_password'] ?? '';

    if ($oldPass === '' || !isValidStrongPassword($newPass)) {
        jsonResponse(['success' => false, 'message' => 'Enter your current password and a new password of 8-64 characters with uppercase, lowercase, a number, and a special character.'], 422);
    }
    if ($newPass !== $confirm) {
        jsonResponse(['success' => false, 'message' => 'New password and confirmation do not match.'], 422);
    }
    if (!empty($user['mustChangePassword']) && $newPass === $oldPass) {
        jsonResponse(['success' => false, 'message' => 'Choose a new password different from the temporary password.'], 422);
    }

    // Fetch current hash
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($oldPass, $row['password_hash'])) {
        jsonResponse(['success' => false, 'message' => 'Current password is incorrect.'], 403);
    }

    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?");
    $stmt->bind_param('si', $newHash, $uid);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        jsonResponse(['success' => false, 'message' => 'Password update failed.'], 500);
    }

    logAudit($conn, $uid, 'PASSWORD_CHANGE', $uid, 'Password changed by user.');
    $_SESSION['user']['mustChangePassword'] = false;
    jsonResponse(['success' => true, 'message' => 'Password changed successfully. Please sign in again.']);
}

// ─── GET: get alert thresholds (Admin only) ──────────────────────────────────
if ($action === 'get_thresholds') {
    if ($user['role'] !== 'ADMIN') {
        jsonResponse(['success' => false, 'message' => 'Admin access required.'], 403);
    }

    $defaults = [
        'A_POSITIVE'  => 5, 'A_NEGATIVE'  => 3,
        'B_POSITIVE'  => 5, 'B_NEGATIVE'  => 3,
        'O_POSITIVE'  => 8, 'O_NEGATIVE'  => 5,
        'AB_POSITIVE' => 3, 'AB_NEGATIVE' => 2,
        'NEAR_EXPIRY_DAYS' => 7,
    ];

    $institutionId = requireInventoryInstitutionId($user);
    $thresholds = $defaults;
    $prefix = 'threshold_' . $institutionId . '_';
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE LEFT(setting_key, CHAR_LENGTH(?)) = ?");
    $stmt->bind_param('ss', $prefix, $prefix); $stmt->execute(); $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $group = substr($row['setting_key'], strlen($prefix));
            $thresholds[$group] = (int)$row['setting_value'];
        }
    }
    $stmt->close();

    jsonResponse(['success' => true, 'thresholds' => $thresholds]);
}

// ─── POST: save alert thresholds (Admin only) ───────────────────────────────
if ($action === 'save_thresholds') {
    if ($user['role'] !== 'ADMIN') {
        jsonResponse(['success' => false, 'message' => 'Admin access required.'], 403);
    }

    $thresholds = $body['thresholds'] ?? [];
    if (!is_array($thresholds) || count($thresholds) === 0) {
        jsonResponse(['success' => false, 'message' => 'No threshold data provided.'], 422);
    }

    $allowed = ['A_POSITIVE','A_NEGATIVE','B_POSITIVE','B_NEGATIVE','O_POSITIVE','O_NEGATIVE','AB_POSITIVE','AB_NEGATIVE'];
    $uid = (int)($user['userId'] ?? $user['user_id'] ?? 0);
    $institutionId = requireInventoryInstitutionId($user);

    $allowedKeys = array_merge($allowed, ['NEAR_EXPIRY_DAYS']);
    foreach ($thresholds as $group => $value) {
        if (!in_array($group, $allowedKeys, true)) {
            jsonResponse(['success' => false, 'message' => 'An unsupported alert threshold was supplied.'], 422);
        }
        $minValue = $group === 'NEAR_EXPIRY_DAYS' ? 1 : 0;
        $maxValue = $group === 'NEAR_EXPIRY_DAYS' ? 60 : 100;
        if (!isValidIntegerRange($value, $minValue, $maxValue)) {
            jsonResponse(['success' => false, 'message' => "Threshold $group must be a whole number between $minValue and $maxValue."], 422);
        }
        if ($group === 'NEAR_EXPIRY_DAYS') {
            $key = 'threshold_' . $institutionId . '_NEAR_EXPIRY_DAYS';
            $val = (int)$value;
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
            $stmt->bind_param('ssi', $key, $val, $uid);
            $stmt->execute();
            $stmt->close();
            continue;
        }
        if (!in_array($group, $allowed, true)) continue;
        $key = 'threshold_' . $institutionId . '_' . $group;
        $val = (int)$value;
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
        $stmt->bind_param('ssi', $key, $val, $uid);
        $stmt->execute();
        $stmt->close();
    }

    logAudit($conn, $uid, 'THRESHOLD_UPDATE', null, 'Alert thresholds updated by admin.');
    jsonResponse(['success' => true, 'message' => 'Alert thresholds saved.']);
}

// ─── GET: get current stock vs thresholds (all logged-in users) ─────────────
if ($action === 'stock_alerts') {
    if (!in_array($user['role'] ?? '', ['STAFF', 'ADMIN', 'LAB_TECHNICIAN'], true)) {
        jsonResponse(['success' => false, 'message' => 'Inventory alert access is restricted to blood-bank staff.'], 403);
    }
    $institutionId = requireInventoryInstitutionId($user);
    // Fetch available stock counts per blood group
    $stockStmt = $conn->prepare("SELECT blood_group, COUNT(*) as qty FROM blood_units WHERE status = 'AVAILABLE' AND institution_id = ? GROUP BY blood_group");
    $stock = [];
    if ($stockStmt) {
        $stockStmt->bind_param('i', $institutionId);
        $stockStmt->execute();
        $stockRes = $stockStmt->get_result();
        while ($r = $stockRes->fetch_assoc()) {
            $stock[$r['blood_group']] = (int)$r['qty'];
        }
        $stockStmt->close();
    }

    // Fetch thresholds from settings
    $defaults = [
        'A_POSITIVE'=>5,'A_NEGATIVE'=>3,'B_POSITIVE'=>5,'B_NEGATIVE'=>3,
        'O_POSITIVE'=>8,'O_NEGATIVE'=>5,'AB_POSITIVE'=>3,'AB_NEGATIVE'=>2,
    ];
    $thresholds = $defaults;
    $institutionId = requireInventoryInstitutionId($user);
    $prefix = 'threshold_' . $institutionId . '_';
    $settingsStmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE LEFT(setting_key, CHAR_LENGTH(?)) = ?");
    $settingsStmt->bind_param('ss', $prefix, $prefix); $settingsStmt->execute(); $res = $settingsStmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $group = substr($row['setting_key'], strlen($prefix));
            $thresholds[$group] = (int)$row['setting_value'];
        }
    }

    $alerts = [];
    $nearExpiryDays = 7;
    $nearKey = 'threshold_' . $institutionId . '_NEAR_EXPIRY_DAYS';
    $nearSettingsStmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $nearSettingsStmt->bind_param('s', $nearKey); $nearSettingsStmt->execute(); $nearSetting = $nearSettingsStmt->get_result();
    $nearSettingsStmt->close();
    if ($nearSetting && $nearSetting->num_rows) $nearExpiryDays = min(60, max(1, (int)$nearSetting->fetch_assoc()['setting_value']));
    foreach ($thresholds as $group => $minQty) {
        if ($minQty <= 0) continue;
        $current = $stock[$group] ?? 0;
        if ($current <= $minQty) {
            $alerts[] = [
                'blood_group'   => $group,
                'display'       => toDisplayBloodGroup($group),
                'current_stock' => $current,
                'threshold'     => $minQty,
                'critical'      => $current === 0,
            ];
        }
    }

    $nearStmt = $conn->prepare("SELECT COUNT(*) AS qty FROM blood_units WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND status IN ('AVAILABLE','QUARANTINED','RESERVED') AND institution_id = ?");
    $nearStmt->bind_param('ii', $nearExpiryDays, $institutionId);
    $nearStmt->execute();
    $nearRes = $nearStmt->get_result();
    $nearCount = ($nearRes && $nearRow = $nearRes->fetch_assoc()) ? (int)$nearRow['qty'] : 0;
    $nearStmt->close();
    $settingsStmt->close();
    if ($nearCount > 0) {
        $alerts[] = ['type' => 'NEAR_EXPIRY', 'current_stock' => $nearCount, 'threshold' => $nearExpiryDays, 'days' => $nearExpiryDays];
    }

    jsonResponse(['success' => true, 'alerts' => $alerts]);
}

jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
