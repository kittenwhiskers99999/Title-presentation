<?php
/**
 * POST /api/registrar/send_invoice.php   (JSON)
 * Body: { "application_id": 12 }
 *
 * Registrar/admin only. "Sends the invoice" to the family: creates the
 * application's fee items from the fee schedule. That is what makes the
 * balance and the Pay buttons appear in the family portal.
 *
 * Rules: only APPROVED applications can be invoiced, and only once. If no
 * fee schedule exists for the application's grade and school year the call
 * fails with a clear message and nothing is created.
 *
 * (Scholars with a zero balance will plug in here later.)
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('POST');
$staff = requireStaff();
$input = readJsonBody();

$applicationId = requireIntField($input, 'application_id');

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, application_no, status, grade_level FROM enrollment_applications WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $applicationId]);
    $app = $stmt->fetch();
    if (!$app) {
        abortWith($pdo, 404, 'Application not found.');
    }
    if ($app['status'] !== 'approved') {
        abortWith($pdo, 409, 'Only approved applications can be invoiced. This one is ' . $app['status'] . '.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM fee_items WHERE application_id = :id');
    $stmt->execute(['id' => $applicationId]);
    if ((int) $stmt->fetchColumn() > 0) {
        abortWith($pdo, 409, 'An invoice was already sent for this application.');
    }

    $stmt = $pdo->prepare('CALL generate_fee_items(:application_id)');
    $stmt->execute(['application_id' => $applicationId]);
    $stmt->closeCursor();   // CALL can leave a result set open; free it before the next query

    $stmt = $pdo->prepare('SELECT COUNT(*) AS items, COALESCE(SUM(amount), 0) AS total FROM fee_items WHERE application_id = :id');
    $stmt->execute(['id' => $applicationId]);
    $invoice = $stmt->fetch();

    if ((int) $invoice['items'] === 0) {
        abortWith($pdo, 409, "No fee schedule is set up for Grade {$app['grade_level']} in this application's school year, so no invoice could be created. Add the fees to the fee_schedules table first.");
    }

    writeAudit($pdo, $staff['id'], 'invoice.sent', 'application', $applicationId, [
        'application_no' => $app['application_no'],
        'items'          => (int) $invoice['items'],
        'total'          => $invoice['total'],
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('send_invoice failed: ' . $e->getMessage());
    jsonError(500, 'Could not send the invoice.');
}

jsonResponse(200, ['invoice' => [
    'application_id' => $applicationId,
    'application_no' => $app['application_no'],
    'items'          => (int) $invoice['items'],
    'total'          => $invoice['total'],
]]);
