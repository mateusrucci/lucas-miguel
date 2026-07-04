<?php
/**
 * submit.php — recebe o lead do navegador e encaminha 100% server-side para:
 *   1) a planilha da cidade (Apps Script /exec)
 *   2) o Agendor (webhook do Make)
 *   3) a Meta Conversions API (evento Lead server-side)
 * UTMs/fbclid capturados no navegador (campos ocultos) e repassados aqui.
 * URLs de webhook e token da Meta ficam SÓ aqui no servidor — nunca no HTML do cliente.
 */

header('Content-Type: application/json; charset=utf-8');

// ===== Config por cidade (server-side) =====
$CONFIG = [
    'indaiatuba' => [
        'apps' => 'https://script.google.com/macros/s/AKfycbxKYnBrSOJTASSKQ_6tBeoTEjwWXv7rr4_6Ed67j2tUTQRLX6niTJbmvtXKsW4Yvi2r/exec',
        'make' => 'https://hook.us2.make.com/q5ydh430k7wow5w74zmr7277gwd3u9iy?evento=Indaiatuba&Data=22-07-2026',
    ],
    'campinas' => [
        'apps' => 'https://script.google.com/macros/s/AKfycbyvTQyoZIvPy_RMFuhXA-Rky0E94eabVjjNn52RKLUWnqLBAELe8E0CnTwORqqndMI_/exec',
        'make' => 'https://hook.us2.make.com/tj1cbdwffuguui6oh2ra7h9hwlb6x415?evento=Campinas&Data=23-07-2026',
    ],
    'aracatuba' => [
        'apps' => 'https://script.google.com/macros/s/AKfycbxZYB8FHhererJQyyrS5YlUSviFVaajvKRk57OpiSyZ91X-uUdprQn3Oa-w1SyNYsTs/exec',
        'make' => 'https://hook.us2.make.com/71umppo169doxcjl4q4xpex9pj2bwr4z?evento=Aracatuba&Data=28-07-2026',
    ],
    'americana' => [
        'apps' => 'https://script.google.com/macros/s/AKfycbyEeM6tHaQMmjrzxCCWur6brWv2eQyXr-t0hDqRNEZAfwZod-zuTRvGGN_oOxC3834VjA/exec',
        'make' => 'https://hook.us2.make.com/0886ybbbjrrjuyc5c1iy2f2xctnj1iig?evento=Americana&Data=21-07-2026',
    ],
    // TODO: substituir URLs de Apps Script + Make quando disponíveis para Marília e Londrina.
    'marilia' => [
        'apps' => 'https://example.com/todo-marilia-apps-script',
        'make' => 'https://example.com/todo-marilia-make?evento=Marilia&Data=19-08-2026',
    ],
    'londrina' => [
        'apps' => 'https://example.com/todo-londrina-apps-script',
        'make' => 'https://example.com/todo-londrina-make?evento=Londrina&Data=20-08-2026',
    ],
];

// ===== Meta Conversions API (server-side) — domínio lp.liberdadeoperacional.com.br =====
const FB_PIXEL_ID     = '759367142080644';
const FB_ACCESS_TOKEN = 'EAAJJHBDjLPMBR6QziVtpAZBNgWLLLWVPZBVsheT3MHQTYxC5Nos9ZC2AKskavTsC4U9CIJfZC0eLaBcwVuKd99bAjsTLhaa7ULzkLa0Jh07nCZCe4Hh32xR3Bh4qWBMjoMBQqtJ2tyMr0tKUWVYHcZCtdjpDQQqs8AlmQrZCEGwckq8XYVQdmHrcyZARv3ZAagkbIwAZDZD';
const FB_API_VERSION  = 'v21.0';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$evento = isset($_POST['evento']) ? strtolower(trim($_POST['evento'])) : '';
if (!isset($CONFIG[$evento])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'evento']);
    exit;
}
$cfg = $CONFIG[$evento];

// ===== Payload (mesmas chaves das colunas da planilha) =====
function v($k) { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : ''; }

