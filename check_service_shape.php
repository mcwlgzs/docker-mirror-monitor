<?php
/**
 * 开发者辅助脚本：验证 ?action=check_service 的返回结构。
 * 用法：php check_service_shape.php <base-url>
 * 例如：php check_service_shape.php http://127.0.0.1:8080
 *
 * 该脚本仅供开发调试，部署时可以删除。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("check_service_shape.php 只能在命令行运行\n");
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');

$body = json_encode(['url' => 'https://registry.cn-hangzhou.aliyuncs.com']);

$ch = curl_init($base . '/api.php?action=check_service');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP {$httpCode}" . ($curlError !== '' ? " curl_error={$curlError}" : '') . PHP_EOL;

$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    echo "非 JSON 响应：\n" . substr((string) $raw, 0, 400) . PHP_EOL;
    exit(1);
}

if (!($payload['success'] ?? false)) {
    echo 'success=false error=' . ($payload['error'] ?? '?') . PHP_EOL;
    exit(1);
}

// 真实结构：服务字段与探测字段平铺在同一层
$row = $payload['data'];
printf(
    "name=%s\nurl=%s\nprobeUrl=%s\nhttpCode=%s\nresponseTime=%sms\nstatus=%s\nreachable=%s\nauthRequired=%s\nmethod=%s\nerror=%s\n",
    $row['name'] ?? '?',
    $row['url'] ?? '?',
    $row['probeUrl'] ?? '?',
    $row['httpCode'] ?? '?',
    $row['responseTime'] ?? '?',
    $row['status'] ?? '?',
    var_export($row['reachable'] ?? null, true),
    var_export($row['authRequired'] ?? null, true),
    $row['method'] ?? '?',
    $row['error'] ?? ''
);

$required = ['name', 'url', 'probeUrl', 'httpCode', 'responseTime', 'status', 'reachable', 'method'];
$missing = array_values(array_filter($required, static fn ($k) => !array_key_exists($k, $row)));

if ($missing !== []) {
    echo '缺少字段：' . implode(', ', $missing) . PHP_EOL;
    exit(1);
}

if (($row['status'] ?? '') === 'error') {
    echo "注意：该源本次探测失败，但仍返回了完整结构\n";
}

echo "\ncheck_service 返回结构正确。\n";
exit(0);
