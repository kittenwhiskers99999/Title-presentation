<?php
/**
 * GET /api/registrar/screening.php      Registrar/admin only.
 * All screening requests (newest first) with the guardian's contact details,
 * so the registrar can text the family a schedule.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';

requireMethod('GET');
requireStaff();

$stmt = $pdo->query(
    'SELECT r.id, r.child_name, r.birthdate, r.screening_type, r.previous_school, r.target_grade,
            r.preferred_schedule, r.status, r.created_at,
            u.full_name AS guardian_name, u.email AS guardian_email, u.mobile AS guardian_mobile
     FROM screening_requests r
     JOIN users u ON u.id = r.guardian_id
     ORDER BY r.id DESC
     LIMIT 200'
);

jsonResponse(200, ['screening_requests' => $stmt->fetchAll()]);
