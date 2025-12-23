<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Read from environment variables set on Render
$dhan_access_token = getenv('DHAN_ACCESS_TOKEN');
$dhan_client_id    = getenv('DHAN_CLIENT_ID');

// If env vars are missing, return error
if (!$dhan_access_token || !$dhan_client_id) {
    http_response_code(500);
    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'error'     => 'dhan_env_missing',
        'message'   => 'DHAN_ACCESS_TOKEN or DHAN_CLIENT_ID not set on server',
    ]);
    exit;
}

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

function dhan_post($url, array $body) {
    global $dhan_access_token, $dhan_client_id;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'access-token: ' . $dhan_access_token,
            'client-id: '   . $dhan_client_id,
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
    // 1) Get expiry list
    $expiry_res = dhan_post('https://api.dhan.co/v2/optionchain/expirylist', [
        'UnderlyingScrip' => $scrip,
        'UnderlyingSeg'   => $seg,
    ]);

    // TEMP debug: log expiry response to Render logs
    error_log('Expiry response for ' . $scrip . ': ' . json_encode($expiry_res));

    if (($expiry_res['status'] ?? '') !== 'success') {
        return ['error' => 'expiry_failed', 'raw' => $expiry_res];
    }

    $expiry = $expiry_res['data'][0] ?? null;
    if (!$expiry) {
        return ['error' => 'no_expiry', 'raw' => $expiry_res];
    }

    // 2) Get option chain for that expiry
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

// Main response
$result = ['timestamp' => date('Y-m-d H:i:s')];  // IST

$result['NIFTY']     = get_pcr_for_index(13, 'IDX_I');
$result['BANKNIFTY'] = get_pcr_for_index(25, 'IDX_I');
$result['FINNIFTY']  = get_pcr_for_index(51, 'IDX_I');

// If any index failed, add a top-level error message
if (
    isset($result['NIFTY']['error']) ||
    isset($result['BANKNIFTY']['error']) ||
    isset($result['FINNIFTY']['error'])
) {
    $result['error'] = 'Failed to fetch expiry';
}

echo json_encode($result);
