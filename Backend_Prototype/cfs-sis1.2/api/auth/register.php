<?php
/**
 * POST /api/auth/register.php
 * Public self-registration — always creates a 'guardian' account. Registrar
 * and admin accounts are not self-service; create those directly in the DB
 * (or build a separate admin-only endpoint later) so a stranger can't just
 * POST role:"registrar" and get into the registrar dashboard.
 *
 * Body: { email, password, full_name, mobile(optional) }
 * On success, logs the new user in immediately (matches the mockup's
 * "Create Account & Continue" flow) and returns their session info.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('POST');
$input = readJsonBody();

$missing = missingFields($input, ['email', 'password', 'full_name']);
if (!empty($missing)) {
    jsonError(400, 'Missing required fields: ' . implode(', ', $missing));
}

$email    = trim(strtolower($input['email']));
$password = $input['password'];
$fullName = trim($input['full_name']);
$mobile   = isset($input['mobile']) ? trim($input['mobile']) : null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError(400, 'Invalid email address.');
}
if (strlen($password) < 8) {
    jsonError(400, 'Password must be at least 8 characters.');
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    jsonError(409, 'An account with this email already exists.');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'INSERT INTO users (email, password_hash, full_name, mobile, role)
     VALUES (:email, :password_hash, :full_name, :mobile, "guardian")'
);
$stmt->execute([
    'email'         => $email,
    'password_hash' => $passwordHash,
    'full_name'     => $fullName,
    'mobile'        => $mobile,
]);
$userId = (int) $pdo->lastInsertId();

// Log the new guardian in right away.
session_regenerate_id(true);   // new session ID on privilege change — prevents session fixation
$_SESSION['user_id'] = $userId;
$_SESSION['role']    = 'guardian';
$_SESSION['email']   = $email;

jsonResponse(201, [
    'id'        => $userId,
    'email'     => $email,
    'full_name' => $fullName,
    'role'      => 'guardian',
]);
