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
if (!in_array($uri, ['/reservation', '/contact'], true)) {
    http_response_code(404);
    exit(json_encode(['error' => 'Not found']));
}

// --- Parse input (FormData or JSON) ---
const MAX_BODY_BYTES = 32768;

$declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > MAX_BODY_BYTES) {
    http_response_code(413);
    exit(json_encode(['error' => 'Requête trop volumineuse']));
}

$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($ct, 'application/json')) {
    // Lecture bornée avant décodage (couvre aussi un corps sans Content-Length fiable)
    $raw = (string)file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if (strlen($raw) > MAX_BODY_BYTES) {
        http_response_code(413);
        exit(json_encode(['error' => 'Requête trop volumineuse']));
    }
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Corps JSON invalide']));
    }
} else {
    $input = $_POST;
}

// Chaque valeur doit être une chaîne ou un nombre (jamais un tableau / objet / booléen)
foreach ($input as $k => $v) {
    if (is_int($v) || is_float($v)) {
        $input[$k] = (string)$v;
    } elseif (!is_string($v)) {
        if ($k === '_honey') { $input[$k] = '1'; continue; }
        http_response_code(400);
        exit(json_encode(['error' => 'Champ invalide']));
    }
}

// Honeypot
if (!empty($input['_honey'])) {
    exit(json_encode(['success' => true]));
}

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

// --- Validation helpers ---
// Texte court : une ligne, sans caractère de contrôle (empêche l'injection d'en-têtes de courriel)
$shortOk = fn(string $s, int $min, int $max): bool =>
    mb_strlen($s) >= $min && mb_strlen($s) <= $max && !preg_match('/[\x00-\x1F\x7F]/', $s);
// Texte libre : retours à la ligne permis, autres caractères de contrôle refusés
$longOk = fn(string $s, int $max): bool =>
    mb_strlen($s) <= $max && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s);
$phoneOk = fn(string $s): bool => (bool)preg_match('/^\+?[0-9 .()\/-]{6,25}$/', $s);
// Date AAAA-MM-JJ réelle, comprise entre hier et dans 18 mois
$dateOk = function (string $s): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return false;
    }
    return $s >= date('Y-m-d', strtotime('-1 day')) && $s <= date('Y-m-d', strtotime('+18 months'));
};

