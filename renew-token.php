<?php
// renew-token.php - Auto-renew Dhan access token

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

// Call Dhan RenewToken API with correct headers
$ch = curl_init('https://api.dhan.co/v2/RenewToken');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'access-token: ' . $current_token,
        'client-id: ' . $client_id,  // Changed from dhanClientId
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'cURL error: ' . $err,
        'http_code' => $code,
    ]);
    exit;
}

if ($code !== 200) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to renew token',
        'http_code' => $code,
        'response' => $resp,
    ]);
    exit;
}

$data = json_decode($resp, true);

// Check for error in response
if (isset($data['errorType']) || isset($data['errorCode'])) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Dhan API error',
        'response' => $data,
    ]);
    exit;
}

$new_token = $data['data']['access_token'] ?? $data['data']['accessToken'] ?? $data['accessToken'] ?? $data['access_token'] ?? null;

if (!$new_token) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'No token in response',
        'response' => $data,
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
]);
