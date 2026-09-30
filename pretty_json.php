<?php
/**
 * 开发者辅助脚本：把一个 API 的 JSON 响应格式化打印出来，便于人工核对。
 *
 * 用法：
 *   curl.exe -s "http://127.0.0.1:8777/api.php?action=check_all&force=1" -o check.json
 *   php pretty_json.php check.json
 *
 * 该脚本仅供开发调试，部署时可以删除。
 */

// 仅供命令行使用，禁止 web 访问
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("pretty_json.php 只能在命令行运行\n");
}

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "usage: php pretty_json.php <response.json>\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($file), true);
if (!is_array($data)) {
    fwrite(STDERR, "not valid json\n");
    exit(1);
}

if (!empty($data['data']) && is_array($data['data'])) {
    printf(
        "success=%s engine=%s scope=%s time=%sms cached=%s\n",
        var_export($data['success'] ?? null, true),
        $data['engine'] ?? '-',
        $data['scope'] ?? '-',
        $data['check_time_ms'] ?? '-',
        var_export($data['cached'] ?? false, true)
    );

    if (isset($data['stats'])) {
        echo 'stats=' . json_encode($data['stats'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }

    foreach ($data['data'] as $row) {
        printf(
            "  %-14s %-5s %-8s %-10s %s\n",
            $row['name'] ?? '?',
            ($row['httpCode'] ?? 0) > 0 ? $row['httpCode'] : '-',
            ($row['responseTime'] ?? 0) . 'ms',
            $row['status'] ?? '?',
            $row['error'] ?? ($row['probeUrl'] ?? '')
        );
    }
    exit(0);
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
