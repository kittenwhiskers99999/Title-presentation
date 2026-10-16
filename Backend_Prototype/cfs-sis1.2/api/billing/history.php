<?php
/**
 * GET /api/billing/history.php?application_id=1
 *
 * Payment history (newest first) plus the current balance. A guardian sees
 * their own applications; registrar/admin can see any. Pending and rejected
 * payments are included (with the registrar's remarks) so a guardian can see
 * what still needs attention. Storage paths are never exposed — proofs are
 * fetched through api/uploads/file.php.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('GET');
$user = requireLogin();

$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT);
if (!$applicationId || $applicationId < 1) {
    jsonError(400, 'application_id (integer) is required.');
}
requireApplicationAccess($pdo, $applicationId, $user);

$stmt = $pdo->prepare(
    'SELECT p.id, p.fee_item_id, f.name AS fee_item_name, p.amount, p.method, p.gcash_ref_no,
            p.status, p.remarks, p.submitted_at, p.verified_at
     FROM payments p
     LEFT JOIN fee_items f ON f.id = p.fee_item_id
     WHERE p.application_id = :id
     ORDER BY p.submitted_at DESC, p.id DESC'
);
$stmt->execute(['id' => $applicationId]);
$payments = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT total_assessed, total_paid, outstanding FROM v_application_balance WHERE application_id = :id');
$stmt->execute(['id' => $applicationId]);
$balance = $stmt->fetch() ?: ['total_assessed' => '0.00', 'total_paid' => '0.00', 'outstanding' => '0.00'];

jsonResponse(200, ['payments' => $payments, 'balance' => $balance]);
