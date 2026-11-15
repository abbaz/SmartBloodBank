<?php
// api/test_db.php - Health check and table verification
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'ERROR', 'message' => 'Method not allowed.'], 405);
}
requireAuth(['ADMIN']);

try {
    $conn = getDb();

    // Get current database name
    $resDb = $conn->query("SELECT DATABASE()");
    $currentDb = ($resDb && $row = $resDb->fetch_array()) ? $row[0] : 'unknown';

    // Count rows in existing tables
    $tables = ['users', 'contact_messages', 'donors', 'blood_units', 'blood_requests', 'transactions', 'audit_logs'];
    $tableCounts = [];

    foreach ($tables as $tbl) {
        $countRes = $conn->query("SELECT COUNT(*) FROM `$tbl`");
        if ($countRes) {
            $tableCounts[$tbl] = (int)$countRes->fetch_array()[0];
        } else {
            $tableCounts[$tbl] = 'Table not found or error';
        }
    }

    jsonResponse([
        'status'         => 'OK',
        'message'        => 'MySQL database connection successful via WAMP!',
        'database_name'  => $currentDb,
        'table_counts'   => $tableCounts
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'status'  => 'ERROR',
        'message' => $e->getMessage()
    ], 500);
}
