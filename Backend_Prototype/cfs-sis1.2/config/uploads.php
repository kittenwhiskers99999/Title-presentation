<?php
/**
 * File upload helpers, shared by api/uploads/document.php (requirement
 * files) and api/billing/pay.php (GCash proof screenshots).
 *
 * Security model, in order of what each layer protects against:
 *   1. Never trust the client: we ignore the client's filename (except its
 *      extension) and the client's claimed Content-Type. The real type is
 *      sniffed from the file's bytes with finfo, and must agree with the
 *      extension.
 *   2. Random server-generated filenames: no path traversal, no overwriting,
 *      and the stored name reveals nothing about the student.
 *   3. Storage that can't be requested by URL (see UPLOAD_ROOT below), so an
 *      uploaded file can never be fetched or executed directly. Files are
 *      meant to be served only through an endpoint that checks who's asking.
 */

// ---- settings you may want to edit ----------------------------------------

// Where uploaded files live. Default is cfs-backend/storage/, locked down by
// storage/.htaccess (Apache must have AllowOverride enabled for it to apply —
// XAMPP's htdocs does by default). For stronger protection, point this
// OUTSIDE htdocs entirely, e.g. 'C:/xampp/cfs-storage': nothing outside the
// web root can be requested by URL no matter how Apache is configured.
// Whatever you choose must be writable by the Apache/PHP user.
const UPLOAD_ROOT = __DIR__ . '/../storage';

const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;   // 5 MB per file

// extension => the MIME type the file's bytes must actually be
const DOCUMENT_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
const PROOF_TYPES    = ['jpg' => 'image/jpeg', 'png' => 'image/png'];

// ---- request-level checks --------------------------------------------------

/** 415/413 unless this is a multipart upload that PHP actually received. */
function assertMultipartRequest(): void
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'multipart/form-data') !== 0) {
        jsonError(415, 'Send this request as multipart/form-data.');
    }
    // When a body exceeds post_max_size, PHP throws it away and both $_POST
    // and $_FILES arrive empty — no error code to read. Detect that here.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        jsonError(413, 'Upload is too large.');
    }
}

/**
 * Validates one uploaded file and returns ['tmp', 'ext', 'mime', 'size'].
 * Ends the request with a 4xx if anything is wrong. Does NOT move the file —
 * call storeUpload() once the database side is ready.
 *
 * @param string $field   the multipart field name, e.g. 'file' or 'proof'
 * @param array  $allowed extension => required MIME type
 */
function validateUpload(string $field, array $allowed, int $maxBytes): array
{
    if (!class_exists('finfo')) {
        error_log('PHP fileinfo extension is not enabled (php.ini: extension=fileinfo)');
        jsonError(500, 'Server is not configured for file uploads.');
    }
    if (!isset($_FILES[$field])) {
        jsonError(400, "Missing file field '$field'.");
    }
    $file = $_FILES[$field];
    if (is_array($file['name'])) {
        jsonError(400, 'Upload exactly one file.');
    }

    $maxMb = $maxBytes / 1024 / 1024;
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            jsonError(400, 'No file was uploaded.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            jsonError(413, "File is too large (max $maxMb MB).");
        case UPLOAD_ERR_PARTIAL:
            jsonError(400, 'The upload was interrupted. Please try again.');
        default:
            error_log('Upload error code ' . $file['error']);
            jsonError(500, 'The server could not receive the file.');
    }

    // Must be a real HTTP upload, not a path the client talked PHP into reading.
    if (!is_uploaded_file($file['tmp_name'])) {
        jsonError(400, 'Invalid upload.');
    }
    if ($file['size'] <= 0) {
        jsonError(400, 'The uploaded file is empty.');
    }
    if ($file['size'] > $maxBytes) {
        jsonError(413, "File is too large (max $maxMb MB).");
    }

    // Extension check (only the last extension counts: "a.php.jpg" -> "jpg").
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    $allowedList = strtoupper(implode(', ', array_keys($allowed)));
    if (!isset($allowed[$ext])) {
        jsonError(415, "Unsupported file type. Allowed: $allowedList.");
    }

    // Content check: sniff the real type from the bytes, ignoring whatever
    // Content-Type the client claimed. It must match the extension.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($mime !== $allowed[$ext]) {
        jsonError(415, "The file's contents don't match its extension. Allowed: $allowedList.");
    }
    // For images, also require that it decodes as one.
    if (str_starts_with($mime, 'image/') && @getimagesize($file['tmp_name']) === false) {
        jsonError(415, 'The image file is corrupted or unreadable.');
    }

    return ['tmp' => $file['tmp_name'], 'ext' => $ext, 'mime' => $mime, 'size' => (int) $file['size']];
}

// ---- storage ---------------------------------------------------------------

/** The .htaccess dropped into UPLOAD_ROOT: deny every direct web request. */
function storageHtaccess(): string
{
    return <<<'HTACCESS'
# Uploaded files must never be requested directly by URL.
Options -Indexes
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;
}

/** Creates UPLOAD_ROOT/$subdir (and the protective .htaccess) if missing. */
function ensureStorageDir(string $subdir): void
{
    $dir = UPLOAD_ROOT . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create storage directory: $dir");
    }
    $htaccess = UPLOAD_ROOT . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, storageHtaccess());
    }
}

/**
 * Picks the final relative path for a new file, e.g. 'proofs/9f2c...e1.png'.
 * The relative path is what goes in the database (file_path / proof_path);
 * UPLOAD_ROOT is prepended only when touching the disk.
 */
function reserveStoragePath(string $subdir, string $ext): string
{
    ensureStorageDir($subdir);
    return $subdir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
}

/** Moves a validated upload to its reserved path. Throws if the move fails. */
function storeUpload(array $upload, string $relativePath): void
{
    $dest = UPLOAD_ROOT . '/' . $relativePath;
    if (!move_uploaded_file($upload['tmp'], $dest)) {
        throw new RuntimeException("Could not move upload to $relativePath");
    }
    @chmod($dest, 0640);
}

/** Deletes a stored file by its relative path. Silently ignores null/missing. */
function deleteStoredFile(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }
    // Paths come from our own database, but never let one climb out of storage.
    if (str_contains($relativePath, '..') || str_contains($relativePath, '\\') || str_starts_with($relativePath, '/')) {
        error_log("Refusing to delete suspicious path: $relativePath");
        return;
    }
    $full = UPLOAD_ROOT . '/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Full disk path for a stored relative path, or null if invalid/missing. */
function storedFilePath(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === ''
        || str_contains($relativePath, '..') || str_contains($relativePath, '\\') || str_starts_with($relativePath, '/')) {
        return null;
    }
    $full = UPLOAD_ROOT . '/' . $relativePath;
    return is_file($full) ? $full : null;
}
