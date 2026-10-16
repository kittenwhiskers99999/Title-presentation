<?php
/**
 * GET /api/auth/me.php
 * Lets the frontend check "is anyone logged in, and as what" on page
 * load — e.g. to decide whether to show the portal or the auth screen
 * when the Family Portal first opens.
 *
 * Deliberately returns 200 + {logged_in:false} rather than 401 when
 * nobody's logged in — this is a status check, not a protected resource.
 */

require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('GET');

$user = currentUser();
if (!$user) {
    jsonResponse(200, ['logged_in' => false]);
}

jsonResponse(200, ['logged_in' => true] + $user);
