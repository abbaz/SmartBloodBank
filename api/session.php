<?php
// api/session.php - Check current user session
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (isset($_SESSION['user'])) {
    jsonResponse([
        'authenticated' => true,
        'user'          => $_SESSION['user']
    ]);
} else {
    jsonResponse([
        'authenticated' => false,
        'user'          => null
    ]);
}
