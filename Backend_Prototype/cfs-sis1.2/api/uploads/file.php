<?php
/**
 * GET /api/uploads/file.php?type=document|payment&id=5
 *
 * The only way to view an uploaded file. Stored files cannot be requested
 * directly by URL, so this checks who is asking first: the guardian who owns
 * the application, or registrar/admin. Streams the file with a content type
 * taken from our own extension map (never from the client).
 *   type=document -> a requirement file     type=payment -> a GCash proof
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/uploads.php';

requireMethod('GET');
$user = requireLogin();

$type = $_GET['type'] ?? '';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_string($type) || !in_array($type, ['document', 'payment'], true)) {
    jsonError(400, "type must be 'document' or 'payment'.");
}
if (!$id || $id < 1) {
    jsonError(400, 'id (positive integer) is required.');
}

$sql = $type === 'document'
    ? 'SELECT x.file_path AS path, x.application_id FROM documents x WHERE x.id = :id'
    : 'SELECT x.proof_path AS path, x.application_id FROM payments x WHERE x.id = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    jsonError(404, 'File not found.');
}

requireApplicationAccess($pdo, (int) $row['application_id'], $user);   // 403 for someone else's file

$full = storedFilePath($row['path']);
if ($full === null) {
    error_log("File record $type #$id points to a missing file: {$row['path']}");
    jsonError(404, 'File not found.');
}

$mimeByExt = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
if (!isset($mimeByExt[$ext])) {
    jsonError(500, 'Unsupported stored file type.');
}

session_write_close();   // don't hold the session lock while streaming
header('Content-Type: ' . $mimeByExt[$ext]);
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="' . $type . '-' . $id . '.' . $ext . '"');
readfile($full);
exit;
