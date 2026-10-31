<?php
/**
 * Tests de l'API de réservation, contre un serveur `php -S` local.
 * Resend est SIMULÉ (tests/mock_resend.php) : aucun courriel n'est envoyé.
 *
 *   php api/tests/api_test.php            # API = api/index.php
 *   API_FILE=/chemin/index.php php api/tests/api_test.php
 *
 * Code de sortie 0 si tout passe ; la liste des tests en échec est imprimée.
 */

declare(strict_types=1);

$root    = dirname(__DIR__);
$apiFile = getenv('API_FILE') ?: $root . '/index.php';
$work    = sys_get_temp_dir() . '/tremouille-test-' . getmypid();
@mkdir($work, 0700, true);

$mockLog  = "$work/mails.jsonl";
$mockFail = "$work/fail_to";
$rlFile   = "$work/ratelimit.json";
$resto    = 'resto@example.test';

$mockPort = freePort();
$apiPort  = freePort();
$baseEnv  = ['PATH' => getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '4'];

$mock = startServer($mockPort, __DIR__ . '/mock_resend.php', $baseEnv + ['MOCK_LOG' => $mockLog, 'MOCK_FAIL' => $mockFail]);
$api  = startServer($apiPort, $apiFile, $baseEnv + [
    'RESEND_API_KEY'      => 're_test',
    'RESEND_API_URL'      => "http://127.0.0.1:$mockPort/emails",
    'TREMOUILLE_TO_EMAIL' => $resto,
    'RATE_LIMIT_FILE'     => $rlFile,
], iniArgs($root . '/php.ini')); // réglages de l'image, puis affichage forcé : le code doit se protéger seul

register_shutdown_function(function () use ($mock, $api, $work) {
    foreach ([$mock, $api] as $p) { proc_terminate($p); }
    array_map('unlink', glob("$work/*") ?: []);
    @rmdir($work);
});

$API = "http://127.0.0.1:$apiPort";
$tz  = new DateTimeZone('Europe/Paris');
$tomorrow = (new DateTimeImmutable('tomorrow', $tz))->format('Y-m-d');

function resa(array $over = []): array
{
    global $tomorrow;
    static $n = 0;
    $n++;
    return array_merge([
        'name'    => 'Client Témoin',
        'email'   => "client$n@example.test",
        'phone'   => '06 12 34 56 78',
        'guests'  => '2',
        'date'    => $tomorrow,
        'time'    => '12:30',
        'message' => 'Allergie aux noix',
    ], $over);
}

function devis(array $over = []): array
{
    static $n = 0;
    $n++;
    return array_merge([
        'name'       => 'Client Devis',
        'email'      => "devis$n@example.test",
        'phone'      => '0612345678',
        'prestation' => 'Privatisation',
        'guests'     => '30',
        'message'    => 'Anniversaire au calme',
    ], $over);
}

$ipSeq = 0;
function freshIp(): string { global $ipSeq; $ipSeq++; return '198.51.100.' . $ipSeq; }

// ---------------------------------------------------------------- cas
$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[$name] = $ok;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  -- $detail") . "\n";
}
function resetState(): void
{
    global $rlFile, $mockLog, $mockFail;
    @unlink($rlFile); @unlink($mockLog); @unlink($mockFail);
}
function mails(): array
{
    global $mockLog;
    if (!is_file($mockLog)) return [];
    return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($mockLog))));
}
function noTrace(array $r): bool
{
    return !preg_match('~(Stack trace|Fatal error|Warning:|Deprecated|Notice:|TypeError|\.php|/app/)~', $r['body']);
}

