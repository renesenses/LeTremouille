<?php

declare(strict_types=1);

// --- Erreurs : jamais affichées au client, toujours journalisées ---
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$allowedOrigin = getenv('CORS_ORIGIN') ?: 'https://letremouille.fr';

header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Erreur d'entrée : rendue en 400, avec un message clair et sans trace. */
final class InvalidInput extends RuntimeException {}

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof InvalidInput) {
        respond(400, ['error' => $e->getMessage()]);
    }
    error_log('Unhandled ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(500, ['error' => 'Erreur interne']);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method not allowed']);
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!in_array($uri, ['/reservation', '/contact'], true)) {
    respond(404, ['error' => 'Not found']);
}

// --- Limites ---
const MAX_BODY_BYTES     = 32768;
const NAME_MAX           = 80;
const EMAIL_MAX          = 254;
const PHONE_MAX          = 25;
const RESA_MESSAGE_MAX   = 2000;
const CONTACT_MESSAGE_MAX = 5000;
const PHONE_PATTERN      = '/^(?:(?:\+33|0033|0)\s*[1-9])(?:[\s.-]*\d{2}){4}$/';
const RESA_GUESTS        = ['1', '2', '3', '4', '5', '6', '7+'];
const RESA_TIMES         = ['12:00', '12:30', '13:00', '13:30', '14:00', '19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '22:00'];
const PRESTATIONS        = ['Menus sur mesure', 'Apéritif dînatoire', 'Plateaux repas', 'Privatisation', 'Autre'];
const TIMEZONE           = 'Europe/Paris';

// --- Parse input (FormData or JSON) ---
$input = readInput();

// Honeypot
if (($input['_honey'] ?? '') !== '') {
    respond(200, ['success' => true]);
}

// --- Config ---
$resendKey = getenv('RESEND_API_KEY');
$toEmail   = getenv('TREMOUILLE_TO_EMAIL') ?: 'contact@letremouille.com';
$fromEmail = getenv('TREMOUILLE_FROM_EMAIL') ?: 'noreply@mozaiklabs.fr';
$fromName  = 'Le Trémouille';

if (!$resendKey) {
    error_log('RESEND_API_KEY not configured');
    respond(500, ['error' => 'Configuration serveur manquante']);
}

// --- Escape for HTML emails ---
$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// =============================================================
// /contact — demande traiteur / événement / privatisation
// =============================================================
if ($uri === '/contact') {
    $name       = field($input, 'name');
    $email      = field($input, 'email');
    $phone      = field($input, 'phone');
    $prestation = field($input, 'prestation');
    $date       = field($input, 'date');
    $guests     = field($input, 'guests');
    $message    = field($input, 'message', true);

    $errors = [];
    if (!validName($name))   $errors[] = 'name';
    if (!validEmail($email)) $errors[] = 'email';
    if (!validPhone($phone)) $errors[] = 'phone';
    if (!in_array($prestation, PRESTATIONS, true)) $errors[] = 'prestation';
    $dateObj = $date === '' ? null : parseFutureDate($date, 24);
    if ($date !== '' && $dateObj === null) $errors[] = 'date';
    if ($guests !== '' && !preg_match('/^[1-9]\d{0,2}$/', $guests)) $errors[] = 'guests';
    if ($message === '' || mb_strlen($message) > CONTACT_MESSAGE_MAX) $errors[] = 'message';

    if ($errors) {
        respond(400, ['error' => 'Champs invalides', 'fields' => $errors]);
    }

    enforceRateLimit($email);

    $dateFormatted = $dateObj ? formatDateFr($dateObj) : '';

    $ok1 = sendEmail($resendKey, $fromEmail, $fromName, $toEmail,
        "Demande {$prestation} — {$name}",
        buildContactNotificationHtml($h, $name, $email, $phone, $prestation, $dateFormatted, $guests, $message),
        $email
    );
    if (!$ok1) {
        respond(500, ['error' => "Erreur d'envoi"]);
    }

    // Confirmation au demandeur : uniquement des valeurs fermées et validées.
    if (!sendEmail($resendKey, $fromEmail, $fromName, $email,
        'Votre demande au Trémouille',
        buildContactConfirmationHtml($h, $prestation, $dateFormatted, $guests),
        $toEmail
    )) {
        error_log('Contact confirmation not sent (notification delivered)');
    }

    respond(200, ['success' => true]);
}

// =============================================================
// /reservation
// =============================================================

// --- Validate ---
$name    = field($input, 'name');
$email   = field($input, 'email');
$phone   = field($input, 'phone');
$guests  = field($input, 'guests');
$date    = field($input, 'date');
$time    = field($input, 'time');
$message = field($input, 'message', true);

$errors = [];
if (!validName($name))   $errors[] = 'name';
if (!validEmail($email)) $errors[] = 'email';
if (!validPhone($phone)) $errors[] = 'phone';
if (!in_array($guests, RESA_GUESTS, true)) $errors[] = 'guests';
$dateObj = parseFutureDate($date, 12);
if ($dateObj === null) $errors[] = 'date';
if (!in_array($time, RESA_TIMES, true)) $errors[] = 'time';
if (mb_strlen($message) > RESA_MESSAGE_MAX) $errors[] = 'message';

if ($errors) {
    respond(400, ['error' => 'Champs invalides', 'fields' => $errors]);
}

// --- Soirées complètes (même liste que soireesCompletes dans script.js) ---
const SOIREES_COMPLETES = ['2026-10-10', '2026-10-16', '2026-10-22', '2026-10-23', '2026-11-20'];
if (in_array($date, SOIREES_COMPLETES, true) && $time >= '19:00') {
    respond(409, ['error' => 'Soirée complète']);
}

enforceRateLimit($email);

// --- Format date in French ---
$dateFormatted = formatDateFr($dateObj);

// --- Send notification to restaurateur ---
$ok1 = sendEmail($resendKey, $fromEmail, $fromName, $toEmail,
    "Réservation — {$name}, {$guests} pers. le {$dateFormatted} à {$time}",
    buildNotificationHtml($h, $name, $email, $phone, $guests, $dateFormatted, $time, $message),
    $email
);
if (!$ok1) {
    respond(500, ['error' => "Erreur d'envoi"]);
}

// --- Send confirmation to client (valeurs fermées uniquement, aucun texte libre) ---
if (!sendEmail($resendKey, $fromEmail, $fromName, $email,
    'Votre réservation au Trémouille',
    buildConfirmationHtml($h, $guests, $dateFormatted, $time),
    $toEmail
)) {
    error_log('Reservation confirmation not sent (notification delivered)');
}

respond(200, ['success' => true]);

// ===== Functions =====

function respond(int $status, array $body): never
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lit le corps (JSON ou formulaire) ; tout ce qui n'est pas un objet de champs est refusé en 400. */
function readInput(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MAX_BODY_BYTES) {
        throw new InvalidInput('Requête trop volumineuse');
    }

    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'application/json')) {
        $raw = (string)file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
        if (strlen($raw) > MAX_BODY_BYTES) {
            throw new InvalidInput('Requête trop volumineuse');
        }
        $data = json_decode($raw, true, 4);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidInput('Requête invalide : un objet JSON est attendu');
        }
        return $data;
    }

    if (str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded')) {
        return $_POST;
    }

    throw new InvalidInput('Requête invalide : type de contenu non pris en charge');
}

