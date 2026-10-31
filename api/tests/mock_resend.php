<?php
// Faux Resend pour les tests : enregistre chaque envoi, n'envoie RIEN.
// Si le fichier MOCK_FAIL contient l'adresse destinataire, l'envoi échoue (500).
declare(strict_types=1);

$payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
$to = $payload['to'][0] ?? '';
$failFile = getenv('MOCK_FAIL');

header('Content-Type: application/json');
if ($failFile && is_file($failFile) && trim((string)file_get_contents($failFile)) === $to) {
    http_response_code(500);
    echo '{"error":"simulated"}';
    return;
}

file_put_contents((string)getenv('MOCK_LOG'), json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
echo '{"id":"mock"}';
