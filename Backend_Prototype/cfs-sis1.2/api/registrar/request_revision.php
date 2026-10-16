<?php
/**
 * POST /api/registrar/request_revision.php   (JSON)
 * Body: { "application_id": 12, "remarks": "what the family must fix (required)" }
 * "Flag for follow-up": sets the application to needs_revision. Unlike reject,
 * this is NOT final - the guardian can upload replacement documents (the
 * upload endpoint accepts needs_revision) and the registrar decides again.
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
    jsonError(400, 'remarks (what the family needs to fix) are required.');
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
    $stmt->execute(['status' => 'needs_revision', 'by' => $staff['id'], 'remarks' => $remarks, 'id' => $applicationId]);

    writeAudit($pdo, $staff['id'], 'application.revision_requested', 'application', $applicationId, [
        'application_no'  => $app['application_no'],
        'previous_status' => $app['status'],
        'remarks'         => $remarks,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('request_revision failed: ' . $e->getMessage());
    jsonError(500, 'Could not update the application.');
}

jsonResponse(200, ['application' => [
    'id' => $applicationId, 'application_no' => $app['application_no'], 'status' => 'needs_revision', 'remarks' => $remarks,
]]);