$payload = [
    'Nome'          => v('nome'),
    'Email'         => v('email'),
    'WhatsApp'      => v('whatsapp'),
    'Cidade'        => v('cidade'),
    'Colaboradores' => v('colaboradores'),
];
foreach (['utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term', 'gclid', 'fbclid'] as $t) {
    $payload[$t] = v($t);
}
if (v('dor') !== '') { $payload['dor'] = v('dor'); } // quiz: marca a dor (resultado) na planilha

if ($payload['Nome'] === '' || $payload['WhatsApp'] === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'campos']);
    exit;
}

// ===== IP real do cliente (atrás do Cloudflare) =====
function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = $_SERVER[$k];
            if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
            return $ip;
        }
    }
    return '';
}

// ===== Monta o evento "Lead" da Meta CAPI (PII com hash SHA-256) =====
function meta_event_body($evento, $payload) {
    $h = function ($v) { $v = strtolower(trim($v)); return $v === '' ? null : hash('sha256', $v); };

    $digits = preg_replace('/\D+/', '', $payload['WhatsApp']);          // telefone só dígitos
    if ($digits !== '' && strlen($digits) <= 11) { $digits = '55' . $digits; } // DDI Brasil

    $nome  = trim($payload['Nome']);
    $parts = preg_split('/\s+/', $nome);
    $fn    = $parts[0] ?? '';
    $ln    = (count($parts) > 1) ? end($parts) : '';

    $ud = [];
    if (!empty($payload['Email']))     { $ud['em'] = [$h($payload['Email'])]; }
    if ($digits !== '')            { $ud['ph'] = [hash('sha256', $digits)]; }
    if ($fn !== '')                { $ud['fn'] = [$h($fn)]; }
    if ($ln !== '')                { $ud['ln'] = [$h($ln)]; }
    if ($payload['Cidade'] !== '') { $ud['ct'] = [$h(preg_replace('/\s+/', '', $payload['Cidade']))]; }
    if (!empty($payload['fbclid'])) { $ud['fbc'] = 'fb.1.' . (time() * 1000) . '.' . $payload['fbclid']; }
    $ip = client_ip();
    if ($ip !== '') { $ud['client_ip_address'] = $ip; }
    if (!empty($_SERVER['HTTP_USER_AGENT'])) { $ud['client_user_agent'] = $_SERVER['HTTP_USER_AGENT']; }

    $eventId = v('lead_event_id');
    if ($eventId === '') { $eventId = v('event_id'); }
    if ($eventId === '') { $eventId = 'lead.' . $evento . '.' . bin2hex(random_bytes(6)); }

    $event = [
        'event_name'    => 'Lead',
        'event_time'    => time(),
        'action_source' => 'website',
        'event_id'      => substr($eventId, 0, 128),
        'user_data'     => $ud,
        'custom_data'   => [
            'content_name' => ucfirst($evento),
            'utm_source'   => $payload['utm_source'],
            'utm_campaign' => $payload['utm_campaign'],
        ],
    ];
    if (!empty($_SERVER['HTTP_REFERER'])) { $event['event_source_url'] = $_SERVER['HTTP_REFERER']; }

    return json_encode(['data' => [$event]], JSON_UNESCAPED_UNICODE);
}

// ===== Responde JÁ (cadastro instantâneo) e encaminha em segundo plano =====
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();                 // libera o navegador na hora; o forward continua abaixo
} else {
    ignore_user_abort(true);
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}

// ===== Encaminha em paralelo: planilha + Make + Meta CAPI =====
$makeUrl  = $cfg['make'] . '&' . http_build_query($payload);
$metaUrl  = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . urlencode(FB_ACCESS_TOKEN);
$metaBody = meta_event_body($evento, $payload);

$chApps = curl_init($cfg['apps']);
curl_setopt_array($chApps, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 15,
]);

$chMake = curl_init($makeUrl);
curl_setopt_array($chMake, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 15,
]);

$chMeta = curl_init($metaUrl);
curl_setopt_array($chMeta, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $metaBody,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 15,
]);

$mh = curl_multi_init();
foreach ([$chApps, $chMake, $chMeta] as $ch) { curl_multi_add_handle($mh, $ch); }
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) { curl_multi_select($mh, 1.0); }
} while ($running && $status === CURLM_OK);

foreach ([$chApps, $chMake, $chMeta] as $ch) {
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
// (a resposta {ok:true} já foi enviada antes do forward — cadastro instantâneo)
