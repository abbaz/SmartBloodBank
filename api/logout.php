<?php
// api/logout.php - Log out user and destroy session
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

jsonResponse([
    'success' => true,
    'message' => 'Logged out successfully.'
]);
