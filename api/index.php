<?php

declare(strict_types=1);

$allowedOrigin = getenv('CORS_ORIGIN') ?: 'https://letremouille.fr';

header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/reservation') {
    http_response_code(404);
    exit(json_encode(['error' => 'Not found']));
}

// --- Parse input (FormData or JSON) ---
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
$input = str_contains($ct, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : $_POST;

// Honeypot
if (!empty($input['_honey'])) {
    exit(json_encode(['success' => true]));
}

// --- Validate ---
$name    = trim($input['name'] ?? '');
$email   = trim($input['email'] ?? '');
$phone   = trim($input['phone'] ?? '');
$guests  = trim($input['guests'] ?? '');
$date    = trim($input['date'] ?? '');
$time    = trim($input['time'] ?? '');
$message = trim($input['message'] ?? '');

$errors = [];
if ($name === '')  $errors[] = 'name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if ($phone === '') $errors[] = 'phone';
if ($guests === '') $errors[] = 'guests';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'date';
if ($time === '')  $errors[] = 'time';

if ($errors) {
    http_response_code(422);
    exit(json_encode(['error' => 'Champs invalides', 'fields' => $errors]));
}

// --- Format date in French ---
$dateFormatted = formatDateFr($date);

// --- Config ---
$resendKey = getenv('RESEND_API_KEY');
$toEmail   = getenv('TREMOUILLE_TO_EMAIL') ?: 'contact@letremouille.com';
$fromEmail = getenv('TREMOUILLE_FROM_EMAIL') ?: 'noreply@mozaiklabs.fr';
$fromName  = 'Le Trémouille';

if (!$resendKey) {
    error_log('RESEND_API_KEY not configured');
    http_response_code(500);
    exit(json_encode(['error' => 'Configuration serveur manquante']));
}

// --- Escape for HTML emails ---
$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// --- Send notification to restaurateur ---
$ok1 = sendEmail($resendKey, $fromEmail, $fromName, $toEmail,
    "Réservation — {$name}, {$guests} pers. le {$dateFormatted} à {$time}",
    buildNotificationHtml($h, $name, $email, $phone, $guests, $dateFormatted, $time, $message),
    $email
);

// --- Send confirmation to client ---
$ok2 = sendEmail($resendKey, $fromEmail, $fromName, $email,
    'Votre réservation au Trémouille',
    buildConfirmationHtml($h, $name, $guests, $dateFormatted, $time),
    $toEmail
);

if ($ok1 && $ok2) {
    exit(json_encode(['success' => true]));
}

http_response_code(500);
exit(json_encode(['error' => "Erreur d'envoi"]));

// ===== Functions =====

function sendEmail(string $apiKey, string $from, string $fromName, string $to, string $subject, string $html, string $replyTo): bool
{
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'from'     => "{$fromName} <{$from}>",
            'to'       => [$to],
            'subject'  => $subject,
            'html'     => $html,
            'reply_to' => $replyTo,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("Resend error ({$code}): {$response} {$err}");
        return false;
    }
    return true;
}

function formatDateFr(string $date): string
{
    $days   = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
    $months = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $ts     = strtotime($date);
    return $days[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

function buildNotificationHtml(Closure $h, string $name, string $email, string $phone, string $guests, string $date, string $time, string $message): string
{
    $rows = <<<HTML
    <tr><td style="padding:8px 12px;color:#888;">Nom</td><td style="padding:8px 12px;font-weight:600;">{$h($name)}</td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Email</td><td style="padding:8px 12px;"><a href="mailto:{$h($email)}" style="color:#c8a96e;">{$h($email)}</a></td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Téléphone</td><td style="padding:8px 12px;"><a href="tel:{$h($phone)}" style="color:#c8a96e;">{$h($phone)}</a></td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Convives</td><td style="padding:8px 12px;">{$h($guests)}</td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Date</td><td style="padding:8px 12px;font-weight:600;">{$h($date)}</td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Heure</td><td style="padding:8px 12px;font-weight:600;">{$h($time)}</td></tr>
    HTML;

    if ($message !== '') {
        $rows .= <<<HTML
        <tr><td style="padding:8px 12px;color:#888;vertical-align:top;">Message</td><td style="padding:8px 12px;">{$h($message)}</td></tr>
        HTML;
    }

    return <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:40px 16px;font-family:-apple-system,sans-serif;background:#f5f0eb;color:#1a1a1a;">
        <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">
            <div style="background:#1a1a1a;padding:24px 32px;">
                <h1 style="margin:0;font-size:18px;color:#c8a96e;font-weight:600;">Nouvelle réservation</h1>
            </div>
            <div style="padding:24px 32px;">
                <table style="width:100%;border-collapse:collapse;">{$rows}</table>
            </div>
        </div>
    </body>
    </html>
    HTML;
}

function buildConfirmationHtml(Closure $h, string $name, string $guests, string $date, string $time): string
{
    return <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:40px 16px;font-family:-apple-system,sans-serif;background:#f5f0eb;color:#1a1a1a;">
        <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">
            <div style="background:#1a1a1a;padding:24px 32px;">
                <h1 style="margin:0;font-size:18px;color:#c8a96e;font-weight:600;">Le Trémouille</h1>
            </div>
            <div style="padding:32px;">
                <p style="font-size:16px;margin:0 0 16px;">Bonjour {$h($name)},</p>
                <p style="margin:0 0 16px;">Nous avons bien reçu votre demande de réservation :</p>
                <div style="background:#f5f0eb;padding:16px 20px;border-radius:6px;margin:0 0 20px;">
                    <strong>{$h($guests)} personne(s)</strong><br>
                    {$h($date)} à <strong>{$h($time)}</strong>
                </div>
                <p style="margin:0 0 24px;">Sans nouvelle de notre part dans les 2 heures, votre réservation est confirmée.</p>
                <p style="margin:0;font-size:14px;color:#666;line-height:1.6;">
                    À très bientôt !<br>
                    <strong>L'équipe du Trémouille</strong><br>
                    7 Boulevard de la Trémouille, 21000 Dijon<br>
                    03 73 73 84 65
                </p>
            </div>
        </div>
    </body>
    </html>
    HTML;
}
