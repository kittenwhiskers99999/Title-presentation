<?php
/**
 * POST /api/auth/logout.php
 * Destroys the session server-side and clears the cookie client-side.
 * POST (not GET) on purpose — a logout triggered by a plain link/image
 * can be forced cross-site; requiring POST makes that harder.
 */

require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('POST');

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

jsonResponse(200, ['message' => 'Logged out.']);
