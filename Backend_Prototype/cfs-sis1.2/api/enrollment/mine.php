<?php
/**
 * GET /api/enrollment/mine.php
 * Guardian only. Everything the family portal needs on load, in one call:
 *   guardian     - the logged-in guardian's own name/email/mobile (prefills the wizard)
 *   applications - their applications for ALL school years, newest first, with balance
 *   students     - their children (for "continuing student" applications)
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('GET');
$guardian = requireRole('guardian');
$gid = (int) $guardian['id'];

$stmt = $pdo->prepare('SELECT id, full_name, email, mobile FROM users WHERE id = :id');
$stmt->execute(['id' => $gid]);
$profile = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT a.id, a.application_no, a.status, a.enrollment_type, a.grade_level, a.submitted_at, a.remarks,
            sy.label AS school_year, s.first_name, s.last_name,
            COALESCE(b.total_assessed, 0) AS total_assessed,
            COALESCE(b.total_paid, 0)     AS total_paid,
            COALESCE(b.outstanding, 0)    AS outstanding
     FROM enrollment_applications a
     JOIN students s      ON s.id = a.student_id
     JOIN school_years sy ON sy.id = a.school_year_id
     LEFT JOIN v_application_balance b ON b.application_id = a.id
     WHERE a.guardian_id = :gid
     ORDER BY a.id DESC'
);
$stmt->execute(['gid' => $gid]);
$applications = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, first_name, last_name, birthdate, sex, lrn FROM students WHERE guardian_id = :gid ORDER BY first_name');
$stmt->execute(['gid' => $gid]);

jsonResponse(200, ['guardian' => $profile, 'applications' => $applications, 'students' => $stmt->fetchAll()]);
