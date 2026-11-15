<?php
// api/db.php - Database connection for Smart Blood Bank

// Start PHP session cleanly
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * Returns an active MySQLi database connection.
 * Automatically detects whether 'smart blood bank' (with space) or 'smart_blood_bank' (with underscore)
 * contains the project tables, handles WAMP ports (3306, 3307, 3308), and auto-creates tables if missing.
 */
function getDb() {
    static $conn = null;
    if ($conn !== null && @$conn->ping()) {
        return $conn;
    }

    $host = '127.0.0.1';
    $user = 'root';
    $pass = '';
    
    // WAMP ports (3306 default MySQL, 3307/3308 MariaDB/MySQL alternate)
    $ports = [3306, 3307, 3308];
    // A database override is useful for isolated integration tests and deployments.
    // When set, fail closed instead of falling back to a different Smart Blood Bank DB.
    $dbOverride = trim((string)getenv('SBB_DB_NAME'));
    // Notice: 'smart blood bank' (with space) is where tables were created in phpMyAdmin
    $dbCandidates = $dbOverride !== '' ? [$dbOverride] : ['smart blood bank', 'smart_blood_bank'];

    $lastError = '';

    // Pass 1: Look for a database that already has the `users` table
    foreach ($ports as $port) {
        foreach ($dbCandidates as $dbName) {
            try {
                $testConn = @new mysqli($host, $user, $pass, $dbName, $port);
                if (!$testConn->connect_error) {
                    $tblCheck = @$testConn->query("SHOW TABLES LIKE 'users'");
                    if ($tblCheck && $tblCheck->num_rows > 0) {
                        $testConn->set_charset('utf8mb4');
                        $conn = $testConn;
                        ensureTablesExist($conn);
                        requireTransactionalTables($conn);
                        $conn->autocommit(false);
                        $GLOBALS['SBB_DB_CONNECTION'] = $conn;
                        return $conn;
                    }
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
    }

    // Pass 2: Connect to any existing smart blood bank DB or create it
    foreach ($ports as $port) {
        foreach ($dbCandidates as $dbName) {
            try {
                $testConn = @new mysqli($host, $user, $pass, $dbName, $port);
                if (!$testConn->connect_error) {
                    $testConn->set_charset('utf8mb4');
                    $conn = $testConn;
                    ensureTablesExist($conn);
                    requireTransactionalTables($conn);
                    $conn->autocommit(false);
                    $GLOBALS['SBB_DB_CONNECTION'] = $conn;
                    return $conn;
                }
                $lastError = $testConn->connect_error;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        // Pass 3: Inspect SHOW DATABASES for any wildcard match
        try {
            if ($dbOverride !== '') break;
            $rootConn = @new mysqli($host, $user, $pass, '', $port);
            if (!$rootConn->connect_error) {
                $res = $rootConn->query("SHOW DATABASES LIKE 'smart%blood%bank'");
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_array();
                    $matchedDb = $row[0];
                    $rootConn->select_db($matchedDb);
                    $rootConn->set_charset('utf8mb4');
                    $conn = $rootConn;
                    ensureTablesExist($conn);
                    requireTransactionalTables($conn);
                    $conn->autocommit(false);
                    $GLOBALS['SBB_DB_CONNECTION'] = $conn;
                    return $conn;
                }
            }
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    // If still not connected, output error JSON
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . ($lastError ?: 'Unable to connect to MySQL on localhost:3306. Please ensure WAMP is running (green icon).')
    ]);
    exit;
}

/** Reject writes unless every core mutable table can participate in transactions. */
function requireTransactionalTables($conn) {
    $required = ['users', 'contact_messages', 'donors', 'blood_units', 'blood_requests', 'transactions', 'audit_logs', 'settings'];
    $escaped = array_map(function ($name) use ($conn) {
        return "'" . $conn->real_escape_string($name) . "'";
    }, $required);
    $result = @$conn->query(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES " .
        "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', $escaped) . ")"
    );
    $engines = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $engines[$row['TABLE_NAME']] = strtoupper((string)$row['ENGINE']);
        }
    }
    $invalid = [];
    foreach ($required as $table) {
        $engine = $engines[$table] ?? null;
        if ($engine !== 'INNODB') {
            $invalid[] = $table . ' (' . ($engine ?: 'missing') . ')';
        }
    }
    if ($invalid) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Transactional storage is required before the application can accept changes. Import database-transactional-engines.sql. Tables needing migration: ' . implode(', ', $invalid) . '.'
        ]);
        exit;
    }
}

