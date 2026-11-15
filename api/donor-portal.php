<?php
// Donor self-service API. Every donor-owned record is resolved from the session.
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');
$user = requireAuth();
if (($user['role'] ?? '') !== 'USER') {
    jsonResponse(['success' => false, 'message' => 'Donor portal access is limited to Donor accounts.'], 403);
}
$userId = (int)($user['userId'] ?? $user['user_id'] ?? 0);
$conn = getDb();
$data = getRequestData();
$action = (string)($data['action'] ?? $_GET['action'] ?? '');

function donorProfileForUser($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT p.user_id, p.facility_id, p.blood_group AS profile_blood_group, p.profile_status, p.donor_id, p.reviewed_at, " .
        "u.full_name, u.email, u.phone, d.blood_group AS verified_blood_group, d.last_donation_date " .
        "FROM donor_profiles p JOIN users u ON u.user_id = p.user_id " .
        "LEFT JOIN donors d ON d.donor_id = p.donor_id WHERE p.user_id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $profile ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action !== '' && $action !== 'dashboard') {
        jsonResponse(['success' => false, 'message' => 'Unknown donor portal action.'], 400);
    }
    $profile = donorProfileForUser($conn, $userId);
    if (!$profile) jsonResponse(['success' => true, 'profile' => null, 'appointments' => [], 'history' => []]);

    $appointments = [];
    $history = [];
    $nextEligible = null;
    if (!empty($profile['donor_id'])) {
        $appointmentStmt = $conn->prepare(
            "SELECT appointment_id, requested_date, TIME_FORMAT(requested_time, '%H:%i') AS requested_time, " .
            "scheduled_date, TIME_FORMAT(scheduled_time, '%H:%i') AS scheduled_time, status, created_at " .
            "FROM donation_appointments WHERE donor_id = ? ORDER BY created_at DESC LIMIT 25"
        );
        if ($appointmentStmt) {
            $donorId = (int)$profile['donor_id'];
            $appointmentStmt->bind_param('i', $donorId);
            $appointmentStmt->execute();
            $rows = $appointmentStmt->get_result();
            while ($row = $rows->fetch_assoc()) {
                $appointments[] = [
                    'appointmentId' => (int)$row['appointment_id'],
                    'requestedDate' => $row['requested_date'],
                    'requestedTime' => $row['requested_time'],
                    'scheduledDate' => $row['scheduled_date'],
                    'scheduledTime' => $row['scheduled_time'],
                    'status' => $row['status'],
                    'createdAt' => $row['created_at']
                ];
            }
            $appointmentStmt->close();
        }

        $historyStmt = $conn->prepare(
            "SELECT collection_date, blood_group, status, COUNT(*) AS unit_count " .
            "FROM blood_units WHERE donor_id = ? AND institution_id = ? " .
            "GROUP BY collection_date, blood_group, status ORDER BY collection_date DESC LIMIT 30"
        );
        if ($historyStmt) {
            $facilityId = (int)$profile['facility_id'];
            $historyStmt->bind_param('ii', $donorId, $facilityId);
            $historyStmt->execute();
            $rows = $historyStmt->get_result();
            while ($row = $rows->fetch_assoc()) {
                $statusLabel = in_array($row['status'], ['AVAILABLE', 'RESERVED', 'ISSUED'], true)
                    ? 'Accepted for use'
                    : ($row['status'] === 'QUARANTINED' ? 'Processing' : 'Not used');
                $history[] = [
                    'date' => $row['collection_date'],
                    'bloodGroup' => toDisplayBloodGroup($row['blood_group']),
                    'status' => $statusLabel,
                    'units' => (int)$row['unit_count']
                ];
            }
            $historyStmt->close();
        }
        if (!empty($profile['last_donation_date'])) {
            $nextEligible = (new DateTimeImmutable($profile['last_donation_date']))->modify('+56 days')->format('Y-m-d');
        }
    }

    $displayGroup = $profile['profile_status'] === 'VERIFIED' && $profile['verified_blood_group']
        ? $profile['verified_blood_group']
        : $profile['profile_blood_group'];
    jsonResponse([
        'success' => true,
        'profile' => [
            'fullName' => $profile['full_name'],
            'email' => $profile['email'],
            'phone' => $profile['phone'],
            'bloodGroup' => toDisplayBloodGroup($displayGroup),
            'facilityId' => (int)$profile['facility_id'],
            'status' => $profile['profile_status'],
            'reviewedAt' => $profile['reviewed_at'],
            'lastDonationDate' => $profile['last_donation_date'],
            'nextEligibleEstimate' => $nextEligible
        ],
        'appointments' => $appointments,
        'history' => $history
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if ($action === 'submit_profile') {
    $facilityId = filter_var($data['facility_id'] ?? null, FILTER_VALIDATE_INT);
    $group = toDbBloodGroup($data['blood_group'] ?? '');
    if (!$facilityId || $facilityId < 1 || $facilityId > 2147483647) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid preferred blood bank facility ID.'], 422);
    }
    if (!isKnownInventoryFacilityId($conn, $facilityId)) {
        jsonResponse(['success' => false, 'message' => 'That blood bank facility is not configured in this installation.'], 422);
    }
    if (!in_array($group, ['O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE'], true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid blood group.'], 422);
    }
    if (!filter_var($data['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) jsonResponse(['success' => false, 'message' => 'Consent is required to submit a donor profile.'], 400);
    $conn->begin_transaction();
    $exists = $conn->prepare('SELECT user_id FROM donor_profiles WHERE user_id = ? LIMIT 1 FOR UPDATE');
    if (!$exists) jsonResponse(['success' => false, 'message' => 'Could not check your donor profile.'], 500);
    $exists->bind_param('i', $userId);
    $exists->execute();
    $already = $exists->get_result()->num_rows > 0;
    $exists->close();
    if ($already) jsonResponse(['success' => false, 'message' => 'A donor profile already exists for this account.'], 409);
    $insert = $conn->prepare("INSERT INTO donor_profiles (user_id, facility_id, blood_group, profile_status, consent_at) VALUES (?, ?, ?, 'PENDING', NOW())");
    if (!$insert) jsonResponse(['success' => false, 'message' => 'Could not prepare donor profile.'], 500);
    $insert->bind_param('iis', $userId, $facilityId, $group);
    if (!$insert->execute()) {
        $insert->close();
        jsonResponse(['success' => false, 'message' => 'Could not submit donor profile.'], 500);
    }
    $insert->close();
    $userUpdate = $conn->prepare("UPDATE users SET institution_id = ?, blood_group = ? WHERE user_id = ? AND role = 'USER'");
    $facilityLabel = (string)$facilityId;
    $displayGroup = toDisplayBloodGroup($group);
    if (!$userUpdate) jsonResponse(['success' => false, 'message' => 'Could not update your account profile.'], 500);
    $userUpdate->bind_param('ssi', $facilityLabel, $displayGroup, $userId);
    if (!$userUpdate->execute()) {
        $userUpdate->close();
        jsonResponse(['success' => false, 'message' => 'Could not update your account profile.'], 500);
    }
    $userUpdate->close();
    logAudit($conn, $userId, 'DONOR_PROFILE_SUBMITTED', $userId, "Donor profile submitted for facility {$facilityId}; staff verification pending.");
    jsonResponse(['success' => true, 'message' => 'Donor profile submitted. Staff must verify it before you can request an appointment.']);
}

if ($action === 'update_profile') {
    $existingProfile = donorProfileForUser($conn, $userId);
    if (!$existingProfile) {
        jsonResponse(['success' => false, 'message' => 'Complete your donor profile before editing it.'], 409);
    }
    $name = trim((string)($data['full_name'] ?? ''));
    $phone = preg_replace('/[\s-]/', '', trim((string)($data['phone'] ?? '')));
    $group = toDbBloodGroup($data['blood_group'] ?? '');
    if (!isValidFullName($name, 3, 60)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid name using 3-60 letters, spaces, apostrophes, periods, or hyphens.'], 422);
    }
    if (!isValidPhoneNumber($phone)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid Pakistani mobile number.'], 422);
    }
    if (!in_array($group, ['O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE'], true)) {
        jsonResponse(['success' => false, 'message' => 'Select a valid blood group.'], 422);
    }
    $conn->begin_transaction();
    $userUpdate = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, blood_group = ? WHERE user_id = ? AND role = 'USER'");
    if (!$userUpdate) jsonResponse(['success' => false, 'message' => 'Could not prepare profile update.'], 500);
    $userUpdate->bind_param('sssi', $name, $phone, $group, $userId);
    if (!$userUpdate->execute()) {
        $userUpdate->close();
        jsonResponse(['success' => false, 'message' => 'Could not save your profile.'], 500);
    }
    $userUpdate->close();
    $profileUpdate = $conn->prepare("UPDATE donor_profiles SET blood_group = ?, profile_status = 'PENDING', reviewed_by = NULL, reviewed_at = NULL WHERE user_id = ?");
    if (!$profileUpdate) jsonResponse(['success' => false, 'message' => 'Could not queue your donor profile for review.'], 500);
    $profileUpdate->bind_param('si', $group, $userId);
    if (!$profileUpdate->execute()) {
        $profileUpdate->close();
        jsonResponse(['success' => false, 'message' => 'Could not queue your donor profile for review.'], 500);
    }
    $profileUpdate->close();
    logAudit($conn, $userId, 'DONOR_PROFILE_UPDATED', $userId, 'Donor updated their own profile; staff verification required.');
    jsonResponse(['success' => true, 'message' => 'Profile saved. Staff verification is required before you can request another appointment.']);
}

if ($action === 'request_appointment') {
    $date = trim((string)($data['requested_date'] ?? ''));
    $time = trim((string)($data['requested_time'] ?? ''));
    if (!isValidDateValue($date) || $date <= (new DateTimeImmutable('today'))->format('Y-m-d')) {
        jsonResponse(['success' => false, 'message' => 'Choose a valid appointment date after today.'], 422);
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        jsonResponse(['success' => false, 'message' => 'Choose a valid preferred time.'], 422);
    }
    $profile = donorProfileForUser($conn, $userId);
    if (!$profile || $profile['profile_status'] !== 'VERIFIED' || empty($profile['donor_id'])) {
        jsonResponse(['success' => false, 'message' => 'Staff must verify your donor profile before you can request an appointment.'], 403);
    }
    $donorId = (int)$profile['donor_id'];
    $facilityId = (int)$profile['facility_id'];
    $conn->begin_transaction();
    $profileLock = $conn->prepare("SELECT user_id FROM donor_profiles WHERE user_id = ? AND profile_status = 'VERIFIED' AND donor_id = ? FOR UPDATE");
    if (!$profileLock) jsonResponse(['success' => false, 'message' => 'Could not verify your donor profile.'], 500);
    $profileLock->bind_param('ii', $userId, $donorId);
    $profileLock->execute();
    $stillVerified = $profileLock->get_result()->num_rows === 1;
    $profileLock->close();
    if (!$stillVerified) jsonResponse(['success' => false, 'message' => 'Staff must verify your donor profile before you can request an appointment.'], 403);
    $active = $conn->prepare("SELECT appointment_id FROM donation_appointments WHERE donor_id = ? AND status IN ('PENDING','CONFIRMED') LIMIT 1 FOR UPDATE");
    if (!$active) jsonResponse(['success' => false, 'message' => 'Could not check your existing appointments.'], 500);
    $active->bind_param('i', $donorId);
    $active->execute();
    $hasActive = $active->get_result()->num_rows > 0;
    $active->close();
    if ($hasActive) jsonResponse(['success' => false, 'message' => 'You already have a pending or confirmed appointment.'], 409);
    $stmt = $conn->prepare("INSERT INTO donation_appointments (donor_id, facility_id, requested_date, requested_time, status) VALUES (?, ?, ?, ?, 'PENDING')");
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Could not prepare appointment request.'], 500);
    $stmt->bind_param('iiss', $donorId, $facilityId, $date, $time);
    if (!$stmt->execute()) {
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Could not submit your appointment request.'], 500);
    }
    $appointmentId = $stmt->insert_id;
    $stmt->close();
    logAudit($conn, $userId, 'DONATION_APPOINTMENT_REQUESTED', $appointmentId, "Donor requested appointment at facility {$facilityId} for {$date}.");
    jsonResponse(['success' => true, 'message' => 'Appointment request submitted. Staff will confirm or suggest another time.', 'appointment_id' => $appointmentId], 201);
}

if ($action === 'cancel_appointment') {
    $appointmentId = filter_var($data['appointment_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$appointmentId || $appointmentId < 1) jsonResponse(['success' => false, 'message' => 'Select a valid appointment.'], 400);
    $profile = donorProfileForUser($conn, $userId);
    if (!$profile || empty($profile['donor_id'])) jsonResponse(['success' => false, 'message' => 'No verified donor profile is linked to this account.'], 404);
    $donorId = (int)$profile['donor_id'];
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE donation_appointments SET status = 'CANCELLED' WHERE appointment_id = ? AND donor_id = ? AND status IN ('PENDING','CONFIRMED')");
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Could not cancel appointment.'], 500);
    $stmt->bind_param('ii', $appointmentId, $donorId);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Appointment was not found or can no longer be cancelled.'], 409);
    }
    $stmt->close();
    logAudit($conn, $userId, 'DONATION_APPOINTMENT_CANCELLED', $appointmentId, 'Donor cancelled their own appointment.');
    jsonResponse(['success' => true, 'message' => 'Appointment cancelled.']);
}

jsonResponse(['success' => false, 'message' => 'Unknown donor portal action.'], 400);
