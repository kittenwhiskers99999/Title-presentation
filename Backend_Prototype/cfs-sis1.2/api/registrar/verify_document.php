<?php
/**
 * POST /api/registrar/verify_document.php   (JSON)
 * Body: { "document_id": 5, "decision": "verified" | "rejected", "remarks": "required when rejecting" }
 *
 * A document's review can be changed later (verified -> rejected, rejected ->
 * verified) because paperwork mistakes happen; every change is audited.
 * Setting it to the status it already has is a 409.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('POST');
$staff = requireStaff();
$input = readJsonBody();

$documentId = requireIntField($input, 'document_id');
$decision = $input['decision'] ?? '';
if (!is_string($decision) || !in_array($decision, ['verified', 'rejected'], true)) {
    jsonError(400, "decision must be 'verified' or 'rejected'.");
}
$remarks = optionalText($input, 'remarks');
if ($decision === 'rejected' && $remarks === null) {
    jsonError(400, 'remarks (the reason) are required when rejecting a document.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, application_id, doc_type, status FROM documents WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        abortWith($pdo, 404, 'Document not found.');
    }
    if ($doc['status'] === $decision) {
        abortWith($pdo, 409, "Document is already $decision.");
    }

    $stmt = $pdo->prepare(
        'UPDATE documents SET status = :status, remarks = :remarks, verified_by = :by, verified_at = NOW() WHERE id = :id'
    );
    $stmt->execute(['status' => $decision, 'remarks' => $remarks, 'by' => $staff['id'], 'id' => $documentId]);

    writeAudit($pdo, $staff['id'], "document.$decision", 'document', $documentId, [
        'application_id'  => (int) $doc['application_id'],
        'doc_type'        => $doc['doc_type'],
        'previous_status' => $doc['status'],
        'remarks'         => $remarks,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('verify_document failed: ' . $e->getMessage());
    jsonError(500, 'Could not update the document.');
}

jsonResponse(200, ['document' => [
    'id' => $documentId, 'application_id' => (int) $doc['application_id'],
    'doc_type' => $doc['doc_type'], 'status' => $decision, 'remarks' => $remarks,
]]);
