<?php
/**
 * Shared rules + helpers for api/registrar/*.php.
 * Include after database.php, helpers.php and auth.php.
 */

/** Documents that must be VERIFIED before an application can be approved.
 *  good_moral is "if applicable" in the mockup, so it is not forced. */
const REQUIRED_DOCUMENT_TYPES = [
    'psa_birth_certificate' => 'PSA birth certificate',
    'report_card'           => 'Report card / Form 138',
    'id_photo'              => '2x2 ID photo',
];

/** Statuses an application can still be approved or rejected from. */
const DECIDABLE_STATUSES = ['submitted', 'pending_verification', 'needs_revision'];

/** Ends the request with 401/403 unless a registrar or admin is logged in. */
function requireStaff(): array
{
    return requireRole(['registrar', 'admin']);
}

/** Rolls back any open transaction, then ends the request with an error. */
function abortWith(PDO $pdo, int $status, string $message, array $extra = []): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse($status, ['error' => $message] + $extra);
}

/**
 * Appends a row to audit_logs. Call it INSIDE the same transaction as the
 * change it describes, so the action and its audit record succeed or fail
 * together — there is never an action without a record.
 */
function writeAudit(PDO $pdo, int $actorId, string $action, string $entity, int $entityId, array $details = []): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity, entity_id, details)
         VALUES (:actor, :action, :entity, :entity_id, :details)'
    );
    $stmt->execute([
        'actor'     => $actorId,
        'action'    => $action,
        'entity'    => $entity,
        'entity_id' => $entityId,
        'details'   => json_encode($details ?: new stdClass(), JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * What still stands between this application and approval, as readable
 * strings. Empty array = ready. Rules: every REQUIRED_DOCUMENT_TYPES document
 * is verified, and verified payments cover the enrollment fee (the fee item
 * with the lowest sequence number).
 */
function getApprovalBlockers(PDO $pdo, int $applicationId): array
{
    $blockers = [];

    $stmt = $pdo->prepare('SELECT doc_type, status FROM documents WHERE application_id = :id');
    $stmt->execute(['id' => $applicationId]);
    $docs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach (REQUIRED_DOCUMENT_TYPES as $type => $label) {
        if (!isset($docs[$type])) {
            $blockers[] = "Missing document: $label";
        } elseif ($docs[$type] !== 'verified') {
            $blockers[] = "Document not verified: $label ({$docs[$type]})";
        }
    }

    // Payment is NOT a blocker any more: the invoice is only issued after approval.

    return $blockers;
}
