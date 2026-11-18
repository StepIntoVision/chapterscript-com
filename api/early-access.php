<?php

declare(strict_types=1);

namespace ChapterScript;

// Errors belong in the server log, never in a response containing connection details.
ini_set('display_errors', '0');
require __DIR__ . '/early-access-lib.php';

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
$json = $contentType === 'application/json' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$input = [];
$stage = 'read_request';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'Submit the early-access form using POST.', $json);
}

try {
    // Read at most 32 KiB, even if Content-Length is absent or false.
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false) {
        throw new \RuntimeException('Cannot read request body');
    }
    $tooLarge = strlen($raw) > MAX_BODY_BYTES || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY_BYTES;
    $parseError = false;
    if (!$tooLarge) {
        if ($contentType === 'application/json') {
            try {
                $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
                if (!$decoded instanceof \stdClass) {
                    $parseError = true;
                } else {
                    $input = (array) $decoded;
                }
            } catch (\JsonException $e) {
                $parseError = true;
            }
        } elseif ($contentType === 'application/x-www-form-urlencoded') {
            parse_str($raw, $input);
        }
    }
    $stage = 'locate_config';
    $configPath = dirname(__DIR__, 2) . '/private/config.php';
    if (!is_file($configPath)) {
        throw new \RuntimeException('Missing private configuration');
    }
    $stage = 'load_config';
    $config = require $configPath;
    $stage = 'validate_config';
    if (!is_array($config)) {
        throw new \RuntimeException('Invalid private configuration');
    }
    // Reject cross-site requests before they can consume the visitor's allowance.
    $stage = 'validate_origin';
    if (!originAllowed($_SERVER, $config['allowed_origins'] ?? ['https://chapterscript.com', 'https://www.chapterscript.com'])) {
        respond(403, 'Please submit the form from the ChapterScript website.', $json);
    }
    $stage = 'connect_database';
    $pdo = connect($config);
    $stage = 'validate_rate_limit_secret_and_ip';
    $key = ipKey($_SERVER['REMOTE_ADDR'] ?? '', $config['rate_limit_secret'] ?? '');
    $stage = 'rate_limit_database';
    $retry = rateLimit($pdo, $key, $config['rate_limits'] ?? [900 => 5, 86400 => 20], time());

    if ($retry > 0) {
        header('Retry-After: ' . $retry);
        $minutes = (int) ceil($retry / 60);
        respond(429, 'Too many attempts. Please try again in ' . $minutes . ($minutes === 1 ? ' minute' : ' minutes') . '.', $json, [], $input);
    }
    if ($tooLarge) {
        respond(413, 'This submission is too large. Please shorten your answers.', $json);
    }
    if (!in_array($contentType, ['application/json', 'application/x-www-form-urlencoded'], true)) {
        respond(415, 'Unsupported submission format.', $json);
    }
    if ($parseError) {
        respond(400, 'The form data could not be read. Please try again.', $json);
    }
    if (is_string($input['_honey'] ?? null) && trim($input['_honey']) !== '') {
        respond(200, 'Thank you. Your request has been received.', $json);
    }
    $stage = 'validate_submission';
    [$data, $errors] = validate($input);
    if ($errors) {
        respond(422, implode(' ', array_values($errors)), $json, $errors, $input);
    }
    $stage = 'save_application';
    saveApplication($pdo, $data);
    $stage = 'send_confirmation';
    respond(200, 'Thank you. Your request has been received.', $json);
} catch (\Throwable $e) {
    // Exception messages can contain credentials, SQL values or personal data.
    // Log the operation and numeric database error, not the driver's message.
    $driverCode = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    error_log('[chapterscript early access] stage=' . $stage . ' ' . get_class($e)
        . ' code=' . $e->getCode() . ' driver_code=' . $driverCode);
    respond(503, 'We could not save your request. Please try again later.', $json, [], $input);
}
