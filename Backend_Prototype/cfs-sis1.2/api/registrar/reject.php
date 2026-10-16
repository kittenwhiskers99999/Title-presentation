<?php
/**
 * POST /api/registrar/reject.php   (JSON)
 * Body: { "application_id": 12, "remarks": "reason (required)" }
 * Rejection is final in this version — a rejected application cannot be reopened.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('POST');
$staff = requireStaff();
$input = readJsonBody();

$applicationId = requireIntField($input, 'application_id');
$remarks = optionalText($input, 'remarks');
if ($remarks === null) {
    jsonError(400, 'remarks (the reason for rejection) are required.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, application_no, status FROM enrollment_applications WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $applicationId]);
    $app = $stmt->fetch();
    if (!$app) {
        abortWith($pdo, 404, 'Application not found.');
    }
    if (!in_array($app['status'], DECIDABLE_STATUSES, true)) {
        abortWith($pdo, 409, "Application is already {$app['status']}.");
    }

    $stmt = $pdo->prepare(
        'UPDATE enrollment_applications
         SET status = :status, reviewed_by = :by, reviewed_at = NOW(), remarks = :remarks
         WHERE id = :id'
    );
    $stmt->execute(['status' => 'rejected', 'by' => $staff['id'], 'remarks' => $remarks, 'id' => $applicationId]);

    writeAudit($pdo, $staff['id'], 'application.rejected', 'application', $applicationId, [
        'application_no'  => $app['application_no'],
        'previous_status' => $app['status'],
        'remarks'         => $remarks,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('reject failed: ' . $e->getMessage());
    jsonError(500, 'Could not reject the application.');
}

jsonResponse(200, ['application' => [
    'id' => $applicationId, 'application_no' => $app['application_no'], 'status' => 'rejected', 'remarks' => $remarks,
]]);
