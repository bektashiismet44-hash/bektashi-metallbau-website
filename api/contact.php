<?php

declare(strict_types=1);

const MAX_REQUEST_BYTES = 65536;
const MIN_FORM_SECONDS = 3.0;
const MAX_FORM_SECONDS = 21600.0;
const MAIL_TO = 'i.b@bektashi-metallbau.ch';
const MAIL_FROM = 'i.b@bektashi-metallbau.ch';

$allowedOrigins = [
    'https://bektashi-metallbau.ch',
    'https://www.bektashi-metallbau.ch',
];

function sendJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function allowOrigin(string $origin, array $allowedOrigins): void
{
    if ($origin === '' || !in_array($origin, $allowedOrigins, true)) {
        sendJson(403, ['ok' => false, 'message' => 'Diese Anfrage ist nicht erlaubt.']);
    }

    header('Vary: Origin');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Max-Age: 600');
}

function readInput(): array
{
    $contentTypeHeader = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $contentType = trim(explode(';', $contentTypeHeader, 2)[0]);

    if ($contentType === 'application/json') {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            sendJson(400, ['ok' => false, 'message' => 'Die Anfrage enthält keine Daten.']);
        }

        try {
            $decoded = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            sendJson(400, ['ok' => false, 'message' => 'Die Anfrage enthält ungültiges JSON.']);
        }

        if (!is_array($decoded)) {
            sendJson(400, ['ok' => false, 'message' => 'Die Anfrage hat das falsche Format.']);
        }
        return $decoded;
    }

    if ($contentType === 'application/x-www-form-urlencoded' || $contentType === 'multipart/form-data') {
        return $_POST;
    }

    sendJson(415, ['ok' => false, 'message' => 'Dieser Inhaltstyp wird nicht unterstützt.']);
}

function textField(array $input, string $field): string
{
    if (!array_key_exists($field, $input)) return '';
    if (!is_string($input[$field])) {
        sendJson(422, ['ok' => false, 'message' => 'Bitte überprüfen Sie Ihre Eingaben.']);
    }

    $value = trim($input[$field]);
    if (preg_match('//u', $value) !== 1) {
        sendJson(422, ['ok' => false, 'message' => 'Bitte verwenden Sie gültige Zeichen.']);
    }
    return $value;
}

function charLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function hasHeaderBreak(string $value): bool
{
    return preg_match('/[\r\n\0]/', $value) === 1;
}

function encodeSubject(string $subject): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

if ($requestMethod === 'OPTIONS') {
    allowOrigin($origin, $allowedOrigins);
    http_response_code(204);
    exit;
}

if ($requestMethod !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJson(405, ['ok' => false, 'message' => 'Nur POST-Anfragen sind erlaubt.']);
}

allowOrigin($origin, $allowedOrigins);

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_REQUEST_BYTES) {
    sendJson(413, ['ok' => false, 'message' => 'Die Anfrage ist zu gross.']);
}

$input = readInput();

if (textField($input, 'website') !== '') {
    sendJson(200, ['ok' => true, 'message' => 'Danke. Ihre Anfrage wurde übermittelt.']);
}

$name = textField($input, 'name');
$email = textField($input, 'email');
$phone = textField($input, 'phone');
$projectType = textField($input, 'project_type');
$project = textField($input, 'project');
$message = textField($input, 'message');
$privacy = textField($input, 'privacy');
$errors = [];

if (charLength($name) < 2 || charLength($name) > 120) $errors['name'] = 'Bitte geben Sie einen Namen mit 2 bis 120 Zeichen ein.';
if ($email === '' || charLength($email) > 190 || hasHeaderBreak($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) $errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
if (charLength($phone) > 60) $errors['phone'] = 'Maximal 60 Zeichen erlaubt.';
if (charLength($projectType) < 2 || charLength($projectType) > 100) $errors['project_type'] = 'Bitte wählen Sie eine Projektart.';
if (charLength($project) < 2 || charLength($project) > 160) $errors['project'] = 'Bitte beschreiben Sie Projekt und Ort.';
if (charLength($message) < 10 || charLength($message) > 4000) $errors['message'] = 'Bitte geben Sie eine Nachricht mit 10 bis 4000 Zeichen ein.';
if ($privacy !== 'accepted') $errors['privacy'] = 'Bitte bestätigen Sie die Datenschutzhinweise.';

foreach ([$name, $phone, $projectType, $project] as $singleLineValue) {
    if (hasHeaderBreak($singleLineValue)) {
        $errors['form'] = 'Einzeilige Felder dürfen keine Zeilenumbrüche enthalten.';
        break;
    }
}

$formStartedRaw = $input['form_started'] ?? null;
if (!is_scalar($formStartedRaw) || !is_numeric((string) $formStartedRaw)) {
    $errors['form_started'] = 'Ungültiger Formular-Zeitstempel.';
} else {
    $formStarted = (float) $formStartedRaw;
    if ($formStarted > 100000000000.0) $formStarted /= 1000.0;
    $elapsed = microtime(true) - $formStarted;
    if ($elapsed < MIN_FORM_SECONDS || $elapsed > MAX_FORM_SECONDS) {
        $errors['form_started'] = 'Bitte laden Sie das Formular neu und versuchen Sie es nochmals.';
    }
}

if ($errors !== []) {
    sendJson(422, ['ok' => false, 'message' => 'Bitte überprüfen Sie Ihre Angaben.', 'errors' => $errors]);
}

date_default_timezone_set('Europe/Zurich');
$subject = encodeSubject('Neue Website-Anfrage: ' . $projectType . ' – ' . $name);
$bodyLines = [
    'Neue Anfrage über bektashi-metallbau.ch',
    '',
    'Name / Firma: ' . $name,
    'E-Mail: ' . $email,
    'Telefon: ' . ($phone !== '' ? $phone : '–'),
    'Projektart: ' . $projectType,
    'Projekt / Ort: ' . $project,
    'Eingang: ' . date('d.m.Y H:i:s') . ' Uhr',
    '',
    'Nachricht:',
    str_replace(["\r\n", "\r"], "\n", $message),
];

$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'From: Bektashi Metallbau Website <' . MAIL_FROM . '>',
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . PHP_VERSION,
];

$mailSent = mail(MAIL_TO, $subject, implode("\r\n", $bodyLines), implode("\r\n", $headers));

if (!$mailSent) {
    try { $requestId = bin2hex(random_bytes(6)); } catch (Throwable $exception) { $requestId = substr(hash('sha256', uniqid('', true)), 0, 12); }
    error_log('Contact form mail() failed; request_id=' . $requestId);
    sendJson(502, ['ok' => false, 'message' => 'Die Nachricht konnte momentan nicht gesendet werden.', 'request_id' => $requestId]);
}

sendJson(200, ['ok' => true, 'message' => 'Danke. Ihre Anfrage wurde übermittelt.']);
