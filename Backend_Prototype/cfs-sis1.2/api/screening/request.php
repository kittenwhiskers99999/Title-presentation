<?php
/**
 * POST /api/screening/request.php   (JSON)   Guardian only.
 * Kindergarten applicants and transferees can't enroll directly; they ask the
 * registrar for a screening instead. Enrollment and payment open after they pass.
 *
 * Body: { "screening_type": "kindergarten" | "transferee",
 *         "child_name", "birthdate": "YYYY-MM-DD",
 *         "previous_school"   (required for transferee),
 *         "target_grade" 1-6  (required for transferee),
 *         "preferred_schedule" (optional), "guardian_mobile" (optional) }
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('POST');
$guardian = requireRole('guardian');
$gid = (int) $guardian['id'];
$input = readJsonBody();

$type = $input['screening_type'] ?? '';
if (!is_string($type) || !in_array($type, ['kindergarten', 'transferee'], true)) {
    jsonError(400, "screening_type must be 'kindergarten' or 'transferee'.");
}

$missing = missingFields($input, ['child_name', 'birthdate']);
if (!empty($missing)) {
    jsonError(400, 'Missing required fields: ' . implode(', ', $missing));
}
if (!is_string($input['child_name']) || !is_string($input['birthdate'])) {
    jsonError(400, 'child_name and birthdate must be text.');
}
$childName = trim($input['child_name']);
if (textLength($childName) > 200) {
    jsonError(400, 'child_name must be at most 200 characters.');
}
if (!isValidDate($input['birthdate']) || $input['birthdate'] > date('Y-m-d')) {
    jsonError(400, 'birthdate must be a valid past date (YYYY-MM-DD).');
}

$previousSchool = optionalText($input, 'previous_school', 200);
$schedule       = optionalText($input, 'preferred_schedule', 200);
$mobile         = optionalText($input, 'guardian_mobile', 30);

$targetGrade = null;
if ($type === 'transferee') {
    $targetGrade = filter_var($input['target_grade'] ?? '', FILTER_VALIDATE_INT);
    if ($targetGrade === false || $targetGrade < 1 || $targetGrade > 6) {
        jsonError(400, 'target_grade must be between 1 and 6 for a transferee.');
    }
    if ($previousSchool === null) {
        jsonError(400, 'previous_school is required for a transferee.');
    }
} else {
    $previousSchool = null;   // not applicable to kindergarten
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO screening_requests
            (guardian_id, child_name, birthdate, screening_type, previous_school, target_grade, preferred_schedule)
         VALUES (:g, :name, :dob, :type, :prev, :grade, :sched)'
    );
    $stmt->execute([
        'g' => $gid, 'name' => $childName, 'dob' => $input['birthdate'], 'type' => $type,
        'prev' => $previousSchool, 'grade' => $targetGrade, 'sched' => $schedule,
    ]);
    $id = (int) $pdo->lastInsertId();

    if ($mobile !== null) {
        $stmt = $pdo->prepare('UPDATE users SET mobile = :m WHERE id = :id');
        $stmt->execute(['m' => $mobile, 'id' => $gid]);
    }
} catch (PDOException $e) {
    error_log('screening request failed: ' . $e->getMessage());
    jsonError(500, 'Could not save the screening request.');
}

jsonResponse(201, ['screening_request' => ['id' => $id, 'status' => 'requested', 'screening_type' => $type]]);
