<?php
/**
 * pageview.php — evento PageView server-side (Meta Conversions API),
 * deduplicado com o pixel do navegador pelo mesmo event_id.
 * Recebe event_id + url do navegador; lê _fbp/_fbc dos cookies e IP/UA do request.
 * Mesmo Pixel/token do submit.php (CAPI puro + pixel = rastreio preciso de PageView).
 */

header('Content-Type: application/json; charset=utf-8');

const FB_PIXEL_ID     = '759367142080644';
const FB_ACCESS_TOKEN = 'EAAJJHBDjLPMBR6QziVtpAZBNgWLLLWVPZBVsheT3MHQTYxC5Nos9ZC2AKskavTsC4U9CIJfZC0eLaBcwVuKd99bAjsTLhaa7ULzkLa0Jh07nCZCe4Hh32xR3Bh4qWBMjoMBQqtJ2tyMr0tKUWVYHcZCtdjpDQQqs8AlmQrZCEGwckq8XYVQdmHrcyZARv3ZAagkbIwAZDZD';
const FB_API_VERSION  = 'v21.0';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$eventId = isset($_POST['event_id']) ? substr(trim((string) $_POST['event_id']), 0, 128) : '';
$url     = isset($_POST['url']) ? trim((string) $_POST['url']) : '';
if ($eventId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'event_id']);
    exit;
}

// responde JÁ e envia em 2º plano (não atrasa a página)
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}

function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if ($ip !== '') return $ip;
        }
    }
    return '';
}

$fbp = isset($_COOKIE['_fbp']) ? $_COOKIE['_fbp'] : '';
$fbc = isset($_COOKIE['_fbc']) ? $_COOKIE['_fbc'] : '';
// monta fbc a partir do fbclid (na URL da página) se não houver cookie _fbc
if ($fbc === '' && $url !== '' && preg_match('/[?&]fbclid=([^&#]+)/', $url, $m)) {
    $fbc = 'fb.1.' . (int) round(microtime(true) * 1000) . '.' . urldecode($m[1]);
}

$user = [
    'client_ip_address' => client_ip(),
    'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
];
if ($fbp !== '') $user['fbp'] = $fbp;
if ($fbc !== '') $user['fbc'] = $fbc;

$event = [
    'event_name'       => 'PageView',
    'event_time'       => time(),
    'event_id'         => $eventId,                 // dedup com o pixel do navegador
    'action_source'    => 'website',
    'event_source_url' => $url !== '' ? $url : ('https://' . ($_SERVER['HTTP_HOST'] ?? 'lp.liberdadeoperacional.com.br')),
    'user_data'        => $user,
];

$body   = json_encode(['data' => [$event]], JSON_UNESCAPED_UNICODE);
$metaUrl = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . urlencode(FB_ACCESS_TOKEN);
$ch = curl_init($metaUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 12,
]);
curl_exec($ch);
curl_close($ch);
