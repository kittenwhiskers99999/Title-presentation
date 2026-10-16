<?php
/**
 * GET /api/students/list.php
 * Returns the logged-in guardian's own children — used by the frontend
 * to let a guardian pick an existing child when submitting a "continuing
 * student" application, instead of re-entering their details and
 * accidentally creating a duplicate student record.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('GET');
$guardian = requireRole('guardian');

$stmt = $pdo->prepare(
    'SELECT id, first_name, last_name, birthdate, sex, lrn
     FROM students
     WHERE guardian_id = :guardian_id
     ORDER BY first_name'
);
$stmt->execute(['guardian_id' => $guardian['id']]);

jsonResponse(200, ['students' => $stmt->fetchAll()]);
