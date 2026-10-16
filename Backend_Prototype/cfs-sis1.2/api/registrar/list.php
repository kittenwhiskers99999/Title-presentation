<?php
/**
 * GET /api/registrar/list.php
 * Registrar/admin only. Query params (all optional):
 *   status          draft | submitted | pending_verification | needs_revision | approved | rejected
 *   grade           1-6
 *   search          student name, application no., guardian name or email
 *   school_year_id  defaults to the current school year
 *   page            default 1
 *   per_page        default 20, max 100
 * Newest submissions first.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('GET');
requireStaff();

$where  = [];
$params = [];

// ---- school year (default: current) ----
$syRaw = $_GET['school_year_id'] ?? '';
if ($syRaw !== '') {
    $sy = filter_var($syRaw, FILTER_VALIDATE_INT);
    if ($sy === false || $sy < 1) {
        jsonError(400, 'school_year_id must be a positive integer.');
    }
    $where[] = 'a.school_year_id = :school_year_id';
    $params['school_year_id'] = $sy;
} else {
    $where[] = 'a.school_year_id = (SELECT id FROM school_years WHERE is_current = 1 LIMIT 1)';
}

// ---- status ----
$status = $_GET['status'] ?? '';
if ($status !== '') {
    $valid = ['draft', 'submitted', 'pending_verification', 'needs_revision', 'approved', 'rejected'];
    if (!is_string($status) || !in_array($status, $valid, true)) {
        jsonError(400, 'status must be one of: ' . implode(', ', $valid) . '.');
    }
    $where[] = 'a.status = :status';
    $params['status'] = $status;
}

// ---- grade ----
$grade = $_GET['grade'] ?? '';
if ($grade !== '') {
    $g = filter_var($grade, FILTER_VALIDATE_INT);
    if ($g === false || $g < 1 || $g > 6) {
        jsonError(400, 'grade must be between 1 and 6.');
    }
    $where[] = 'a.grade_level = :grade';
    $params['grade'] = $g;
}

// ---- search ----
$search = $_GET['search'] ?? '';
if (!is_string($search)) {
    jsonError(400, 'search must be text.');
}
$search = trim($search);
if (textLength($search) > 100) {
    jsonError(400, 'search must be at most 100 characters.');
}
if ($search !== '') {
    // Escape LIKE wildcards so a search for "_" or "%" means those literal
    // characters instead of matching every row. '!' is the escape character.
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
    $where[] = "(s.first_name LIKE :q1 ESCAPE '!'
              OR s.last_name LIKE :q2 ESCAPE '!'
              OR CONCAT(s.first_name, ' ', s.last_name) LIKE :q3 ESCAPE '!'
              OR a.application_no LIKE :q4 ESCAPE '!'
              OR u.full_name LIKE :q5 ESCAPE '!'
              OR u.email LIKE :q6 ESCAPE '!')";
    foreach (range(1, 6) as $i) {
        $params["q$i"] = $like;
    }
}

// ---- pagination (validated ints, so safe to place in the SQL directly) ----
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$perPage = filter_var($_GET['per_page'] ?? 20, FILTER_VALIDATE_INT);
if ($page === false || $page < 1) {
    jsonError(400, 'page must be a positive integer.');
}
if ($perPage === false || $perPage < 1 || $perPage > 100) {
    jsonError(400, 'per_page must be between 1 and 100.');
}
$offset = ($page - 1) * $perPage;

$whereSql = 'WHERE ' . implode(' AND ', $where);
$fromSql = 'FROM enrollment_applications a
            JOIN students s ON s.id = a.student_id
            JOIN users u ON u.id = a.guardian_id';

$stmt = $pdo->prepare("SELECT COUNT(*) $fromSql $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT a.id, a.application_no, a.status, a.enrollment_type, a.grade_level, a.submitted_at,
            s.first_name, s.last_name,
            u.full_name AS guardian_name, u.email AS guardian_email, u.mobile AS guardian_mobile,
            COALESCE(b.total_assessed, 0) AS total_assessed,
            COALESCE(b.total_paid, 0) AS total_paid,
            COALESCE(b.outstanding, 0) AS outstanding,
            (SELECT COUNT(*) FROM payments p WHERE p.application_id = a.id AND p.status = 'pending') AS pending_payments,
            (SELECT COUNT(*) FROM documents d WHERE d.application_id = a.id AND d.status = 'pending') AS pending_documents
     $fromSql
     LEFT JOIN v_application_balance b ON b.application_id = a.id
     $whereSql
     ORDER BY COALESCE(a.submitted_at, a.created_at) DESC, a.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);

jsonResponse(200, [
    'applications' => $stmt->fetchAll(),
    'pagination'   => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
]);
