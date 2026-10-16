<?php
/**
 * POST /api/billing/pay.php        (multipart/form-data)
 *
 * A guardian reports a GCash payment: reference number + screenshot.
 * Fields: application_id, amount, gcash_ref_no, proof (JPG/PNG, max 5 MB),
 *         fee_item_id (optional — which fee this pays)
 *
 * The payment is stored as 'pending'; it only counts toward the balance once
 * the registrar verifies it. A GCash reference can be used once, ever: the
 * database's UNIQUE key on gcash_ref_no is the real guard (it is race-proof),
 * and a duplicate comes back as a clean 409.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/uploads.php';

requireMethod('POST');
$guardian = requireRole('guardian');
assertMultipartRequest();

$applicationId = filter_var($_POST['application_id'] ?? '', FILTER_VALIDATE_INT);
if (!$applicationId || $applicationId < 1) {
    jsonError(400, 'application_id (integer) is required.');
}

// Normalize the reference BEFORE it reaches the unique key: "1234 5678 90123"
// and "1234567890123" are the same GCash reference. GCash references are 13
// digits; we accept 10-20 so a format change doesn't block real payments.
$refInput = $_POST['gcash_ref_no'] ?? '';
$gcashRef = is_string($refInput) ? preg_replace('/[\s-]+/', '', trim($refInput)) : '';
if (!preg_match('/^\d{10,20}$/', $gcashRef)) {
    jsonError(400, 'gcash_ref_no must be 10-20 digits (spaces and dashes are ignored).');
}

$amountInput = $_POST['amount'] ?? '';
$amountCentavos = is_string($amountInput) ? pesosToCentavos($amountInput) : null;
if ($amountCentavos === null || $amountCentavos <= 0) {
    jsonError(400, 'amount must be a positive peso amount such as 5450 or 5450.50.');
}

$feeItemId = null;
$feeRaw = $_POST['fee_item_id'] ?? '';
if ($feeRaw !== '') {
    $feeItemId = filter_var($feeRaw, FILTER_VALIDATE_INT);
    if (!$feeItemId || $feeItemId < 1) {
        jsonError(400, 'fee_item_id must be a positive integer.');
    }
}

$application = requireApplicationAccess($pdo, $applicationId, $guardian);
if ($application['status'] !== 'approved') {
    jsonError(409, 'Payments open once the registrar approves your application and sends your invoice.');
}

if ($feeItemId !== null) {
    $stmt = $pdo->prepare('SELECT id FROM fee_items WHERE id = :f AND application_id = :a');
    $stmt->execute(['f' => $feeItemId, 'a' => $applicationId]);
    if (!$stmt->fetch()) {
        jsonError(404, 'Fee item not found on this application.');
    }
}

$stmt = $pdo->prepare('SELECT outstanding FROM v_application_balance WHERE application_id = :id');
$stmt->execute(['id' => $applicationId]);
$outstanding = max(0, pesosToCentavos((string) ($stmt->fetchColumn() ?: '0')) ?? 0);
if ($outstanding <= 0) {
    jsonError(409, 'This application has no outstanding balance.');
}
if ($amountCentavos > $outstanding) {
    jsonError(400, 'amount exceeds the outstanding balance of ₱' . centavosToPesos($outstanding) . '.');
}

$upload = validateUpload('proof', PROOF_TYPES, MAX_UPLOAD_BYTES);

$proofPath = reserveStoragePath('proofs', $upload['ext']);
$stored = false;

try {
    $pdo->beginTransaction();

    // Insert first: a duplicate reference fails here, before any file is written.
    $stmt = $pdo->prepare(
        'INSERT INTO payments (application_id, fee_item_id, amount, gcash_ref_no, proof_path, status)
         VALUES (:app, :fee, :amount, :ref, :proof, :status)'
    );
    $stmt->execute([
        'app'    => $applicationId,
        'fee'    => $feeItemId,
        'amount' => centavosToPesos($amountCentavos),
        'ref'    => $gcashRef,
        'proof'  => $proofPath,
        'status' => 'pending',
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    storeUpload($upload, $proofPath);
    $stored = true;

    $pdo->commit();
} catch (PDOException | RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($stored) {
        deleteStoredFile($proofPath);
    }
    if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
        jsonError(409, 'This GCash reference number has already been submitted.');
    }
    error_log('payment submit failed: ' . $e->getMessage());
    jsonError(500, 'Could not record the payment.');
}

$stmt = $pdo->prepare('SELECT submitted_at FROM payments WHERE id = :id');
$stmt->execute(['id' => $paymentId]);

jsonResponse(201, [
    'payment' => [
        'id'             => $paymentId,
        'application_id' => $applicationId,
        'fee_item_id'    => $feeItemId,
        'amount'         => centavosToPesos($amountCentavos),
        'gcash_ref_no'   => $gcashRef,
        'status'         => 'pending',
        'submitted_at'   => $stmt->fetchColumn(),
    ],
]);
