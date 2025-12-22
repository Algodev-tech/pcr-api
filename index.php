<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

const DHAN_ACCESS_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJpc3MiOiJkaGFuIiwicGFydG5lcklkIjoiIiwiZXhwIjoxNzY2NDY1NjMxLCJpYXQiOjE3NjYzNzkyMzEsInRva2VuQ29uc3VtZXJUeXBlIjoiU0VMRiIsIndlYmhvb2tVcmwiOiIiLCJkaGFuQ2xpZW50SWQiOiIxMTA3MTkwNjcyIn0.c0VMwdwejv2kPXBzGRX1QEREfgnFsBTG36Ylc-AsE9-iQ0NoC6HS3vGohT5SIBKACyvxW88z67UmkmWC4PWLNg';
const DHAN_CLIENT_ID    = '1107190672';

function dhan_post($url, array $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'access-token: ' . DHAN_ACCESS_TOKEN,
            'client-id'    => DHAN_CLIENT_ID,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ? json_decode($resp, true) : null;
}

$result = ['timestamp' => date('Y-m-d H:i:s')];

$expiry_res = dhan_post('https://api.dhan.co/v2/optionchain/expirylist', [
    'UnderlyingScrip' => 13,
    'UnderlyingSeg'   => 'IDX_I',
]);

if (!$expiry_res || ($expiry_res['status'] ?? '') !== 'success') {
    $result['error'] = 'Failed to fetch expiry';
    echo json_encode($result);
    exit;
}

$expiry = $expiry_res['data'][0] ?? null;

$oc_res = dhan_post('https://api.dhan.co/v2/optionchain', [
    'UnderlyingScrip' => 13,
    'UnderlyingSeg'   => 'IDX_I',
    'Expiry'          => $expiry,
]);

if (!$oc_res || ($oc_res['status'] ?? '') !== 'success') {
    $result['error'] = 'Failed to fetch option chain';
    echo json_encode($result);
    exit;
}

$last_price = $oc_res['data']['last_price'] ?? 0;
$oc = $oc_res['data']['oc'] ?? [];

$call_oi = $put_oi = $call_vol = $put_vol = 0;

foreach ($oc as $options) {
    $call_oi  += $options['ce']['oi']      ?? 0;
    $put_oi   += $options['pe']['oi']      ?? 0;
    $call_vol += $options['ce']['volume']  ?? 0;
    $put_vol  += $options['pe']['volume']  ?? 0;
}

$pcr        = $call_oi  > 0 ? round($put_oi / $call_oi, 2) : 0;
$volume_pcr = $call_vol > 0 ? round($put_vol / $call_vol, 2) : 0;

echo json_encode([
    'timestamp'    => date('Y-m-d H:i:s'),
    'price'        => $last_price,
    'call_oi'      => $call_oi,
    'put_oi'       => $put_oi,
    'pcr'          => $pcr,
    'call_volume'  => $call_vol,
    'put_volume'   => $put_vol,
    'volume_pcr'   => $volume_pcr,
    'expiry'       => $expiry,
]);
