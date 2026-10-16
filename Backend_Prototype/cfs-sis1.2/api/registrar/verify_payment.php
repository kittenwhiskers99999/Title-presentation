<?php
/**
 * POST /api/registrar/verify_payment.php   (JSON)
 * Body: { "payment_id": 9, "decision": "verified" | "rejected", "remarks": "required when rejecting" }
 *
 * Only a PENDING payment can be decided; verified and rejected are final
 * (money decisions are not silently flipped — build a reversal flow if the
 * school needs one). Verifying is refused if it would push verified payments
 * past the application's total assessed fees.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('POST');
$staff = requireStaff();
$input = readJsonBody();

$paymentId = requireIntField($input, 'payment_id');
$decision = $input['decision'] ?? '';
if (!is_string($decision) || !in_array($decision, ['verified', 'rejected'], true)) {
    jsonError(400, "decision must be 'verified' or 'rejected'.");
}
$remarks = optionalText($input, 'remarks');
if ($decision === 'rejected' && $remarks === null) {
    jsonError(400, 'remarks (the reason) are required when rejecting a payment.');
}

try {
    $pdo->beginTransaction();

    // The JOIN makes FOR UPDATE lock the application row too, so two payments
    // for the same application are verified one at a time and the overpayment
    // check below can't be raced.
    $stmt = $pdo->prepare(
        'SELECT p.id, p.application_id, p.amount, p.status, p.gcash_ref_no
         FROM payments p
         JOIN enrollment_applications a ON a.id = p.application_id
         WHERE p.id = :id FOR UPDATE'
    );
    $stmt->execute(['id' => $paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        abortWith($pdo, 404, 'Payment not found.');
    }
    if ($payment['status'] !== 'pending') {
        abortWith($pdo, 409, "Payment is already {$payment['status']}.");
    }
    $applicationId = (int) $payment['application_id'];

    if ($decision === 'verified') {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM fee_items WHERE application_id = :id');
        $stmt->execute(['id' => $applicationId]);
        $assessed = pesosToCentavos((string) $stmt->fetchColumn()) ?? 0;

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE application_id = :id AND status = 'verified'");
        $stmt->execute(['id' => $applicationId]);
        $alreadyPaid = pesosToCentavos((string) $stmt->fetchColumn()) ?? 0;

        $thisAmount = pesosToCentavos((string) $payment['amount']) ?? 0;
        if ($alreadyPaid + $thisAmount > $assessed) {
            abortWith($pdo, 409, 'Verifying this payment would exceed the total fees assessed (₱'
                . centavosToPesos($assessed) . '). Reject it, or ask the guardian to correct the amount.');
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE payments SET status = :status, remarks = :remarks, verified_by = :by, verified_at = NOW() WHERE id = :id'
    );
    $stmt->execute(['status' => $decision, 'remarks' => $remarks, 'by' => $staff['id'], 'id' => $paymentId]);

    writeAudit($pdo, $staff['id'], "payment.$decision", 'payment', $paymentId, [
        'application_id' => $applicationId,
        'amount'         => $payment['amount'],
        'gcash_ref_no'   => $payment['gcash_ref_no'],
        'remarks'        => $remarks,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('verify_payment failed: ' . $e->getMessage());
    jsonError(500, 'Could not update the payment.');
}

$stmt = $pdo->prepare('SELECT total_assessed, total_paid, outstanding FROM v_application_balance WHERE application_id = :id');
$stmt->execute(['id' => $applicationId]);

jsonResponse(200, [
    'payment' => ['id' => $paymentId, 'application_id' => $applicationId, 'status' => $decision, 'remarks' => $remarks],
    'balance' => $stmt->fetch(),
]);
