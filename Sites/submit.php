<?php
/**
 * submit.php — recebe o lead do navegador e encaminha 100% server-side para:
 *   1) a planilha da cidade (Apps Script /exec) — com dados enriquecidos
 *   2) o Agendor (webhook do Make) — com dados enriquecidos
 *   3) a Meta Conversions API — evento "Lead" + "LeadMQL" (se colaboradores > 5)
 *
 * Enrichment server-side (nada disso vem do form do usuário):
 *   - IP → geoIP (city, state, country, zip)
 *   - Cookie _fbp e _fbc (ou fbclid → fbc)
 *   - external_id = hash(WhatsApp)
 *   - event_source_url = HTTP_REFERER
 *
 * UTMs/fbclid capturados no navegador (campos ocultos) e repassados aqui.
 * URLs de webhook e token da Meta ficam SÓ aqui no servidor — nunca no HTML.
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

// ===== Faixas de colaboradores que qualificam como LeadMQL (>5) =====
const MQL_MARKERS = ['5 a 10', '10 a 20', 'Mais de'];

// ===== Guards =====
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

function v($k) { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : ''; }

// ===== Payload base (mesmas chaves das colunas da planilha) =====
$payload = [
    'Nome'          => v('nome'),
    'Email'         => v('email'),
    'WhatsApp'      => v('whatsapp'),
    'Cidade'        => v('cidade'),                 // do form (hoje vazio — removido)
    'Colaboradores' => v('colaboradores'),
];
foreach (['utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term', 'gclid', 'fbclid'] as $t) {
    $payload[$t] = v($t);
}
if (v('dor') !== '') { $payload['dor'] = v('dor'); }

if ($payload['Nome'] === '' || $payload['WhatsApp'] === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'campos']);
    exit;
}

// ===== IP real do cliente =====
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

// ===== geoIP lookup (freeipapi.com + fallback ip-api.com) =====
function geoip_lookup($ip) {
    if ($ip === '' || in_array($ip, ['127.0.0.1', '::1'])) return null;
    // primary: freeipapi (HTTPS, sem rate limit conhecido)
    $ch = curl_init("https://freeipapi.com/api/json/{$ip}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && $resp) {
        $j = json_decode($resp, true);
        if (is_array($j) && !empty($j['cityName'])) {
            return [
                'city'    => $j['cityName']    ?? '',
                'state'   => $j['regionName']  ?? '',
                'country' => strtolower($j['countryCode'] ?? ''),
                'zip'     => $j['zipCode']     ?? '',
            ];
        }
    }
    // fallback: ip-api (HTTP-only no free)
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,city,regionName,region,countryCode,zip");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && $resp) {
        $j = json_decode($resp, true);
        if (is_array($j) && ($j['status'] ?? '') === 'success') {
            return [
                'city'    => $j['city']        ?? '',
                'state'   => $j['regionName']  ?? '',
                'country' => strtolower($j['countryCode'] ?? ''),
                'zip'     => $j['zip']         ?? '',
            ];
        }
    }
    return null;
}

// ===== Qualifica lead como MQL (colaboradores > 5) =====
function is_mql($colaboradores) {
    $c = (string) $colaboradores;
    foreach (MQL_MARKERS as $m) {
        if (stripos($c, $m) !== false) return true;
    }
    return false;
}

// ===== Monta um evento CAPI (Lead ou LeadMQL) =====
function meta_event($event_name, $event_id, $evento, $payload, $geo) {
    $h = function ($v) { $v = strtolower(trim($v)); return $v === '' ? null : hash('sha256', $v); };

    // telefone só dígitos, com DDI Brasil
    $digits = preg_replace('/\D+/', '', $payload['WhatsApp']);
    if ($digits !== '' && strlen($digits) <= 11) { $digits = '55' . $digits; }

    // nome → fn + ln
    $nome  = trim($payload['Nome']);
    $parts = preg_split('/\s+/', $nome);
    $fn    = $parts[0] ?? '';
    $ln    = (count($parts) > 1) ? end($parts) : '';

    $ud = [];
    if (!empty($payload['Email']))     { $ud['em'] = [$h($payload['Email'])]; }
    if ($digits !== '')                { $ud['ph'] = [hash('sha256', $digits)]; }
    if ($fn !== '')                    { $ud['fn'] = [$h($fn)]; }
    if ($ln !== '')                    { $ud['ln'] = [$h($ln)]; }

    // localidade: prioriza cidade do form; senão usa geoIP
    $city_raw = $payload['Cidade'] !== '' ? $payload['Cidade'] : ($geo['city'] ?? '');
    if ($city_raw !== '') { $ud['ct'] = [$h(preg_replace('/\s+/', '', $city_raw))]; }
    if (!empty($geo['state']))   { $ud['st']      = [$h(preg_replace('/\s+/', '', $geo['state']))]; }
    if (!empty($geo['country'])) { $ud['country'] = [$h($geo['country'])]; }
    if (!empty($geo['zip']))     { $ud['zp']      = [$h(preg_replace('/\D/', '', $geo['zip']))]; }

    // fbp / fbc dos cookies (fallback: constrói fbc do fbclid)
    if (!empty($_COOKIE['_fbp'])) { $ud['fbp'] = $_COOKIE['_fbp']; }
    if (!empty($_COOKIE['_fbc'])) {
        $ud['fbc'] = $_COOKIE['_fbc'];
    } elseif (!empty($payload['fbclid'])) {
        $ud['fbc'] = 'fb.1.' . (time() * 1000) . '.' . $payload['fbclid'];
    }

    // external_id estável (hash do WhatsApp) — melhora matching
    if ($digits !== '') { $ud['external_id'] = [hash('sha256', $digits)]; }

    $ip = client_ip();
    if ($ip !== '') { $ud['client_ip_address'] = $ip; }
    if (!empty($_SERVER['HTTP_USER_AGENT'])) { $ud['client_user_agent'] = $_SERVER['HTTP_USER_AGENT']; }

    $event = [
        'event_name'    => $event_name,
        'event_time'    => time(),
        'action_source' => 'website',
        'event_id'      => substr($event_id, 0, 128),
        'user_data'     => $ud,
        'custom_data'   => [
            'content_name' => ucfirst($evento),
            'lead_type'    => ($event_name === 'LeadMQL') ? 'mql' : 'standard',
            'colaboradores'=> $payload['Colaboradores'],
            'utm_source'   => $payload['utm_source'],
            'utm_campaign' => $payload['utm_campaign'],
            'utm_medium'   => $payload['utm_medium'],
            'utm_content'  => $payload['utm_content'],
            'utm_term'     => $payload['utm_term'],
        ],
    ];
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $event['event_source_url'] = $_SERVER['HTTP_REFERER'];
    }
    return $event;
}

// ===== Responde JÁ (cadastro instantâneo). O forward continua depois. =====
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}

// ===== Enrichment: geoIP =====
$geo = geoip_lookup(client_ip());
if ($geo) {
    $payload['Cidade_geo'] = $geo['city']    ?? '';
    $payload['UF']         = $geo['state']   ?? '';
    $payload['Pais']       = strtoupper($geo['country'] ?? '');
    $payload['CEP']        = $geo['zip']     ?? '';
}

// ===== Monta eventos CAPI =====
$leadEventId = v('lead_event_id');
if ($leadEventId === '') { $leadEventId = v('event_id'); }
if ($leadEventId === '') { $leadEventId = 'lead.' . $evento . '.' . bin2hex(random_bytes(6)); }

$events = [ meta_event('Lead', $leadEventId, $evento, $payload, $geo) ];

if (is_mql($payload['Colaboradores'])) {
    // LeadMQL usa event_id derivado do Lead (compostabilidade) mas distinto
    $mqlEventId = 'mql.' . substr($leadEventId, 0, 100);
    $events[] = meta_event('LeadMQL', $mqlEventId, $evento, $payload, $geo);
    $payload['MQL'] = 'yes';
} else {
    $payload['MQL'] = 'no';
}

$metaBody = json_encode(['data' => $events], JSON_UNESCAPED_UNICODE);
$metaUrl  = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . FB_PIXEL_ID . '/events?access_token=' . urlencode(FB_ACCESS_TOKEN);
$makeUrl  = $cfg['make'] . '&' . http_build_query($payload);

// ===== Forward paralelo: planilha + Make + Meta CAPI =====
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
