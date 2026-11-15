<?php
// api/reports.php - Facility-scoped inventory snapshot and date-range analytics (FR-13, FR-15)
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$currentUser = requireAuth(['STAFF', 'ADMIN', 'LAB_TECHNICIAN']);
$conn = getDb();
$institutionId = requireInventoryInstitutionId($currentUser);
$institutionCodes = userInstitutionCodes($currentUser);

function parseReportDate($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) return null;
    return $date;
}

$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('first day of this month')->modify('-5 months');
$hasStart = isset($_GET['start_date']) && $_GET['start_date'] !== '';
$hasEnd = isset($_GET['end_date']) && $_GET['end_date'] !== '';
if ($hasStart !== $hasEnd) {
    jsonResponse(['success' => false, 'message' => 'Provide both start_date and end_date.'], 422);
}
$startDate = $hasStart ? parseReportDate((string)$_GET['start_date']) : $defaultStart;
$endDate = $hasEnd ? parseReportDate((string)$_GET['end_date']) : $today;
if (!$startDate || !$endDate) {
    jsonResponse(['success' => false, 'message' => 'Dates must be valid YYYY-MM-DD values.'], 422);
}
if ($startDate > $endDate) {
    jsonResponse(['success' => false, 'message' => 'Start date must be on or before end date.'], 422);
}
$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');
$endExclusive = $endDate->modify('+1 day')->format('Y-m-d');
$startTime = $start . ' 00:00:00';
$endExclusiveTime = $endExclusive . ' 00:00:00';

