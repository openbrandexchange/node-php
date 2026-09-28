<?php
// Test webhook receiver: stores every request (headers + raw body) as JSON lines.
$file = getenv('OBX_RECEIVER_FILE') ?: sys_get_temp_dir() . '/obx-receiver.ndjson';
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
file_put_contents($file, json_encode(['path' => $_SERVER['REQUEST_URI'], 'headers' => $headers, 'body' => file_get_contents('php://input')]) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(str_contains($_SERVER['REQUEST_URI'], 'fail') ? 500 : 200);
echo 'ok';
