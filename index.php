<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

const DHAN_ACCESS_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJpc3MiOiJkaGFuIiwicGFydG5lcklkIjoiIiwiZXhwIjoxNzY2NDY1NjMxLCJpYXQiOjE3NjYzNzkyMzEsInRva2VuQ29uc3VtZXJUeXBlIjoiU0VMRiIsIndlYmhvb2tVcmwiOiIiLCJkaGFuQ2xpZW50SWQiOiIxMTA3MTkwNjcyIn0.c0VMwdwejv2kPXBzGRX1QEREfgnFsBTG36Ylc-AsE9-iQ0NoC6HS3vGohT5SIBKACyvxW88z67UmkmWC4PWLNg';
const DHAN_CLIENT_ID    = '1107190672';

function dhan_post($url, array $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'access-token: ' . DHAN_ACCESS_TOKEN,
            'client-id: '   . DHAN_CLIENT_ID,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['status' => 'failed', 'error' => $err, '_http' => $code];
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return ['status' => 'failed', 'error' => 'Invalid JSON', '_http' => $code, '_raw' => $resp];
    }
    $json['_http'] = $code;
    return $json;
}

function get_pcr_for_index($scrip, $seg = 'IDX_I') {
    $expiry_res = dhan_post('https://api.dhan.co/v2/optionchain/expirylist', [
        'UnderlyingScrip' => $scrip,
        'UnderlyingSeg'   => $seg,
    ]);

    if (($expiry_res['status'] ?? '') !== 'success') {
        return ['error' => 'expiry_failed', 'raw' => $expiry_res];
    }

    $expiry = $expiry_res['data'][0] ?? null;
    if (!$expiry) {
        return ['error' => 'no_expiry', 'raw' => $expiry_res];
    }

    $oc_res = dhan_post('https://api.dhan.co/v2/optionchain', [
        'UnderlyingScrip' => $scrip,
        'UnderlyingSeg'   => $seg,
        'Expiry'          => $expiry,
    ]);

    if (($oc_res['status'] ?? '') !== 'success') {
        return ['error' => 'oc_failed', 'raw' => $oc_res, 'expiry' => $expiry];
    }

    $last_price = $oc_res['data']['last_price'] ?? 0;
    $oc         = $oc_res['data']['oc'] ?? [];

    $call_oi = $put_oi = $call_vol = $put_vol = 0;

    foreach ($oc as $options) {
        $call_oi  += $options['ce']['oi']     ?? 0;
        $put_oi   += $options['pe']['oi']     ?? 0;
        $call_vol += $options['ce']['volume'] ?? 0;
        $put_vol  += $options['pe']['volume'] ?? 0;
    }

    $pcr        = $call_oi  > 0 ? round($put_oi / $call_oi, 2)   : 0;
    $volume_pcr = $call_vol > 0 ? round($put_vol / $call_vol, 2) : 0;

    return [
        'price'        => $last_price,
        'call_oi'      => $call_oi,
        'put_oi'       => $put_oi,
        'pcr'          => $pcr,
        'call_volume'  => $call_vol,
        'put_volume'   => $put_vol,
        'volume_pcr'   => $volume_pcr,
        'expiry'       => $expiry,
    ];
}

$result = ['timestamp' => date('Y-m-d H:i:s')];

$result['NIFTY']     = get_pcr_for_index(13, 'IDX_I');
$result['BANKNIFTY'] = get_pcr_for_index(25, 'IDX_I');
$result['FINNIFTY']  = get_pcr_for_index(51, 'IDX_I');

echo json_encode($result);
