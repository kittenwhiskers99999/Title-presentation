<?php
/**
 * POST /api/uploads/document.php        (multipart/form-data)
 *
 * A guardian uploads one requirement file for one of their applications.
 * Fields: application_id, doc_type, file
 *   doc_type: psa_birth_certificate | report_card | good_moral | id_photo | other
 * Accepted: JPG, PNG, PDF up to 5 MB (see config/uploads.php).
 *
 * One file per doc_type per application. Uploading the same doc_type again
 * REPLACES the earlier file and sends it back to 'pending' — unless the
 * registrar already verified it, in which case it is locked (409).
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/uploads.php';

const DOC_KINDS = ['psa_birth_certificate', 'report_card', 'good_moral', 'id_photo', 'other'];
const UPLOADABLE_STATUSES = ['draft', 'submitted', 'pending_verification', 'needs_revision'];

requireMethod('POST');
$guardian = requireRole('guardian');
assertMultipartRequest();

$applicationId = filter_var($_POST['application_id'] ?? '', FILTER_VALIDATE_INT);
if (!$applicationId || $applicationId < 1) {
    jsonError(400, 'application_id (integer) is required.');
}
$docType = $_POST['doc_type'] ?? '';
if (!is_string($docType) || !in_array($docType, DOC_KINDS, true)) {
    jsonError(400, 'doc_type must be one of: ' . implode(', ', DOC_KINDS) . '.');
}

$application = requireApplicationAccess($pdo, $applicationId, $guardian);
if (!in_array($application['status'], UPLOADABLE_STATUSES, true)) {
    jsonError(409, "This application is already {$application['status']} and no longer accepts documents.");
}

$upload = validateUpload('file', DOCUMENT_TYPES, MAX_UPLOAD_BYTES);

$newPath  = reserveStoragePath('documents', $upload['ext']);
$stored   = false;
$replaced = false;
$oldPath  = null;

try {
    $pdo->beginTransaction();

    // Lock the existing row (if any) so two simultaneous uploads can't interleave.
    $stmt = $pdo->prepare('SELECT id, file_path, status FROM documents WHERE application_id = :a AND doc_type = :t FOR UPDATE');
    $stmt->execute(['a' => $applicationId, 't' => $docType]);
    $existing = $stmt->fetch();

    if ($existing && $existing['status'] === 'verified') {
        $pdo->rollBack();
        jsonError(409, 'This document was already verified by the registrar and can no longer be replaced.');
    }

    storeUpload($upload, $newPath);
    $stored = true;

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE documents
             SET file_path = :p, status = :s, remarks = NULL, verified_by = NULL, verified_at = NULL, uploaded_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['p' => $newPath, 's' => 'pending', 'id' => $existing['id']]);
        $documentId = (int) $existing['id'];
        $replaced = true;
        $oldPath = $existing['file_path'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO documents (application_id, doc_type, file_path, status) VALUES (:a, :t, :p, :s)');
        $stmt->execute(['a' => $applicationId, 't' => $docType, 'p' => $newPath, 's' => 'pending']);
        $documentId = (int) $pdo->lastInsertId();
    }

    $pdo->commit();
} catch (PDOException | RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($stored) {
        deleteStoredFile($newPath);   // don't leave an orphan file behind
    }
    if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
        jsonError(409, 'A document of this type was just uploaded. Please try again.');
    }
    error_log('document upload failed: ' . $e->getMessage());
    jsonError(500, 'Could not save the document.');
}

deleteStoredFile($oldPath);   // only after the database points at the new file

jsonResponse($replaced ? 200 : 201, [
    'document' => [
        'id'             => $documentId,
        'application_id' => $applicationId,
        'doc_type'       => $docType,
        'status'         => 'pending',
        'replaced'       => $replaced,
    ],
]);
