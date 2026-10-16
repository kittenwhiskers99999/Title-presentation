<?php
/**
 * POST /api/auth/login.php
 * Body: { email, password }
 * Works for any role (guardian, registrar, admin) — the frontend's
 * guardian sign-in and registrar sign-in screens can both hit this;
 * what they're allowed to do afterward is decided by requireRole()
 * on each individual endpoint, not by which login form was used.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('POST');
$input = readJsonBody();

$missing = missingFields($input, ['email', 'password']);
if (!empty($missing)) {
    jsonError(400, 'Missing required fields: ' . implode(', ', $missing));
}

$email    = trim(strtolower($input['email']));
$password = $input['password'];

$stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, is_active FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// Same error for "no such user" and "wrong password" — don't reveal which
// one it was, or you've just handed an attacker a way to enumerate emails.
if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonError(401, 'Incorrect email or password.');
}
if (!$user['is_active']) {
    jsonError(403, 'This account has been deactivated. Contact the registrar\'s office.');
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['role']    = $user['role'];
$_SESSION['email']   = $user['email'];

jsonResponse(200, [
    'id'        => $user['id'],
    'email'     => $user['email'],
    'full_name' => $user['full_name'],
    'role'      => $user['role'],
]);