try {
    // Current stock and lifecycle are snapshots, independent of the selected report period.
    $groups = ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'];
    $groupStock = [];
    foreach ($groups as $group) {
        $groupStock[$group] = [
            'available' => 0, 'total' => 0, 'issued' => 0, 'expired' => 0,
            'period_issued' => 0, 'period_expired' => 0, 'near_expiry' => 0
        ];
    }

    $stockStmt = $conn->prepare(
        "SELECT blood_group, COUNT(*) AS total,
                SUM(status = 'AVAILABLE') AS available,
                SUM(status = 'ISSUED') AS issued,
                SUM(status = 'EXPIRED') AS expired,
                SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    AND status IN ('AVAILABLE','QUARANTINED','RESERVED')) AS near_expiry
         FROM blood_units WHERE institution_id = ? GROUP BY blood_group"
    );
    $stockStmt->bind_param('i', $institutionId);
    $stockStmt->execute();
    $stockRes = $stockStmt->get_result();
    while ($row = $stockRes->fetch_assoc()) {
        $displayGroup = toDisplayBloodGroup($row['blood_group']);
        if (!isset($groupStock[$displayGroup])) continue;
        foreach (['total', 'available', 'issued', 'expired', 'near_expiry'] as $field) {
            $groupStock[$displayGroup][$field] = (int)$row[$field];
        }
    }
    $stockStmt->close();

    $nearExpiryCount = array_sum(array_column($groupStock, 'near_expiry'));
    $statusCounts = ['AVAILABLE' => 0, 'QUARANTINED' => 0, 'RESERVED' => 0, 'ISSUED' => 0, 'EXPIRED' => 0];
    $statusStmt = $conn->prepare("SELECT status, COUNT(*) AS qty FROM blood_units WHERE institution_id = ? GROUP BY status");
    $statusStmt->bind_param('i', $institutionId);
    $statusStmt->execute();
    $statusRes = $statusStmt->get_result();
    while ($row = $statusRes->fetch_assoc()) {
        if (isset($statusCounts[$row['status']])) $statusCounts[$row['status']] = (int)$row['qty'];
    }
    $statusStmt->close();

    // Period issuance is based on transaction date; expiry wastage is based on the unit expiry date.
    $periodIssued = 0;
    $periodExpired = 0;
    $issuedStmt = $conn->prepare("SELECT COUNT(*) AS qty FROM transactions WHERE institution_id = ? AND allocation_date >= ? AND allocation_date < ?");
    $issuedStmt->bind_param('iss', $institutionId, $startTime, $endExclusiveTime);
    $issuedStmt->execute();
    $periodIssued = (int)$issuedStmt->get_result()->fetch_assoc()['qty'];
    $issuedStmt->close();

    $expiredStmt = $conn->prepare("SELECT COUNT(*) AS qty FROM blood_units WHERE institution_id = ? AND status = 'EXPIRED' AND expiry_date >= ? AND expiry_date < ?");
    $expiredStmt->bind_param('iss', $institutionId, $start, $endExclusive);
    $expiredStmt->execute();
    $periodExpired = (int)$expiredStmt->get_result()->fetch_assoc()['qty'];
    $expiredStmt->close();

    $periodGroupIssued = $conn->prepare(
        "SELECT u.blood_group, COUNT(*) AS qty FROM transactions t
         JOIN blood_units u ON u.unit_id = t.unit_id
         WHERE t.institution_id = ? AND t.allocation_date >= ? AND t.allocation_date < ?
         GROUP BY u.blood_group"
    );
    $periodGroupIssued->bind_param('iss', $institutionId, $startTime, $endExclusiveTime);
    $periodGroupIssued->execute();
    $groupIssuedRes = $periodGroupIssued->get_result();
    while ($row = $groupIssuedRes->fetch_assoc()) {
        $displayGroup = toDisplayBloodGroup($row['blood_group']);
        if (isset($groupStock[$displayGroup])) $groupStock[$displayGroup]['period_issued'] = (int)$row['qty'];
    }
    $periodGroupIssued->close();

    $periodGroupExpired = $conn->prepare(
        "SELECT blood_group, COUNT(*) AS qty FROM blood_units
         WHERE institution_id = ? AND status = 'EXPIRED' AND expiry_date >= ? AND expiry_date < ?
         GROUP BY blood_group"
    );
    $periodGroupExpired->bind_param('iss', $institutionId, $start, $endExclusive);
    $periodGroupExpired->execute();
    $groupExpiredRes = $periodGroupExpired->get_result();
    while ($row = $groupExpiredRes->fetch_assoc()) {
        $displayGroup = toDisplayBloodGroup($row['blood_group']);
        if (isset($groupStock[$displayGroup])) $groupStock[$displayGroup]['period_expired'] = (int)$row['qty'];
    }
    $periodGroupExpired->close();

    $totalHandled = $periodIssued + $periodExpired;
    $wastageRate = $totalHandled > 0 ? round(($periodExpired / $totalHandled) * 100, 1) : 0.0;
    $efficiencyRate = round(100.0 - $wastageRate, 1);

    // Demand is measured by requests placed in the selected period.
    $urgencyCounts = ['CRITICAL' => 0, 'URGENT' => 0, 'ROUTINE' => 0];
    $totalRequests = 0;
    $fulfilledRequests = 0;
    $reqStmt = $conn->prepare(
        "SELECT urgency, status, COUNT(*) AS qty FROM blood_requests
         WHERE serving_institution_id = ? AND request_date >= ? AND request_date < ?
         GROUP BY urgency, status"
    );
    $reqStmt->bind_param('iss', $institutionId, $startTime, $endExclusiveTime);
    $reqStmt->execute();
    $reqRes = $reqStmt->get_result();
    while ($row = $reqRes->fetch_assoc()) {
        $qty = (int)$row['qty'];
        if (isset($urgencyCounts[$row['urgency']])) $urgencyCounts[$row['urgency']] += $qty;
        $totalRequests += $qty;
        if ($row['status'] === 'FULFILLED') $fulfilledRequests += $qty;
    }
    $reqStmt->close();
    $fulfillmentRate = $totalRequests > 0 ? round(($fulfilledRequests / $totalRequests) * 100, 1) : 100.0;

    // Monthly series is bounded to the selected dates, including partial first/last months.
    $monthlyMap = [];
    $monthCursor = $startDate->modify('first day of this month');
    $lastMonth = $endDate->modify('first day of this month');
    while ($monthCursor <= $lastMonth) {
        $key = $monthCursor->format('Y-m');
        $monthlyMap[$key] = [
            'month' => $monthCursor->format('M Y'), 'collections' => 0, 'issuances' => 0, 'expired' => 0
        ];
        $monthCursor = $monthCursor->modify('+1 month');
    }

    $collectionStmt = $conn->prepare(
        "SELECT DATE_FORMAT(collection_date, '%Y-%m') AS month_key, COUNT(*) AS qty
         FROM blood_units WHERE institution_id = ? AND collection_date >= ? AND collection_date < ?
         GROUP BY month_key"
    );
    $collectionStmt->bind_param('iss', $institutionId, $start, $endExclusive);
    $collectionStmt->execute();
    $collectionRes = $collectionStmt->get_result();
    while ($row = $collectionRes->fetch_assoc()) {
        if (isset($monthlyMap[$row['month_key']])) $monthlyMap[$row['month_key']]['collections'] = (int)$row['qty'];
    }
    $collectionStmt->close();

    $issuanceTrendStmt = $conn->prepare(
        "SELECT DATE_FORMAT(allocation_date, '%Y-%m') AS month_key, COUNT(*) AS qty
         FROM transactions WHERE institution_id = ? AND allocation_date >= ? AND allocation_date < ?
         GROUP BY month_key"
    );
    $issuanceTrendStmt->bind_param('iss', $institutionId, $startTime, $endExclusiveTime);
    $issuanceTrendStmt->execute();
    $issuanceRes = $issuanceTrendStmt->get_result();
    while ($row = $issuanceRes->fetch_assoc()) {
        if (isset($monthlyMap[$row['month_key']])) $monthlyMap[$row['month_key']]['issuances'] = (int)$row['qty'];
    }
    $issuanceTrendStmt->close();

    $expiryTrendStmt = $conn->prepare(
        "SELECT DATE_FORMAT(expiry_date, '%Y-%m') AS month_key, COUNT(*) AS qty
         FROM blood_units WHERE institution_id = ? AND status = 'EXPIRED' AND expiry_date >= ? AND expiry_date < ?
         GROUP BY month_key"
    );
    $expiryTrendStmt->bind_param('iss', $institutionId, $start, $endExclusive);
    $expiryTrendStmt->execute();
    $expiryRes = $expiryTrendStmt->get_result();
    while ($row = $expiryRes->fetch_assoc()) {
        if (isset($monthlyMap[$row['month_key']])) $monthlyMap[$row['month_key']]['expired'] = (int)$row['qty'];
    }
    $expiryTrendStmt->close();

    $donorCount = 0;
    $donorStmt = $conn->prepare("SELECT COUNT(*) AS qty FROM donors d JOIN users manager ON manager.user_id = d.managed_by WHERE manager.institution_id IN (?, ?)");
    $donorStmt->bind_param('ss', $institutionCodes[0], $institutionCodes[1]);
    $donorStmt->execute();
    $donorCount = (int)$donorStmt->get_result()->fetch_assoc()['qty'];
    $donorStmt->close();

    jsonResponse([
        'success' => true,
        'report_period' => ['start_date' => $start, 'end_date' => $end],
        'group_stock' => $groupStock,
        'status_counts' => $statusCounts,
        'near_expiry_count' => $nearExpiryCount,
        'wastage_metrics' => [
            'total_issued' => $periodIssued,
            'total_expired' => $periodExpired,
            'total_handled' => $totalHandled,
            'wastage_rate' => $wastageRate,
            'efficiency_rate' => $efficiencyRate,
            'active_donors' => $donorCount
        ],
        'demand_metrics' => [
            'urgency_counts' => $urgencyCounts,
            'total_requests' => $totalRequests,
            'fulfilled' => $fulfilledRequests,
            'fulfillment_rate' => $fulfillmentRate
        ],
        'monthly_trends' => array_values($monthlyMap)
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