/**
 * Ensures essential tables exist in the connected database.
 */
function ensureTablesExist($conn) {
    @$conn->query("CREATE TABLE IF NOT EXISTS `users` (
      `user_id` int NOT NULL AUTO_INCREMENT,
      `username` varchar(50) NOT NULL UNIQUE,
      `full_name` varchar(60) NOT NULL,
      `email` varchar(120) NOT NULL UNIQUE,
      `phone` varchar(20) DEFAULT NULL,
      `password_hash` varchar(255) NOT NULL,
      `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
      `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER') NOT NULL,
      `blood_group` varchar(8) DEFAULT NULL,
      `institution_id` varchar(12) NOT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS `contact_messages` (
      `message_id` int NOT NULL AUTO_INCREMENT,
      `full_name` varchar(60) NOT NULL,
      `email` varchar(120) NOT NULL,
      `phone` varchar(20) DEFAULT NULL,
      `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER') NOT NULL,
      `subject` varchar(80) NOT NULL,
      `message` varchar(1000) NOT NULL,
      `sent_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $verifiedColumn = @$conn->query("SHOW COLUMNS FROM `users` LIKE 'institution_verified'");
    if ($verifiedColumn && $verifiedColumn->num_rows === 0) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `institution_verified` tinyint(1) NOT NULL DEFAULT 0 AFTER `institution_id`");
    }

    $passwordResetColumn = @$conn->query("SHOW COLUMNS FROM `users` LIKE 'must_change_password'");
    if ($passwordResetColumn && $passwordResetColumn->num_rows === 0) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `must_change_password` tinyint(1) NOT NULL DEFAULT 0 AFTER `password_hash`");
    }

    @$conn->query("CREATE TABLE IF NOT EXISTS `audit_logs` (
      `log_id` int NOT NULL AUTO_INCREMENT,
      `user_id` int DEFAULT NULL,
      `action` varchar(80) NOT NULL,
      `entity_id` int DEFAULT NULL,
      `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `details` varchar(500) DEFAULT NULL,
      PRIMARY KEY (`log_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Older local databases may predate the hospital reference field.
    $requestColumns = @$conn->query("SHOW COLUMNS FROM `blood_requests` LIKE 'patient_ref'");
    if ($requestColumns && $requestColumns->num_rows === 0) {
        @$conn->query("ALTER TABLE `blood_requests` ADD COLUMN `patient_ref` varchar(150) DEFAULT NULL AFTER `request_date`");
    }

    // Assign legacy requests to the installation's default blood-bank facility.
    $servingColumns = @$conn->query("SHOW COLUMNS FROM `blood_requests` LIKE 'serving_institution_id'");
    if ($servingColumns && $servingColumns->num_rows === 0) {
        @$conn->query("ALTER TABLE `blood_requests` ADD COLUMN `serving_institution_id` int NOT NULL DEFAULT 1 AFTER `submitted_by`");
    }

    @$conn->query("CREATE TABLE IF NOT EXISTS `settings` (
      `setting_key` varchar(100) NOT NULL PRIMARY KEY,
      `setting_value` text,
      `updated_by` int DEFAULT NULL,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Sends a JSON response with proper headers and exits.
 */
function jsonResponse($data, $statusCode = 200) {
    $conn = $GLOBALS['SBB_DB_CONNECTION'] ?? null;
    if ($conn instanceof mysqli) {
        if ($statusCode >= 400) {
            @$conn->rollback();
        } else {
            @$conn->commit();
        }
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

/**
 * Maps display blood group (e.g. O+) to DB enum (e.g. O_POSITIVE).
 */
function toDbBloodGroup($group) {
    $clean = strtoupper(trim((string)$group));
    $map = [
        'O-'  => 'O_NEGATIVE',
        'O+'  => 'O_POSITIVE',
        'A-'  => 'A_NEGATIVE',
        'A+'  => 'A_POSITIVE',
        'B-'  => 'B_NEGATIVE',
        'B+'  => 'B_POSITIVE',
        'AB-' => 'AB_NEGATIVE',
        'AB+' => 'AB_POSITIVE',
    ];
    return $map[$clean] ?? $clean;
}

/**
 * Maps DB enum (e.g. O_POSITIVE) to display blood group (e.g. O+).
 */
function toDisplayBloodGroup($dbGroup) {
    $clean = strtoupper(trim((string)$dbGroup));
    $map = [
        'O_NEGATIVE'  => 'O-',
        'O_POSITIVE'  => 'O+',
        'A_NEGATIVE'  => 'A-',
        'A_POSITIVE'  => 'A+',
        'B_NEGATIVE'  => 'B-',
        'B_POSITIVE'  => 'B+',
        'AB_NEGATIVE' => 'AB-',
        'AB_POSITIVE' => 'AB+',
    ];
    return $map[$clean] ?? $clean;
}

/**
 * Helper to ensure current user is logged in, optionally checking allowed roles.
 */
function requireAuth($allowedRoles = [], $allowPasswordChange = false) {
    if (empty($_SESSION['user'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required. Please sign in.'], 401);
    }
    $sessionUserId = (int)($_SESSION['user']['userId'] ?? $_SESSION['user']['user_id'] ?? 0);
    if ($sessionUserId > 0) {
        $authConn = getDb();
        $resetCheck = $authConn->prepare('SELECT must_change_password FROM users WHERE user_id = ? LIMIT 1');
        if (!$resetCheck) {
            jsonResponse(['success' => false, 'message' => 'Could not verify account status.'], 503);
        }
        $resetCheck->bind_param('i', $sessionUserId);
        $resetCheck->execute();
        $account = $resetCheck->get_result()->fetch_assoc();
        $resetCheck->close();
        if (!$account) {
            jsonResponse(['success' => false, 'message' => 'This account is no longer available. Please sign in again.'], 401);
        }
        $_SESSION['user']['mustChangePassword'] = (bool)$account['must_change_password'];
    }
    if (!empty($allowedRoles)) {
        $userRole = $_SESSION['user']['role'] ?? '';
        if (!in_array($userRole, $allowedRoles, true) && $userRole !== 'ADMIN') {
            jsonResponse(['success' => false, 'message' => 'Access denied for your role (' . $userRole . ').'], 403);
        }
    }
    if (!empty($_SESSION['user']['mustChangePassword']) && !$allowPasswordChange) {
        jsonResponse([
            'success' => false,
            'password_change_required' => true,
            'message' => 'Change your temporary password before using the application.'
        ], 423);
    }
    return $_SESSION['user'];
}

/**
 * Resolve the account's facility to the integer key used by inventory records.
 * The demo account's legacy FUSST-BB label is the existing facility 1 used by
 * the WAMP seed data; unsupported/non-numeric labels fail closed.
 */
function requireInventoryInstitutionId($user) {
    $raw = strtoupper(trim((string)($user['institutionId'] ?? $user['institution_id'] ?? '')));
    if ($raw === 'FUSST-BB') return 1;
    if (preg_match('/^[1-9][0-9]*$/', $raw) && (float)$raw <= 2147483647) {
        return (int)$raw;
    }
    jsonResponse(['success' => false, 'message' => 'Your account is not linked to a supported inventory facility.'], 403);
}

/** Facility receiving new hospital requests in this single-bank installation. */
function defaultServingInstitutionId() {
    $raw = trim((string)getenv('SBB_DEFAULT_FACILITY_ID'));
    return preg_match('/^[1-9][0-9]*$/', $raw) && (float)$raw <= 2147483647 ? (int)$raw : 1;
}

/** Return both legacy and canonical string labels for donor-manager scoping. */
function userInstitutionCodes($user) {
    $raw = trim((string)($user['institutionId'] ?? $user['institution_id'] ?? ''));
    if ($raw === '') return ['', ''];
    if (strtoupper($raw) === 'FUSST-BB') return ['FUSST-BB', '1'];
    return [$raw, $raw];
}

/**
 * Retrieves request data either from JSON body or $_POST.
 */
function getRequestData() {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
    }
    return $_POST;
}

/** Shared server-side validation rules for values submitted by browser forms. */
function isValidDateValue($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value;
}

function isValidFullName($value, $minLength = 3, $maxLength = 60) {
    return is_string($value) && preg_match("/^[A-Za-z][A-Za-z .'-]{" . ($minLength - 1) . ',' . ($maxLength - 1) . "}$/", trim($value)) === 1;
}

function isValidPhoneNumber($value, $required = true) {
    if (!is_string($value)) return false;
    $phone = preg_replace('/[\s-]/', '', trim($value));
    if ($phone === '') return !$required;
    return preg_match('/^(?:\\+92|0)3[0-9]{9}$/', $phone) === 1;
}

function isValidPhoneOrEmail($value) {
    if (!is_string($value)) return false;
    $clean = trim($value);
    return isValidPhoneNumber($clean, false) || (strlen($clean) <= 254 && filter_var($clean, FILTER_VALIDATE_EMAIL) !== false);
}

function isDateNoLaterThanToday($value) {
    return isValidDateValue($value) && $value <= (new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
}

function isValidStrongPassword($value) {
    return is_string($value) && preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*?&#^()_\\-+=.]).{8,64}$/', $value) === 1;
}

function isValidIntegerRange($value, $min, $max) {
    if (!is_int($value) && !is_string($value)) return false;
    $text = (string)$value;
    if (!preg_match('/^(0|[1-9][0-9]*)$/', $text)) return false;
    $number = filter_var($text, FILTER_VALIDATE_INT);
    return $number !== false && $number >= $min && $number <= $max;
}

/**
 * Helper to log actions into audit_logs table if it exists.
 */
function logAudit($conn, $userId, $action, $entityId = null, $details = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, entity_id, timestamp, details) VALUES (?, ?, ?, NOW(), ?)");
        if (!$stmt) {
            jsonResponse(['success' => false, 'message' => 'The change could not be recorded in the audit trail. No changes were saved.'], 500);
        }
        $stmt->bind_param("isis", $userId, $action, $entityId, $details);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            jsonResponse(['success' => false, 'message' => 'The change could not be recorded in the audit trail. No changes were saved.'], 500);
        }
        return true;
    } catch (Throwable $t) {
        jsonResponse(['success' => false, 'message' => 'The change could not be recorded in the audit trail. No changes were saved.'], 500);
    }
}

/**
 * Ensures demo staff account exists in MySQL users table.
 */
function ensureDemoUserExists($conn) {
    try {
        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1");
        if (!$check) return;
        $demoEmail = 'staff@bloodbank.pk';
        $demoUser = 'staffdemo';
        $check->bind_param("ss", $demoEmail, $demoUser);
        $check->execute();
        $res = $check->get_result();
        if ($res && $res->num_rows === 0) {
            $check->close();
            $insert = $conn->prepare("INSERT INTO users (username, full_name, email, phone, password_hash, role, blood_group, institution_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($insert) {
                $fullName = 'Demo Staff';
                $phone = '03001234567';
                $pwdHash = password_hash('Staff@123', PASSWORD_BCRYPT);
                $role = 'STAFF';
                $bloodGroup = 'O+';
                $institutionId = 'FUSST-BB';
                $insert->bind_param("ssssssss", $demoUser, $fullName, $demoEmail, $phone, $pwdHash, $role, $bloodGroup, $institutionId);
                $insert->execute();
                $insert->close();
            }
        } else {
            $check->close();
        }
    } catch (Throwable $t) {
        // Ignore if schema mismatch
    }
}