// 1. Cas nominal + confirmation sans texte libre
resetState();
$r = post("$API/reservation", resa(['name' => 'NOMTEMOIN', 'message' => 'MESSAGETEMOIN http://x.test']), freshIp());
$m = mails();
check('reservation_valide_200', $r['code'] === 200, $r['code'] . ' ' . $r['body']);
check('deux_courriels', count($m) === 2, (string)count($m));
$conf  = array_values(array_filter($m, fn($x) => ($x['to'][0] ?? '') !== $resto));
$notif = array_values(array_filter($m, fn($x) => ($x['to'][0] ?? '') === $resto));
$confText = $conf ? json_encode($conf[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
check('confirmation_sans_texte_libre', $conf !== [] && !str_contains($confText, 'NOMTEMOIN') && !str_contains($confText, 'MESSAGETEMOIN') && !str_contains($confText, 'x.test'), $confText);
check('confirmation_avec_valeurs_fermees', $conf !== [] && str_contains($confText, '12:30') && str_contains($confText, '2 personne'), $confText);
check('notification_complete_au_restaurant', $notif !== [] && str_contains(json_encode($notif[0], JSON_UNESCAPED_UNICODE), 'NOMTEMOIN') && str_contains(json_encode($notif[0], JSON_UNESCAPED_UNICODE), 'MESSAGETEMOIN'));
check('notification_avant_confirmation', count($m) === 2 && ($m[0]['to'][0] ?? '') === $resto);

// 2. Bornes et formats -> 400
resetState();
$bad = [
    'nom_trop_long'      => ['name' => str_repeat('a', 81)],
    'nom_avec_url'       => ['name' => 'Gagnez sur https://x.test'],
    'nom_retour_ligne'   => ['name' => "Jean\r\nBcc: x"],
    'nom_vide'           => ['name' => ''],
    'email_invalide'     => ['email' => 'pas-un-courriel'],
    'email_trop_long'    => ['email' => str_repeat('a', 250) . '@example.test'],
    'telephone_invalide' => ['phone' => 'appelez-moi'],
    'telephone_long'     => ['phone' => '06 12 34 56 78 ' . str_repeat('9', 30)],
    'convives_hors_liste'=> ['guests' => '99'],
    'heure_hors_liste'   => ['time' => '23:59'],
    'message_trop_long'  => ['message' => str_repeat('m', 2001)],
    'date_invalide'      => ['date' => '2026-13-45'],
    'date_30_fevrier'    => ['date' => '2027-02-30'],
    'date_passee'        => ['date' => (new DateTimeImmutable('-3 days', $tz))->format('Y-m-d')],
    'date_trop_lointaine'=> ['date' => (new DateTimeImmutable('+13 months', $tz))->format('Y-m-d')],
    'date_format'        => ['date' => '31/12/2026'],
];
foreach ($bad as $case => $over) {
    $r = post("$API/reservation", resa($over), freshIp());
    check("borne_$case", $r['code'] === 400 && noTrace($r), $r['code'] . ' ' . $r['body']);
}
check('bornes_aucun_courriel', mails() === [], (string)count(mails()));
$r = post("$API/reservation", resa(['message' => str_repeat('m', 2000)]), freshIp());
check('message_2000_accepte', $r['code'] === 200, $r['code'] . ' ' . $r['body']);

// 3. Corps inattendus -> 400 sans trace
resetState();
$raw = [
    'json_tableau_dans_champ' => json_encode(resa(['name' => [1]])),
    'json_scalaire'           => '"abc"',
    'json_liste'              => '[1,2,3]',
    'json_malforme'           => '{"name": ',
    'json_profond'            => json_encode(['name' => ['a' => ['b' => ['c' => ['d' => 1]]]]]),
];
foreach ($raw as $case => $body) {
    $r = postRaw("$API/reservation", $body, 'application/json', freshIp());
    check("corps_$case", $r['code'] === 400 && noTrace($r) && is_array(json_decode($r['body'], true)), $r['code'] . ' ' . $r['body']);
}
$r = postRaw("$API/reservation", 'x', 'text/plain', freshIp());
check('corps_type_inconnu', $r['code'] === 400 && noTrace($r), $r['code'] . ' ' . $r['body']);
$r = post("$API/reservation", resa(['message' => str_repeat('m', 40000)]), freshIp());
check('corps_trop_gros', $r['code'] === 400 && str_contains($r['body'], 'volumineuse') && noTrace($r), $r['code'] . ' ' . $r['body']);
$r = postForm("$API/reservation", resa(['name' => ['x']]), freshIp());
check('formulaire_tableau', $r['code'] === 400 && noTrace($r), $r['code'] . ' ' . $r['body']);

// 4. Limitation par IP : 5 par heure
resetState();
$ip = freshIp();
$codes = [];
for ($i = 0; $i < 6; $i++) { $codes[] = post("$API/reservation", resa(), $ip)['code']; }
check('ip_5_acceptees_puis_429', $codes === [200, 200, 200, 200, 200, 429], implode(',', $codes));
$r = post("$API/reservation", resa(), $ip);
check('ip_429_message_clair', $r['code'] === 429 && str_contains($r['body'], 'Trop de demandes') && ($r['headers']['retry-after'] ?? 0) > 0, $r['code'] . ' ' . $r['body']);
check('ip_429_sans_courriel', count(mails()) === 10, (string)count(mails()));
$r = post("$API/contact", devis(), $ip);
check('ip_limite_commune_au_devis', $r['code'] === 429, (string)$r['code']);
$r = post("$API/reservation", resa(), freshIp());
check('ip_autre_non_bloquee', $r['code'] === 200, (string)$r['code']);

// 5. Limitation par destinataire : 3 par jour
resetState();
$codes = [];
for ($i = 0; $i < 4; $i++) { $codes[] = post("$API/reservation", resa(['email' => 'Cible@Example.test']), freshIp())['code']; }
check('destinataire_3_acceptees_puis_429', $codes === [200, 200, 200, 429], implode(',', $codes));
$r = post("$API/contact", devis(['email' => 'cible@example.test']), freshIp());
check('destinataire_casse_et_route_ignorees', $r['code'] === 429, (string)$r['code']);

// 6. Concurrence : 30 requêtes simultanées depuis une IP -> exactement 5 acceptées
resetState();
$ip = freshIp();
$codes = parallel("$API/reservation", array_map(fn() => resa(), range(1, 30)), $ip);
sort($codes);
check('concurrence_exactement_5', array_count_values($codes) === [200 => 5, 429 => 25], implode(',', $codes));

// 7. Échec de la notification -> 500 et AUCUNE confirmation
resetState();
file_put_contents($mockFail, $resto);
$r = post("$API/reservation", resa(), freshIp());
check('notification_en_echec_pas_de_confirmation', $r['code'] === 500 && mails() === [] && noTrace($r), $r['code'] . ' ' . count(mails()));

// 8. Devis
resetState();
$r = post("$API/contact", devis(['name' => 'NOMDEVIS', 'message' => 'PROJETDEVIS', 'date' => $tomorrow]), freshIp());
$m = mails();
$conf = array_values(array_filter($m, fn($x) => ($x['to'][0] ?? '') !== $resto));
$confText = $conf ? json_encode($conf[0], JSON_UNESCAPED_UNICODE) : '';
check('devis_valide_200', $r['code'] === 200 && count($m) === 2, $r['code'] . ' ' . $r['body']);
check('devis_confirmation_sans_texte_libre', $conf !== [] && !str_contains($confText, 'NOMDEVIS') && !str_contains($confText, 'PROJETDEVIS') && str_contains($confText, 'Privatisation'), $confText);
foreach ([
    'prestation_hors_liste' => ['prestation' => 'Achetez sur https://x.test'],
    'message_vide'          => ['message' => ''],
    'message_trop_long'     => ['message' => str_repeat('m', 5001)],
    'date_invalide'         => ['date' => '2026-02-31'],
    'convives_zero'         => ['guests' => '0'],
    'nom_trop_long'         => ['name' => str_repeat('a', 81)],
] as $case => $over) {
    $r = post("$API/contact", devis($over), freshIp());
    check("devis_$case", $r['code'] === 400 && noTrace($r), $r['code'] . ' ' . $r['body']);
}

// 9. Divers
$r = request('GET', "$API/reservation");
check('get_405', $r['code'] === 405);
$r = post("$API/autre", resa(), freshIp());
check('route_inconnue_404', $r['code'] === 404);
resetState();
$r = post("$API/reservation", resa(['_honey' => 'x']), freshIp());
check('pot_de_miel_sans_courriel', $r['code'] === 200 && mails() === []);

$failed = array_keys(array_filter($results, fn($ok) => !$ok));
echo "\n" . (count($results) - count($failed)) . '/' . count($results) . " tests OK\n";
if ($failed) { echo 'ECHECS: ' . implode(' ', $failed) . "\n"; exit(1); }
exit(0);

// ---------------------------------------------------------------- outils
function iniArgs(string $ini): array
{
    $args = [];
    foreach (parse_ini_file($ini, false, INI_SCANNER_RAW) as $k => $v) {
        if ($k === 'error_log') continue;
        array_push($args, '-d', "$k=$v");
    }
    return array_merge($args, ['-d', 'display_errors=1']);
}

function freePort(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
}

function startServer(int $port, string $router, array $env, array $extra = [])
{
    $cmd = array_merge([PHP_BINARY], $extra, ['-S', "127.0.0.1:$port", $router]);
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname($router), $env);
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
        if ($c) { fclose($c); return $p; }
        usleep(50000);
    }
    fwrite(STDERR, "serveur $router injoignable\n");
    exit(2);
}

function request(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['code' => $code, 'body' => (string)$out, 'headers' => $respHeaders];
}

function post(string $url, array $data, string $ip): array
{
    return postRaw($url, json_encode($data), 'application/json', $ip);
}

function postRaw(string $url, string $body, string $ct, string $ip): array
{
    return request('POST', $url, $body, ["Content-Type: $ct", "X-Real-IP: $ip"]);
}

function postForm(string $url, array $data, string $ip): array
{
    return request('POST', $url, http_build_query($data), ['Content-Type: application/x-www-form-urlencoded', "X-Real-IP: $ip"]);
}

function parallel(string $url, array $payloads, string $ip): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($payloads as $p) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Real-IP: $ip"], CURLOPT_TIMEOUT => 20,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running && $st === CURLM_OK);
    $codes = array_map(fn($ch) => curl_getinfo($ch, CURLINFO_HTTP_CODE), $handles);
    foreach ($handles as $ch) { curl_multi_remove_handle($mh, $ch); }
    curl_multi_close($mh);
    return $codes;
}