/** Champ texte : absent = '', non textuel = 400, UTF-8 invalide ou caractères de contrôle = 400. */
function field(array $input, string $key, bool $multiline = false): string
{
    if (!array_key_exists($key, $input) || $input[$key] === null) {
        return '';
    }
    $v = $input[$key];
    if (is_int($v)) {
        $v = (string)$v;
    }
    if (!is_string($v)) {
        throw new InvalidInput("Requête invalide : le champ « {$key} » doit être un texte");
    }
    if (strlen($v) > MAX_BODY_BYTES || !mb_check_encoding($v, 'UTF-8')) {
        throw new InvalidInput("Requête invalide : le champ « {$key} » est illisible");
    }
    $v = trim($v);
    $forbidden = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    if (preg_match($forbidden, $v)) {
        throw new InvalidInput("Requête invalide : caractères non autorisés dans « {$key} »");
    }
    return $v;
}

function validName(string $name): bool
{
    return $name !== ''
        && mb_strlen($name) <= NAME_MAX
        && !preg_match('~(https?:|www\.|://)~i', $name);
}

function validEmail(string $email): bool
{
    return strlen($email) <= EMAIL_MAX && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validPhone(string $phone): bool
{
    return strlen($phone) <= PHONE_MAX && preg_match(PHONE_PATTERN, $phone) === 1;
}

/** Date AAAA-MM-JJ réelle, pas dans le passé, au plus à $months mois. */
function parseFutureDate(string $date, int $months): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $tz = new DateTimeZone(TIMEZONE);
    $d  = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    if ($d === false || $d->format('Y-m-d') !== $date) {
        return null;
    }
    $today = new DateTimeImmutable('today', $tz);
    if ($d < $today || $d > $today->modify("+{$months} months")) {
        return null;
    }
    return $d;
}

