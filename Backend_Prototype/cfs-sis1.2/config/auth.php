<?php
/**
 * Session handling + auth guards.
 * Include this (after database.php + helpers.php) in any endpoint that
 * needs to know who's logged in, or that should require it:
 *
 *   require __DIR__ . '/../../config/database.php';
 *   require __DIR__ . '/../../config/helpers.php';
 *   require __DIR__ . '/../../config/auth.php';
 *   $guardian = requireRole('guardian');   // ends the request with 401/403 if not allowed
 */

// Cookie flags must be set BEFORE session_start(). httponly stops JS from
// reading the cookie (XSS mitigation); samesite=Lax stops it being sent on
// cross-site requests. 'secure' should be true once this runs over HTTPS —
// on local XAMPP (plain http://localhost) it must stay false or the browser
// will silently refuse to send the cookie at all.
session_set_cookie_params([
    'lifetime' => 0,            // expires when the browser closes
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => false,        // TODO: set true when served over HTTPS
]);
session_start();

/** The logged-in user's session row, or null if nobody's logged in. */
function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'    => $_SESSION['user_id'],
        'role'  => $_SESSION['role'],
        'email' => $_SESSION['email'],
    ];
}

/** 401s the request unless someone is logged in. Returns their session row. */
function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        jsonError(401, 'You must be logged in.');
    }
    return $user;
}

/**
 * 401s if nobody's logged in, 403s if they're logged in with the wrong role.
 * $role may be a single role string or an array of acceptable roles.
 */
function requireRole(string|array $role): array
{
    $user = requireLogin();
    $allowed = is_array($role) ? $role : [$role];
    if (!in_array($user['role'], $allowed, true)) {
        jsonError(403, 'You do not have permission to do that.');
    }
    return $user;
}

/**
 * Loads an application and checks the user may access it: 404 if it doesn't
 * exist, 403 if it belongs to another guardian. Registrar/admin may access
 * any application. Returns id, application_no, guardian_id, status.
 */
function requireApplicationAccess(PDO $pdo, int $applicationId, array $user): array
{
    $stmt = $pdo->prepare('SELECT id, application_no, guardian_id, status FROM enrollment_applications WHERE id = :id');
    $stmt->execute(['id' => $applicationId]);
    $application = $stmt->fetch();
    if (!$application) {
        jsonError(404, 'Application not found.');
    }
    $isOwner = (int) $application['guardian_id'] === (int) $user['id'];
    $isStaff = in_array($user['role'], ['registrar', 'admin'], true);
    if (!$isOwner && !$isStaff) {
        jsonError(403, 'You do not have permission to access this application.');
    }
    return $application;
}
