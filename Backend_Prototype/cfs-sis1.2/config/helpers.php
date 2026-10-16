<?php
/**
 * Shared helpers for every API script. Include after database.php:
 *   require __DIR__ . '/../../config/database.php';
 *   require __DIR__ . '/../../config/helpers.php';
 */

/** Send a JSON response and stop execution. */
function jsonResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Shortcut for the common error shape: jsonError(400, 'message'). */
function jsonError(int $statusCode, string $message): void
{
    jsonResponse($statusCode, ['error' => $message]);
}

/** Decode the JSON request body, or 400 if it's missing/invalid. */
function readJsonBody(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        jsonError(400, 'Invalid or missing JSON body.');
    }
    return $input;
}

/** 405 unless the request uses the given HTTP method. */
function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        jsonError(405, "Use $method.");
    }
}

/**
 * Checks that every field in $required is present and non-empty in $input.
 * Returns the list of missing field names (empty array = all present).
 */
function missingFields(array $input, array $required): array
{
    return array_values(array_filter(
        $required,
        fn($field) => !isset($input[$field]) || $input[$field] === ''
    ));
}

/** True if $value is a YYYY-MM-DD date that actually exists on the calendar. */
function isValidDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

/**
 * Peso string -> integer centavos, so money math never touches floats.
 * "5450", "5450.5", "5450.50" are valid; "5,450", "1e3", "12.345", "-5" are not.
 */
function pesosToCentavos(string $value): ?int
{
    if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', trim($value), $m)) {
        return null;
    }
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
}

/** Integer centavos -> "5450.50" (the format DECIMAL(12,2) columns use). */
function centavosToPesos(int $centavos): string
{
    $sign = $centavos < 0 ? '-' : '';
    $abs = abs($centavos);
    return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
}

/** Reads $input[$key] as a positive integer, or ends the request with 400. */
function requireIntField(array $input, string $key): int
{
    $raw = $input[$key] ?? null;
    $value = (is_bool($raw) || is_array($raw)) ? false : filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        jsonError(400, "$key (positive integer) is required.");
    }
    return $value;
}

/** Trimmed optional text field: null when absent/blank, 400 when longer than $max. */
function optionalText(array $input, string $key, int $max = 500): ?string
{
    if (!isset($input[$key]) || !is_string($input[$key])) {
        return null;
    }
    $value = trim($input[$key]);
    if ($value === '') {
        return null;
    }
    if (textLength($value) > $max) {
        jsonError(400, "$key must be at most $max characters.");
    }
    return $value;
}

/** Character count that works with or without PHP's mbstring extension. */
function textLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }
    $count = preg_match_all('/./su', $value);          // counts UTF-8 characters
    return $count === false ? strlen($value) : $count; // invalid UTF-8: fall back to bytes
}
