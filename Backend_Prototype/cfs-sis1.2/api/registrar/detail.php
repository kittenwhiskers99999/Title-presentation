<?php
/**
 * GET /api/registrar/detail.php?application_id=1
 * Registrar/admin only. Everything the review screen needs in one call:
 * application, student, guardian, documents, payments, fee items, balance,
 * and the approval checklist (what's still blocking approval).
 * Stored file paths are never returned — view files via api/uploads/file.php.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('GET');
requireStaff();

$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT);
if (!$applicationId || $applicationId < 1) {
    jsonError(400, 'application_id (integer) is required.');
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.application_no, a.status, a.enrollment_type, a.grade_level,
            a.submitted_at, a.reviewed_at, a.remarks, sy.label AS school_year,
            a.guardian_relationship, a.emergency_contact_name, a.emergency_contact_mobile,
            rv.full_name AS reviewed_by_name,
            s.id AS student_id, s.first_name, s.last_name, s.birthdate, s.sex, s.address, s.lrn,
            u.id AS guardian_id, u.full_name AS guardian_name, u.email AS guardian_email, u.mobile AS guardian_mobile
     FROM enrollment_applications a
     JOIN school_years sy ON sy.id = a.school_year_id
     JOIN students s ON s.id = a.student_id
     JOIN users u ON u.id = a.guardian_id
     LEFT JOIN users rv ON rv.id = a.reviewed_by
     WHERE a.id = :id'
);
$stmt->execute(['id' => $applicationId]);
$row = $stmt->fetch();
if (!$row) {
    jsonError(404, 'Application not found.');
}

$stmt = $pdo->prepare(
    'SELECT d.id, d.doc_type, d.status, d.remarks, d.uploaded_at, d.verified_at, v.full_name AS verified_by_name
     FROM documents d LEFT JOIN users v ON v.id = d.verified_by
     WHERE d.application_id = :id ORDER BY d.doc_type'
);
$stmt->execute(['id' => $applicationId]);
$documents = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT p.id, p.fee_item_id, f.name AS fee_item_name, p.amount, p.gcash_ref_no, p.status, p.remarks,
            p.submitted_at, p.verified_at, v.full_name AS verified_by_name
     FROM payments p
     LEFT JOIN fee_items f ON f.id = p.fee_item_id
     LEFT JOIN users v ON v.id = p.verified_by
     WHERE p.application_id = :id ORDER BY p.submitted_at DESC, p.id DESC'
);
$stmt->execute(['id' => $applicationId]);
$payments = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, name, amount, due_date FROM fee_items WHERE application_id = :id ORDER BY sequence');
$stmt->execute(['id' => $applicationId]);
$feeItems = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT total_assessed, total_paid, outstanding FROM v_application_balance WHERE application_id = :id');
$stmt->execute(['id' => $applicationId]);
$balance = $stmt->fetch();

$blockers = getApprovalBlockers($pdo, $applicationId);

jsonResponse(200, [
    'application' => [
        'id' => $row['id'], 'application_no' => $row['application_no'], 'status' => $row['status'],
        'enrollment_type' => $row['enrollment_type'], 'grade_level' => $row['grade_level'],
        'school_year' => $row['school_year'], 'submitted_at' => $row['submitted_at'],
        'reviewed_at' => $row['reviewed_at'], 'reviewed_by_name' => $row['reviewed_by_name'],
        'remarks' => $row['remarks'],
        'guardian_relationship' => $row['guardian_relationship'],
        'emergency_contact_name' => $row['emergency_contact_name'],
        'emergency_contact_mobile' => $row['emergency_contact_mobile'],
    ],
    'student' => [
        'id' => $row['student_id'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
        'birthdate' => $row['birthdate'], 'sex' => $row['sex'], 'address' => $row['address'], 'lrn' => $row['lrn'],
    ],
    'guardian' => [
        'id' => $row['guardian_id'], 'full_name' => $row['guardian_name'],
        'email' => $row['guardian_email'], 'mobile' => $row['guardian_mobile'],
    ],
    'documents' => $documents,
    'payments'  => $payments,
    'fee_items' => $feeItems,
    'balance'   => $balance,
    'approval'  => [
        'can_decide' => in_array($row['status'], DECIDABLE_STATUSES, true),
        'ready'      => empty($blockers),
        'blockers'   => $blockers,
    ],
]);
