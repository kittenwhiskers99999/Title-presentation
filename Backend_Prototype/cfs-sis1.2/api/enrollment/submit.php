<?php
/**
 * POST /api/enrollment/submit.php
 *
 * Creates an enrollment application for a Gr.1-6 student (new or
 * continuing), then generates their fee items. Kindergarten/transferee
 * applicants don't use this - they go through api/screening/request.php.
 *
 * guardian_id comes from the session, never from the request body.
 *
 * Body (JSON):
 *   Common (all optional): guardian_mobile, guardian_relationship
 *     (mother|father|legal_guardian), emergency_contact_name,
 *     emergency_contact_mobile
 *
 *   New student (always Grade 1):
 *     { "enrollment_type":"new", "grade_level":1, "first_name", "last_name",
 *       "birthdate":"YYYY-MM-DD", "sex":"male|female", "address", "lrn" }
 *
 *   Continuing student - EITHER an existing child on this account:
 *     { "enrollment_type":"continuing", "grade_level":3, "student_id":7 }
 *   OR (first time this child is in the system) the child's details, same
 *   fields as a new student plus "lrn":
 *     { "enrollment_type":"continuing", "grade_level":3, "first_name", "last_name", "birthdate", "lrn" ... }
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('POST');
$guardian   = requireRole('guardian');
$guardianId = (int) $guardian['id'];

$input = readJsonBody();

$missing = missingFields($input, ['enrollment_type', 'grade_level']);
if (!empty($missing)) {
    jsonError(400, 'Missing required fields: ' . implode(', ', $missing));
}

$enrollmentType = $input['enrollment_type'];
$gradeLevel     = (int) $input['grade_level'];

if (!in_array($enrollmentType, ['new', 'continuing'], true)) {
    jsonError(400, "enrollment_type must be 'new' or 'continuing'.");
}
if ($gradeLevel < 1 || $gradeLevel > 6) {
    jsonError(400, 'grade_level must be between 1 and 6. Kindergarten applicants use the screening request flow instead.');
}
if ($enrollmentType === 'new' && $gradeLevel !== 1) {
    jsonError(400, 'New students must enroll at Grade 1.');
}

// ---- optional guardian / emergency details ----
$relationship = $input['guardian_relationship'] ?? null;
if ($relationship !== null && $relationship !== '' &&
    (!is_string($relationship) || !in_array($relationship, ['mother', 'father', 'legal_guardian'], true))) {
    jsonError(400, "guardian_relationship must be 'mother', 'father' or 'legal_guardian'.");
}
$relationship  = ($relationship === '' ? null : $relationship);
$guardianMobile = optionalText($input, 'guardian_mobile', 30);
$emName        = optionalText($input, 'emergency_contact_name', 150);
$emMobile      = optionalText($input, 'emergency_contact_mobile', 30);

// ---- student details (needed for new, and for continuing without student_id) ----
$hasStudentId = isset($input['student_id']) && $input['student_id'] !== '' && $input['student_id'] !== null;
$needDetails  = ($enrollmentType === 'new') || !$hasStudentId;

$student = null;
if ($needDetails) {
    $missing = missingFields($input, ['first_name', 'last_name', 'birthdate']);
    if (!empty($missing)) {
        jsonError(400, 'Missing required fields: ' . implode(', ', $missing));
    }
    foreach (['first_name', 'last_name', 'birthdate'] as $k) {
        if (!is_string($input[$k])) {
            jsonError(400, "$k must be text.");
        }
    }
    $first = trim($input['first_name']);
    $last  = trim($input['last_name']);
    if (textLength($first) > 100 || textLength($last) > 100) {
        jsonError(400, 'Names must be at most 100 characters.');
    }
    if (!isValidDate($input['birthdate'])) {
        jsonError(400, 'birthdate must be in YYYY-MM-DD format.');
    }
    if ($input['birthdate'] > date('Y-m-d')) {
        jsonError(400, 'birthdate cannot be in the future.');
    }
    $sex = $input['sex'] ?? null;
    if ($sex === '') { $sex = null; }
    if ($sex !== null && (!is_string($sex) || !in_array($sex, ['male', 'female'], true))) {
        jsonError(400, "sex must be 'male' or 'female'.");
    }
    $address = optionalText($input, 'address', 255);
    $lrn = $input['lrn'] ?? null;
    if ($lrn !== null && $lrn !== '') {
        $lrn = is_string($lrn) ? preg_replace('/[\s-]+/', '', $lrn) : '';
        if (!preg_match('/^\d{12}$/', $lrn)) {
            jsonError(400, 'LRN must be 12 digits (spaces are ignored).');
        }
    } else {
        $lrn = null;
    }
    $student = ['first' => $first, 'last' => $last, 'birthdate' => $input['birthdate'],
                'sex' => $sex, 'address' => $address, 'lrn' => $lrn];
}

try {
    $stmt = $pdo->query('SELECT id, enrollment_open FROM school_years WHERE is_current = 1 LIMIT 1');
    $schoolYear = $stmt->fetch();
    if (!$schoolYear) {
        jsonError(500, 'No current school year is configured.');
    }
    if (!$schoolYear['enrollment_open']) {
        jsonError(403, 'Enrollment is not currently open.');
    }
    $schoolYearId = $schoolYear['id'];

    $pdo->beginTransaction();

    if ($student !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO students (guardian_id, first_name, last_name, birthdate, sex, address, lrn)
             VALUES (:guardian_id, :first_name, :last_name, :birthdate, :sex, :address, :lrn)'
        );
        $stmt->execute([
            'guardian_id' => $guardianId,
            'first_name'  => $student['first'],
            'last_name'   => $student['last'],
            'birthdate'   => $student['birthdate'],
            'sex'         => $student['sex'],
            'address'     => $student['address'],
            'lrn'         => $student['lrn'],
        ]);
        $studentId = (int) $pdo->lastInsertId();
    } else {
        // existing child: must belong to THIS guardian
        $studentId = (int) $input['student_id'];
        $stmt = $pdo->prepare('SELECT id FROM students WHERE id = :id AND guardian_id = :guardian_id');
        $stmt->execute(['id' => $studentId, 'guardian_id' => $guardianId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            jsonError(404, 'Student not found on your account.');
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO enrollment_applications
            (school_year_id, student_id, guardian_id, enrollment_type, grade_level, status, submitted_at,
             guardian_relationship, emergency_contact_name, emergency_contact_mobile)
         VALUES (:school_year_id, :student_id, :guardian_id, :enrollment_type, :grade_level, "submitted", NOW(),
                 :rel, :em_name, :em_mobile)'
    );
    $stmt->execute([
        'school_year_id'  => $schoolYearId,
        'student_id'      => $studentId,
        'guardian_id'     => $guardianId,
        'enrollment_type' => $enrollmentType,
        'grade_level'     => $gradeLevel,
        'rel'             => $relationship,
        'em_name'         => $emName,
        'em_mobile'       => $emMobile,
    ]);
    $applicationId = (int) $pdo->lastInsertId();

    if ($guardianMobile !== null) {
        $stmt = $pdo->prepare('UPDATE users SET mobile = :m WHERE id = :id');
        $stmt->execute(['m' => $guardianMobile, 'id' => $guardianId]);
    }

    // No fees here: the invoice (fee items) is created when the registrar approves the application.

    $pdo->commit();

    $stmt = $pdo->prepare('SELECT application_no FROM enrollment_applications WHERE id = :id');
    $stmt->execute(['id' => $applicationId]);
    $applicationNo = $stmt->fetchColumn();

    jsonResponse(201, [
        'application_id' => $applicationId,
        'application_no' => $applicationNo,
        'student_id'     => $studentId,
        'status'         => 'submitted',
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (($e->errorInfo[1] ?? null) === 1062) {
        // Two different unique keys can fire: the LRN, or one application per student per school year.
        if (stripos($e->getMessage(), 'lrn') !== false) {
            jsonError(409, 'A student with this LRN is already registered.');
        }
        jsonError(409, 'This student already has an application for the current school year.');
    }
    error_log('submit failed: ' . $e->getMessage());
    jsonError(500, 'Could not submit the application.');
}