/** Adresse du client : X-Real-IP n'est cru que s'il vient d'un relais privé (le nginx du VPS). */
function clientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $isPublic = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    $ip = (!$isPublic && filter_var($real, FILTER_VALIDATE_IP) !== false) ? $real : $remote;

    // IPv6 : on compte par /64.
    if (str_contains($ip, ':')) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            return bin2hex(substr($bin, 0, 8)) . '::/64';
        }
    }
    return $ip;
}

/**
 * Limitation de débit, sans dépendance : un fichier JSON verrouillé (flock),
 * partagé par les processus du serveur. Clés hachées (aucune adresse en clair).
 * Rend 429 au-delà d'une limite.
 */
function enforceRateLimit(string $email): void
{
    $rules = [
        ['ip:' . clientIp(),                       envInt('RATE_LIMIT_IP_PER_HOUR', 5),      3600],
        ['to:' . strtolower($email),               envInt('RATE_LIMIT_EMAIL_PER_DAY', 3),    86400],
        ['all',                                    envInt('RATE_LIMIT_GLOBAL_PER_DAY', 300), 86400],
    ];

    $file = getenv('RATE_LIMIT_FILE') ?: sys_get_temp_dir() . '/tremouille-ratelimit.json';
    $fp = fopen($file, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        error_log('Rate limit store unavailable');
        respond(503, ['error' => 'Service momentanément indisponible. Merci de nous appeler au 03 73 73 84 65.']);
    }

    $now   = time();
    $state = json_decode((string)stream_get_contents($fp), true);
    $state = is_array($state) ? $state : [];

    // Purge des horodatages expirés (fenêtre la plus longue : 1 jour).
    foreach ($state as $k => $stamps) {
        $stamps = is_array($stamps) ? array_values(array_filter($stamps, fn($t) => is_int($t) && $t > $now - 86400)) : [];
        if ($stamps) { $state[$k] = $stamps; } else { unset($state[$k]); }
    }

    $retryAfter = 0;
    foreach ($rules as [$key, $limit, $window]) {
        $hk = hash('sha256', $key);
        $recent = array_filter($state[$hk] ?? [], fn($t) => $t > $now - $window);
        if (count($recent) >= $limit) {
            $retryAfter = max($retryAfter, min($recent) + $window - $now);
        }
    }

    if ($retryAfter === 0) {
        foreach ($rules as [$key]) {
            $state[hash('sha256', $key)][] = $now;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        respond(429, ['error' => 'Trop de demandes envoyées. Merci de réessayer plus tard ou de nous appeler au 03 73 73 84 65.']);
    }
}

function envInt(string $name, int $default): int
{
    $v = getenv($name);
    return ($v !== false && ctype_digit($v)) ? (int)$v : $default;
}

function sendEmail(string $apiKey, string $from, string $fromName, string $to, string $subject, string $html, string $replyTo): bool
{
    $ch = curl_init(getenv('RESEND_API_URL') ?: 'https://api.resend.com/emails');
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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);

    if ($code < 200 || $code >= 300) {
        error_log("Resend error ({$code}): {$response} {$err}");
        return false;
    }
    return true;
}

function formatDateFr(DateTimeImmutable $d): string
{
    $days   = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
    $months = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    return $days[(int)$d->format('w')] . ' ' . (int)$d->format('j') . ' ' . $months[(int)$d->format('n')] . ' ' . $d->format('Y');
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

/** Gabarit commun des confirmations : $details ne contient QUE des valeurs fermées déjà échappées. */
function buildConfirmationShell(string $intro, string $details, string $outro): string
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
                <p style="font-size:16px;margin:0 0 16px;">Bonjour,</p>
                <p style="margin:0 0 16px;">{$intro}</p>
                <div style="background:#f5f0eb;padding:16px 20px;border-radius:6px;margin:0 0 20px;">
                    {$details}
                </div>
                <p style="margin:0 0 24px;">{$outro}</p>
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

function buildConfirmationHtml(Closure $h, string $guests, string $date, string $time): string
{
    return buildConfirmationShell(
        'Nous avons bien reçu votre demande de réservation :',
        "<strong>{$h($guests)} personne(s)</strong><br>\n{$h($date)} à <strong>{$h($time)}</strong>",
        'Sans nouvelle de notre part dans les 2 heures, votre réservation est confirmée.'
    );
}

function buildContactConfirmationHtml(Closure $h, string $prestation, string $date, string $guests): string
{
    $details = "<strong>{$h($prestation)}</strong>";
    if ($date !== '') {
        $details .= "<br>\nLe {$h($date)}";
    }
    if ($guests !== '') {
        $details .= "<br>\n{$h($guests)} personne(s)";
    }
    return buildConfirmationShell(
        'Nous avons bien reçu votre demande concernant :',
        $details,
        "Nous l'étudions et revenons vers vous très rapidement avec une proposition sur mesure."
    );
}
