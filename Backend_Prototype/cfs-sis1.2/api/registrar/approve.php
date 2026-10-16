<?php
/**
 * POST /api/registrar/approve.php   (JSON)
 * Body: { "application_id": 12, "remarks": "optional", "override": false }
 *
 * Approves an application. By default it refuses with 409 (and a list of
 * "blockers") until the checklist in config/registrar.php is satisfied:
 * required documents verified + enrollment fee covered by verified payments.
 * To approve anyway (e.g. the school accepts missing papers later), send
 * "override": true plus "remarks" explaining why; the override and every
 * skipped check are recorded in the audit log.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('POST');
$staff = requireStaff();
$input = readJsonBody();

$applicationId = requireIntField($input, 'application_id');
$remarks  = optionalText($input, 'remarks');
$override = ($input['override'] ?? false) === true;

try {
    $pdo->beginTransaction();

    // Lock the row so two registrars can't approve/reject the same application at once.
    $stmt = $pdo->prepare('SELECT id, application_no, status FROM enrollment_applications WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $applicationId]);
    $app = $stmt->fetch();
    if (!$app) {
        abortWith($pdo, 404, 'Application not found.');
    }
    if (!in_array($app['status'], DECIDABLE_STATUSES, true)) {
        abortWith($pdo, 409, "Application is already {$app['status']}.");
    }

    $blockers = getApprovalBlockers($pdo, $applicationId);
    if ($blockers && !$override) {
        abortWith($pdo, 409, 'Application is not ready for approval.', ['blockers' => $blockers]);
    }
    if ($blockers && $remarks === null) {
        abortWith($pdo, 400, 'remarks are required when overriding the approval checklist.', ['blockers' => $blockers]);
    }

    $stmt = $pdo->prepare(
        'UPDATE enrollment_applications
         SET status = :status, reviewed_by = :by, reviewed_at = NOW(), remarks = :remarks
         WHERE id = :id'
    );
    $stmt->execute(['status' => 'approved', 'by' => $staff['id'], 'remarks' => $remarks, 'id' => $applicationId]);

    // Approving does NOT create the invoice. The registrar sends it separately (api/registrar/send_invoice.php).

    writeAudit($pdo, $staff['id'], 'application.approved', 'application', $applicationId, [
        'application_no'  => $app['application_no'],
        'previous_status' => $app['status'],
        'remarks'         => $remarks,
        'override'        => !empty($blockers),
        'skipped_checks'  => $blockers,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('approve failed: ' . $e->getMessage());
    jsonError(500, 'Could not approve the application.');
}

jsonResponse(200, ['application' => [
    'id' => $applicationId, 'application_no' => $app['application_no'], 'status' => 'approved', 'remarks' => $remarks,
]]);