// --- Escape for HTML emails ---
$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// =============================================================
// /contact — demande traiteur / événement / privatisation
// =============================================================
const PRESTATIONS = ['Menus sur mesure', 'Apéritif dînatoire', 'Plateaux repas', 'Privatisation', 'Autre'];
const COUVERTS = ['1', '2', '3', '4', '5', '6', '7+'];
const CRENEAUX = ['12:00', '12:30', '13:00', '13:30', '14:00', '19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '22:00'];

if ($uri === '/contact') {
    $name       = trim($input['name'] ?? '');
    $email      = trim($input['email'] ?? '');
    $phone      = trim($input['phone'] ?? '');
    $prestation = trim($input['prestation'] ?? '');
    $date       = trim($input['date'] ?? '');
    $guests     = trim($input['guests'] ?? '');
    $message    = trim($input['message'] ?? '');

    $errors = [];
    if (!$shortOk($name, 1, 80)) $errors[] = 'name';
    if (!$shortOk($email, 3, 254) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
    if (!$phoneOk($phone)) $errors[] = 'phone';
    if (!in_array($prestation, PRESTATIONS, true)) $errors[] = 'prestation';
    if ($date !== '' && !$dateOk($date)) $errors[] = 'date';
    if ($guests !== '' && !(preg_match('/^\d{1,3}$/', $guests) && (int)$guests >= 1)) $errors[] = 'guests';
    if ($message === '' || !$longOk($message, 2000)) $errors[] = 'message';

    if ($errors) {
        http_response_code(422);
        exit(json_encode(['error' => 'Champs invalides', 'fields' => $errors]));
    }

    $dateFormatted = $date !== '' ? formatDateFr($date) : '';

    $ok1 = sendEmail($resendKey, $fromEmail, $fromName, $toEmail,
        "Demande {$prestation} — {$name}",
        buildContactNotificationHtml($h, $name, $email, $phone, $prestation, $dateFormatted, $guests, $message),
        $email
    );

    // Confirmation au client seulement si la notification au restaurant est partie
    $ok2 = $ok1 && sendEmail($resendKey, $fromEmail, $fromName, $email,
        'Votre demande au Trémouille',
        buildContactConfirmationHtml($h, $name, $prestation),
        $toEmail
    );

    if ($ok1 && $ok2) {
        exit(json_encode(['success' => true]));
    }

    http_response_code(500);
    exit(json_encode(['error' => "Erreur d'envoi"]));
}

// =============================================================
// /reservation
// =============================================================

// --- Validate ---
$name    = trim($input['name'] ?? '');
$email   = trim($input['email'] ?? '');
$phone   = trim($input['phone'] ?? '');
$guests  = trim($input['guests'] ?? '');
$date    = trim($input['date'] ?? '');
$time    = trim($input['time'] ?? '');
$message = trim($input['message'] ?? '');

$errors = [];
if (!$shortOk($name, 1, 80)) $errors[] = 'name';
if (!$shortOk($email, 3, 254) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (!$phoneOk($phone)) $errors[] = 'phone';
if (!in_array($guests, COUVERTS, true)) $errors[] = 'guests';
if (!$dateOk($date)) $errors[] = 'date';
if (!in_array($time, CRENEAUX, true)) $errors[] = 'time';
if (!$longOk($message, 1000)) $errors[] = 'message';

if ($errors) {
    http_response_code(422);
    exit(json_encode(['error' => 'Champs invalides', 'fields' => $errors]));
}

// --- Soirées complètes (même liste que soireesCompletes dans script.js) ---
const SOIREES_COMPLETES = ['2026-10-10', '2026-10-16', '2026-10-22', '2026-10-23', '2026-11-20', '2026-11-27'];
if (in_array($date, SOIREES_COMPLETES, true) && $time >= '19:00') {
    http_response_code(409);
    exit(json_encode(['error' => 'Soirée complète']));
}

// --- Format date in French ---
$dateFormatted = formatDateFr($date);

// --- Send notification to restaurateur ---
$ok1 = sendEmail($resendKey, $fromEmail, $fromName, $toEmail,
    "Réservation — {$name}, {$guests} pers. le {$dateFormatted} à {$time}",
    buildNotificationHtml($h, $name, $email, $phone, $guests, $dateFormatted, $time, $message),
    $email
);

// --- Send confirmation to client ---
$ok2 = $ok1 && sendEmail($resendKey, $fromEmail, $fromName, $email,
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
    // Défense en profondeur : aucun retour chariot dans ce qui devient un en-tête
    foreach ([$from, $fromName, $to, $subject, $replyTo] as $v) {
        if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
            error_log('Valeur d\'en-tête refusée');
            return false;
        }
    }
    // Mode essai (tests locaux uniquement) : aucun appel réseau
    if (getenv('TREMOUILLE_MAIL_DRYRUN') === '1') {
        return true;
    }
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
    $ts     = strtotime($date) ?: 0;
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

function buildContactNotificationHtml(Closure $h, string $name, string $email, string $phone, string $prestation, string $date, string $guests, string $message): string
{
    $rows = <<<HTML
    <tr><td style="padding:8px 12px;color:#888;">Nom</td><td style="padding:8px 12px;font-weight:600;">{$h($name)}</td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Email</td><td style="padding:8px 12px;"><a href="mailto:{$h($email)}" style="color:#c8a96e;">{$h($email)}</a></td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Téléphone</td><td style="padding:8px 12px;"><a href="tel:{$h($phone)}" style="color:#c8a96e;">{$h($phone)}</a></td></tr>
    <tr><td style="padding:8px 12px;color:#888;">Prestation</td><td style="padding:8px 12px;font-weight:600;">{$h($prestation)}</td></tr>
    HTML;

    if ($date !== '') {
        $rows .= <<<HTML
        <tr><td style="padding:8px 12px;color:#888;">Date souhaitée</td><td style="padding:8px 12px;">{$h($date)}</td></tr>
        HTML;
    }

    if ($guests !== '') {
        $rows .= <<<HTML
        <tr><td style="padding:8px 12px;color:#888;">Convives</td><td style="padding:8px 12px;">{$h($guests)}</td></tr>
        HTML;
    }

    $rows .= <<<HTML
    <tr><td style="padding:8px 12px;color:#888;vertical-align:top;">Projet</td><td style="padding:8px 12px;white-space:pre-line;">{$h($message)}</td></tr>
    HTML;

    return <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:40px 16px;font-family:-apple-system,sans-serif;background:#f5f0eb;color:#1a1a1a;">
        <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">
            <div style="background:#1a1a1a;padding:24px 32px;">
                <h1 style="margin:0;font-size:18px;color:#c8a96e;font-weight:600;">Nouvelle demande — {$h($prestation)}</h1>
            </div>
            <div style="padding:24px 32px;">
                <table style="width:100%;border-collapse:collapse;">{$rows}</table>
            </div>
        </div>
    </body>
    </html>
    HTML;
}

function buildContactConfirmationHtml(Closure $h, string $name, string $prestation): string
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
                <p style="margin:0 0 16px;">Nous avons bien reçu votre demande concernant :</p>
                <div style="background:#f5f0eb;padding:16px 20px;border-radius:6px;margin:0 0 20px;">
                    <strong>{$h($prestation)}</strong>
                </div>
                <p style="margin:0 0 24px;">Nous l'étudions et revenons vers vous très rapidement avec une proposition sur mesure.</p>
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
