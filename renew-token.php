<?php
// renew-token.php - Auto-renew Dhan access token with debug

header('Content-Type: application/json');

$token_file = sys_get_temp_dir() . '/dhan_access_token.txt';
$client_id  = getenv('DHAN_CLIENT_ID');

// Read current token
if (!is_file($token_file)) {
    // First time: use env var as initial token
    $current_token = getenv('DHAN_ACCESS_TOKEN');
    if ($current_token) {
        file_put_contents($token_file, $current_token);
    }
} else {
    $current_token = trim(file_get_contents($token_file));
}

if (!$current_token || !$client_id) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Token or Client ID missing',
    ]);
    exit;
}

// === DEBUG: Log what we're sending ===
$debug_info = [
    'url' => 'https://api.dhan.co/v2/RenewToken',
    'method' => 'POST',
    'client_id' => $client_id,
    'token_preview' => substr($current_token, 0, 30) . '...',
    'token_length' => strlen($current_token),
    'headers_sent' => [
        'access-token: (present)',
        'dhanClientId: ' . $client_id,
    ],
];

error_log('=== DHAN RENEW TOKEN DEBUG ===');
error_log(json_encode($debug_info, JSON_PRETTY_PRINT));

// Call Dhan RenewToken API
$ch = curl_init('https://api.dhan.co/v2/RenewToken');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_HTTPHEADER     => [
        'access-token: ' . $current_token,
        'dhanClientId: ' . $client_id,
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

// === DEBUG: Log response ===
error_log('=== DHAN RESPONSE ===');
error_log('HTTP Code: ' . $code);
error_log('Response: ' . $resp);

if ($resp === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'cURL error: ' . $err,
        'http_code' => $code,
        'debug' => $debug_info,
    ]);
    exit;
}

$data = json_decode($resp, true);

// Check for error response
if ($code !== 200 || isset($data['errorType']) || isset($data['errorCode'])) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to renew token',
        'http_code' => $code,
        'response' => $data,
        'debug' => $debug_info,
    ]);
    exit;
}

// Extract new token from response
$new_token = $data['accessToken'] ?? null;

if (!$new_token) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'No accessToken in response',
        'response' => $data,
        'debug' => $debug_info,
    ]);
    exit;
}

// Save new token to file
file_put_contents($token_file, $new_token);

echo json_encode([
    'status' => 'success',
    'message' => 'Token renewed successfully',
    'renewed_at' => date('Y-m-d H:i:s'),
    'token_preview' => substr($new_token, 0, 20) . '...',
    'expiry_time' => $data['expiryTime'] ?? 'unknown',
]);
