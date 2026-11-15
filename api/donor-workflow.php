<?php
// Facility-scoped Staff/Admin review for donor profiles and donation appointments.
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');
$staff = requireAuth(['STAFF', 'ADMIN']);
$staffId = (int)($staff['userId'] ?? $staff['user_id'] ?? 0);
$facilityId = requireInventoryInstitutionId($staff);
$institutionCodes = userInstitutionCodes($staff);
$conn = getDb();
$data = getRequestData();
$action = (string)($data['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === '' || $action === 'profiles') {
        $stmt = $conn->prepare(
            "SELECT p.user_id, p.facility_id, p.blood_group, p.profile_status, p.donor_id, p.created_at, " .
            "u.username, u.full_name, u.email, u.phone " .
            "FROM donor_profiles p JOIN users u ON u.user_id = p.user_id " .
            "WHERE p.facility_id = ? AND p.profile_status = 'PENDING' AND u.role = 'USER' ORDER BY p.created_at ASC"
        );
        if (!$stmt) jsonResponse(['success' => false, 'message' => 'Could not load donor profiles for review.'], 500);
        $stmt->bind_param('i', $facilityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $profiles = [];
        while ($row = $result->fetch_assoc()) {
            $profiles[] = [
                'userId' => (int)$row['user_id'],
                'username' => $row['username'],
                'fullName' => $row['full_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'bloodGroup' => toDisplayBloodGroup($row['blood_group']),
                'linkedDonorId' => $row['donor_id'] ? (int)$row['donor_id'] : null,
                'submittedAt' => $row['created_at']
            ];
        }
        $stmt->close();

        $appointments = [];
        $apptStmt = $conn->prepare(
            "SELECT a.appointment_id, a.donor_id, a.requested_date, TIME_FORMAT(a.requested_time, '%H:%i') AS requested_time, " .
            "a.scheduled_date, TIME_FORMAT(a.scheduled_time, '%H:%i') AS scheduled_time, a.status, a.staff_note, a.created_at, " .
            "d.name AS donor_name, d.contact_details, d.blood_group " .
            "FROM donation_appointments a JOIN donors d ON d.donor_id = a.donor_id " .
            "WHERE a.facility_id = ? AND a.status IN ('PENDING','CONFIRMED') ORDER BY a.requested_date ASC, a.requested_time ASC"
        );
        if (!$apptStmt) jsonResponse(['success' => false, 'message' => 'Could not load donation appointments.'], 500);
        $apptStmt->bind_param('i', $facilityId);
        $apptStmt->execute();
        $result = $apptStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $appointments[] = [
                'appointmentId' => (int)$row['appointment_id'],
                'donorId' => (int)$row['donor_id'],
                'donorName' => $row['donor_name'],
                'contact' => $row['contact_details'],
                'bloodGroup' => toDisplayBloodGroup($row['blood_group']),
                'requestedDate' => $row['requested_date'],
                'requestedTime' => $row['requested_time'],
                'scheduledDate' => $row['scheduled_date'],
                'scheduledTime' => $row['scheduled_time'],
                'status' => $row['status'],
                'staffNote' => $row['staff_note'],
                'createdAt' => $row['created_at']
            ];
        }
        $apptStmt->close();
        jsonResponse(['success' => true, 'profiles' => $profiles, 'appointments' => $appointments]);
    }
    jsonResponse(['success' => false, 'message' => 'Unknown donor workflow view.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if ($action === 'review_profile') {
    $targetUserId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = strtoupper(trim((string)($data['decision'] ?? '')));
    $existingDonorId = filter_var($data['existing_donor_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$targetUserId || $targetUserId < 1 || !in_array($decision, ['VERIFY', 'REJECT'], true)) {
        jsonResponse(['success' => false, 'message' => 'Choose a valid donor profile and review decision.'], 400);
    }
    if ($existingDonorId !== false && $existingDonorId !== null && $existingDonorId < 1) {
        jsonResponse(['success' => false, 'message' => 'Existing donor ID must be a positive whole number.'], 400);
    }

    $conn->begin_transaction();
    $find = $conn->prepare(
        "SELECT p.user_id, p.facility_id, p.blood_group, p.profile_status, p.donor_id, " .
        "u.full_name, u.phone FROM donor_profiles p JOIN users u ON u.user_id = p.user_id " .
        "WHERE p.user_id = ? AND p.facility_id = ? AND p.profile_status = 'PENDING' AND u.role = 'USER' LIMIT 1 FOR UPDATE"
    );
    if (!$find) jsonResponse(['success' => false, 'message' => 'Could not load the donor profile.'], 500);
    $find->bind_param('ii', $targetUserId, $facilityId);
    $find->execute();
    $profile = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$profile) jsonResponse(['success' => false, 'message' => 'No pending donor profile was found in your facility.'], 404);

    if ($decision === 'REJECT') {
        $reject = $conn->prepare("UPDATE donor_profiles SET profile_status = 'REJECTED', reviewed_by = ?, reviewed_at = NOW() WHERE user_id = ? AND profile_status = 'PENDING'");
        if (!$reject) jsonResponse(['success' => false, 'message' => 'Could not prepare the profile review.'], 500);
        $reject->bind_param('ii', $staffId, $targetUserId);
        if (!$reject->execute() || $reject->affected_rows !== 1) {
            $reject->close();
            jsonResponse(['success' => false, 'message' => 'The donor profile changed before review completed.'], 409);
        }
        $reject->close();
        logAudit($conn, $staffId, 'DONOR_PROFILE_REJECTED', $targetUserId, "Staff rejected donor profile for facility {$facilityId}.");
        jsonResponse(['success' => true, 'message' => 'Donor profile marked as needing correction. The Donor can update it and resubmit.']);
    }

    $donorId = $profile['donor_id'] ? (int)$profile['donor_id'] : 0;
    if ($existingDonorId) {
        if ($donorId && $donorId !== (int)$existingDonorId) {
            jsonResponse(['success' => false, 'message' => 'This Donor account is already linked to a different donor record.'], 409);
        }
        $owned = $conn->prepare(
            "SELECT d.donor_id, d.user_id FROM donors d JOIN users manager ON manager.user_id = d.managed_by " .
            "WHERE d.donor_id = ? AND manager.institution_id IN (?, ?) LIMIT 1 FOR UPDATE"
        );
        if (!$owned) jsonResponse(['success' => false, 'message' => 'Could not verify the existing donor record.'], 500);
        $owned->bind_param('iss', $existingDonorId, $institutionCodes[0], $institutionCodes[1]);
        $owned->execute();
        $existing = $owned->get_result()->fetch_assoc();
        $owned->close();
        if (!$existing || ($existing['user_id'] !== null && (int)$existing['user_id'] !== (int)$targetUserId)) {
            jsonResponse(['success' => false, 'message' => 'That donor record is unavailable or already linked to another account.'], 409);
        }
        $donorId = (int)$existingDonorId;
        $updateDonor = $conn->prepare('UPDATE donors SET user_id = ?, name = ?, contact_details = ?, blood_group = ? WHERE donor_id = ?');
        if (!$updateDonor) jsonResponse(['success' => false, 'message' => 'Could not link the existing donor record.'], 500);
        $group = toDbBloodGroup($profile['blood_group']);
        $contact = $profile['phone'];
        $updateDonor->bind_param('isssi', $targetUserId, $profile['full_name'], $contact, $group, $donorId);
        if (!$updateDonor->execute()) {
            $updateDonor->close();
            jsonResponse(['success' => false, 'message' => 'Could not link the existing donor record.'], 500);
        }
        $updateDonor->close();
    } elseif (!$donorId) {
        $group = toDbBloodGroup($profile['blood_group']);
        $contact = $profile['phone'];
        $create = $conn->prepare('INSERT INTO donors (managed_by, user_id, name, contact_details, blood_group) VALUES (?, ?, ?, ?, ?)');
        if (!$create) jsonResponse(['success' => false, 'message' => 'Could not create the verified donor record.'], 500);
        $create->bind_param('iisss', $staffId, $targetUserId, $profile['full_name'], $contact, $group);
        if (!$create->execute()) {
            $create->close();
            jsonResponse(['success' => false, 'message' => 'Could not create the verified donor record.'], 500);
        }
        $donorId = (int)$create->insert_id;
        $create->close();
    } else {
        $group = toDbBloodGroup($profile['blood_group']);
        $contact = $profile['phone'];
        $link = $conn->prepare('UPDATE donors SET user_id = ?, name = ?, contact_details = ?, blood_group = ? WHERE donor_id = ? AND user_id = ?');
        if (!$link) jsonResponse(['success' => false, 'message' => 'Could not relink the donor profile.'], 500);
        $link->bind_param('isssii', $targetUserId, $profile['full_name'], $contact, $group, $donorId, $targetUserId);
        if (!$link->execute()) {
            $link->close();
            jsonResponse(['success' => false, 'message' => 'Could not relink the donor profile.'], 500);
        }
        $link->close();
    }

    $verify = $conn->prepare("UPDATE donor_profiles SET profile_status = 'VERIFIED', donor_id = ?, reviewed_by = ?, reviewed_at = NOW() WHERE user_id = ? AND profile_status = 'PENDING'");
    if (!$verify) jsonResponse(['success' => false, 'message' => 'Could not save donor verification.'], 500);
    $verify->bind_param('iii', $donorId, $staffId, $targetUserId);
    if (!$verify->execute() || $verify->affected_rows !== 1) {
        $verify->close();
        jsonResponse(['success' => false, 'message' => 'Could not save donor verification.'], 409);
    }
    $verify->close();
    logAudit($conn, $staffId, 'DONOR_PROFILE_VERIFIED', $targetUserId, "Staff verified donor profile and linked donor #{$donorId} at facility {$facilityId}.");
    jsonResponse(['success' => true, 'message' => 'Donor profile verified. The Donor can now request appointments.']);
}

if ($action === 'review_appointment') {
    $appointmentId = filter_var($data['appointment_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = strtoupper(trim((string)($data['decision'] ?? '')));
    $note = trim((string)($data['staff_note'] ?? ''));
    if (!$appointmentId || $appointmentId < 1 || !in_array($decision, ['CONFIRM', 'DECLINE', 'NO_SHOW'], true)) {
        jsonResponse(['success' => false, 'message' => 'Choose a valid appointment decision.'], 400);
    }
    if (strlen($note) > 300) jsonResponse(['success' => false, 'message' => 'Staff note cannot exceed 300 characters.'], 422);
    $conn->begin_transaction();
    $find = $conn->prepare(
        "SELECT a.appointment_id, a.donor_id, a.requested_date, a.requested_time, a.status, p.profile_status " .
        "FROM donation_appointments a JOIN donor_profiles p ON p.donor_id = a.donor_id " .
        "WHERE a.appointment_id = ? AND a.facility_id = ? LIMIT 1 FOR UPDATE"
    );
    if (!$find) jsonResponse(['success' => false, 'message' => 'Could not load the appointment.'], 500);
    $find->bind_param('ii', $appointmentId, $facilityId);
    $find->execute();
    $appointment = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$appointment) jsonResponse(['success' => false, 'message' => 'Appointment not found in your facility.'], 404);

    if ($decision === 'CONFIRM') {
        if ($appointment['profile_status'] !== 'VERIFIED') {
            jsonResponse(['success' => false, 'message' => 'Reverify the donor profile before confirming or rescheduling this appointment.'], 409);
        }
        if (!in_array($appointment['status'], ['PENDING', 'CONFIRMED'], true)) {
            jsonResponse(['success' => false, 'message' => 'Only pending or confirmed appointments can be scheduled.'], 409);
        }
        $date = trim((string)($data['scheduled_date'] ?? $appointment['requested_date']));
        $time = trim((string)($data['scheduled_time'] ?? substr($appointment['requested_time'], 0, 5)));
        if (!isValidDateValue($date) || $date < (new DateTimeImmutable('today'))->format('Y-m-d') || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            jsonResponse(['success' => false, 'message' => 'Choose a valid scheduled date and time that are not in the past.'], 422);
        }
        if ((new DateTimeImmutable($date . ' ' . $time)) < new DateTimeImmutable()) {
            jsonResponse(['success' => false, 'message' => 'Choose a scheduled time that is not in the past.'], 422);
        }
        $update = $conn->prepare("UPDATE donation_appointments SET status = 'CONFIRMED', scheduled_date = ?, scheduled_time = ?, staff_note = ?, reviewed_by = ? WHERE appointment_id = ? AND facility_id = ?");
        if (!$update) jsonResponse(['success' => false, 'message' => 'Could not schedule appointment.'], 500);
        $update->bind_param('sssiii', $date, $time, $note, $staffId, $appointmentId, $facilityId);
        $event = 'DONATION_APPOINTMENT_CONFIRMED';
        $message = 'Appointment confirmed or rescheduled.';
    } else {
        $allowedStatuses = $decision === 'DECLINE' ? ['PENDING', 'CONFIRMED'] : ['CONFIRMED'];
        if (!in_array($appointment['status'], $allowedStatuses, true)) {
            jsonResponse(['success' => false, 'message' => 'This appointment cannot be changed to the selected status.'], 409);
        }
        $status = $decision === 'DECLINE' ? 'DECLINED' : 'NO_SHOW';
        $update = $conn->prepare('UPDATE donation_appointments SET status = ?, staff_note = ?, reviewed_by = ? WHERE appointment_id = ? AND facility_id = ?');
        if (!$update) jsonResponse(['success' => false, 'message' => 'Could not update appointment.'], 500);
        $update->bind_param('ssiii', $status, $note, $staffId, $appointmentId, $facilityId);
        $event = $decision === 'DECLINE' ? 'DONATION_APPOINTMENT_DECLINED' : 'DONATION_APPOINTMENT_NO_SHOW';
        $message = $decision === 'DECLINE' ? 'Appointment declined.' : 'Appointment marked as no-show.';
    }
    if (!$update->execute()) {
        $update->close();
        jsonResponse(['success' => false, 'message' => 'Could not save appointment decision.'], 500);
    }
    $update->close();
    logAudit($conn, $staffId, $event, $appointmentId, "Staff updated appointment status at facility {$facilityId}.");
    jsonResponse(['success' => true, 'message' => $message]);
}

jsonResponse(['success' => false, 'message' => 'Unknown donor workflow action.'], 400);
