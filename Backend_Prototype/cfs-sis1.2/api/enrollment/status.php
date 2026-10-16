<?php
/**
 * GET /api/enrollment/status.php?application_id=1
 *
 * Returns an application's status, student info, requirement checklist,
 * and billing balance. A guardian may only view their own applications;
 * registrar/admin may view any (for the registrar dashboard).
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('GET');
$user = requireLogin();

$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT);
if (!$applicationId) {
    jsonError(400, 'application_id (integer) is required.');
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.application_no, a.status, a.enrollment_type, a.grade_level, a.submitted_at,
            a.guardian_id, a.remarks, s.first_name, s.last_name
     FROM enrollment_applications a
     JOIN students s ON s.id = a.student_id
     WHERE a.id = :id'
);
$stmt->execute(['id' => $applicationId]);
$application = $stmt->fetch();

if (!$application) {
    jsonError(404, 'Application not found.');
}

// Ownership check: a guardian can only see their own child's application.
// Registrars/admins can see any application (needed for the dashboard).
$isOwner = (int) $application['guardian_id'] === (int) $user['id'];
$isStaff = in_array($user['role'], ['registrar', 'admin'], true);
if (!$isOwner && !$isStaff) {
    jsonError(403, 'You do not have permission to view this application.');
}
unset($application['guardian_id']); // internal-only, not part of the response shape

$stmt = $pdo->prepare('SELECT doc_type, status, remarks FROM documents WHERE application_id = :id');
$stmt->execute(['id' => $applicationId]);
$documents = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT total_assessed, total_paid, outstanding FROM v_application_balance WHERE application_id = :id');
$stmt->execute(['id' => $applicationId]);
$balance = $stmt->fetch() ?: ['total_assessed' => 0, 'total_paid' => 0, 'outstanding' => 0];

// Each fee item also reports how much of it is verified-paid and how much is
// awaiting verification, so the portal can show Paid / Pending / Not yet due.
$stmt = $pdo->prepare(
    "SELECT f.id, f.name, f.amount, f.due_date,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.fee_item_id = f.id AND p.status = 'verified'), 0) AS paid,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.fee_item_id = f.id AND p.status = 'pending'), 0) AS pending
     FROM fee_items f WHERE f.application_id = :id ORDER BY f.sequence"
);
$stmt->execute(['id' => $applicationId]);
$feeItems = $stmt->fetchAll();

jsonResponse(200, [
    'application' => $application,
    'documents'   => $documents,
    'balance'     => $balance,
    'fee_items'   => $feeItems,
]);
