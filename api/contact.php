<?php
// api/contact.php - Contact Form Submission API
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$data = getRequestData();

$fullName = trim($data['fullName'] ?? $data['full_name'] ?? '');
$email    = trim(strtolower($data['email'] ?? ''));
$phone    = preg_replace('/[\s-]/', '', trim($data['phone'] ?? ''));
$role     = trim($data['role'] ?? '');
$subject  = trim($data['subject'] ?? '');
$message  = trim($data['message'] ?? '');

$allowedRoles = ['ADMIN', 'STAFF', 'LAB_TECHNICIAN', 'HOSPITAL_REQUESTER', 'USER'];

if (!isValidFullName($fullName)) {
    jsonResponse(['success' => false, 'message' => 'Enter a valid full name (3-60 letters, spaces, apostrophes, periods, or hyphens).'], 400);
}
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Valid email address is required.'], 400);
}
if (!isValidPhoneNumber($phone, false)) {
    jsonResponse(['success' => false, 'message' => 'Enter a valid Pakistani mobile number or leave the phone field blank.'], 400);
}
if (!in_array($role, $allowedRoles, true)) {
    jsonResponse(['success' => false, 'message' => 'Please select a valid role.'], 400);
}
if (!preg_match('/^[A-Za-z0-9 ,\x27.!?:-]{5,80}$/', $subject)) {
    jsonResponse(['success' => false, 'message' => 'Subject must be 5-80 characters and use the allowed punctuation.'], 400);
}
if (strlen($message) < 20 || strlen($message) > 1000) {
    jsonResponse(['success' => false, 'message' => 'Message must be between 20 and 1000 characters.'], 400);
}

$conn = getDb();

$stmt = $conn->prepare(
    "INSERT INTO contact_messages (full_name, email, phone, role, subject, message, sent_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())"
);

if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
}

$stmt->bind_param("ssssss", $fullName, $email, $phone, $role, $subject, $message);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    jsonResponse(['success' => false, 'message' => 'Failed to save contact message: ' . $err], 500);
}

$messageId = $stmt->insert_id;
$stmt->close();

$userId = $_SESSION['user']['userId'] ?? null;
if ($userId) {
    logAudit($conn, $userId, 'CONTACT_SUBMITTED', $messageId, "Message subject: $subject");
}

jsonResponse([
    'success'   => true,
    'message'   => 'Your message has been sent successfully. The blood bank team will review it shortly.',
    'messageId' => $messageId
], 201);
