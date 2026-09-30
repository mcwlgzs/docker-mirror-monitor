<?php
/**
 * Docker 镜像加速服务监控 - 核心库
 *
 * 这里放的是「纯函数」：环境初始化、探测引擎、错误翻译、缓存、限流。
 * 单独成文件的目的：
 *   1. api.php 只负责 HTTP 入口与路由，逻辑清晰；
 *   2. selftest.php / CLI 工具可以复用同一套探测代码，避免「测试的是一份实现、
 *      跑的是另一份实现」这种自欺欺人的自检。
 *
 * 依赖：config.php 返回的 $config 数组，由调用方传入。
 */

declare(strict_types=1);

const DMM_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/**
 * 读取一个布尔型环境变量（1/true/yes/on 视为真）。
 */
function dmm_env_flag(string $key): bool
{
    $value = getenv($key);
    if ($value === false) {
        return false;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * 初始化运行环境（错误上报、时区、目录）。
 *
 * 在任何输出之前调用；非法时区（容器里常见的 CST）回退到 Asia/Shanghai。
 *
 * @param array<string, mixed> $config
 */
function dmm_bootstrap(array $config): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    $tz = getenv('TZ');
    $applied = false;
    if (is_string($tz) && $tz !== '') {
        try {
            new DateTimeZone($tz);
            date_default_timezone_set($tz);
            $applied = true;
        } catch (Exception $e) {
            $applied = false;
        }
    }
    if (!$applied) {
        date_default_timezone_set('Asia/Shanghai');
    }

    dmm_ensure_dir((string) $config['cache']['dir']);
    dmm_ensure_dir((string) $config['log']['dir']);

    if (PHP_SAPI !== 'cli') {
        ini_set('max_execution_time', '60');
        ini_set('memory_limit', '128M');
    }
}

/**
 * 确保目录存在且可写。
 */
function dmm_ensure_dir(string $dir, int $mode = 0755): bool
{
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
        return false;
    }
    return is_writable($dir);
}

/* =====================================================================
 * HTTP 辅助
 * ===================================================================== */

/**
 * 输出 JSON 响应并结束请求。
 *
 * @param mixed $data
 */
function dmm_json($data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    $flags = DMM_JSON_FLAGS;
    if (!empty($_GET['pretty'])) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($data, $flags);
    exit;
}

/**
 * 标准化访客 IP（支持反向代理，需显式开启 trust_proxy）。
 */
function dmm_client_ip(array $config): string
{
    $candidates = [];

    if (!empty($config['trust_proxy'])) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // X-Forwarded-For: client, proxy1, proxy2 —— 取第一个
            $candidates[] = trim(explode(',', $forwarded)[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $candidates[] = trim((string) $_SERVER['HTTP_X_REAL_IP']);
        }
    }

    $candidates[] = $_SERVER['REMOTE_ADDR'] ?? '';

    foreach ($candidates as $ip) {
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

/* =====================================================================
 * 状态判定与错误翻译
 * ===================================================================== */

/**
 * 根据响应时间归类状态。
 *
 * 返回 'very_slow' 而不是 'error'：能握手成功说明服务是「可用但极慢」，
 * 把它标成错误会让用户误以为镜像源挂了，这是 v1.x 的观感问题之一。
 * 真正不可用的情况由 dmm_build_probe_result 直接判定为 error。
 *
 * @param array<string, int> $thresholds
 */
function dmm_classify_status(int $responseTime, array $thresholds): string
{
    if ($responseTime > $thresholds['slow']) {
        return 'very_slow';
    }
    if ($responseTime > $thresholds['fair']) {
        return 'slow';
    }
    if ($responseTime > $thresholds['fast']) {
        return 'fair';
    }
    return 'fast';
}

/**
 * 把 curl 错误码翻译成可读中文，便于用户在页面上直接定位问题。
 */
function dmm_translate_curl_error(int $errno, string $fallback): string
{
    // 使用数字字面量：CURLE_* 常量并非在所有 PHP/libcurl 构建中都存在，
    // 直接写常量名会触发 "Undefined constant" 致命错误。
    $map = [
        1 => '不支持的协议',
        3 => 'URL 格式错误',
        5 => '无法解析代理地址',
        6 => 'DNS 解析失败（域名不存在或解析被污染）',
        7 => '无法建立连接（端口关闭或被防火墙拦截）',
        28 => '连接或响应超时',
        35 => 'TLS 握手失败',
        47 => '跳转次数过多',
        51 => 'TLS 证书校验失败（证书链不完整）',
        52 => '连接被对端关闭，未返回数据',
        55 => '发送请求失败',
        56 => '接收数据失败（连接中断）',
        58 => '本地证书文件不可读',
        60 => 'TLS 证书校验失败（缺少根证书，请检查 CA 证书包）',
        77 => 'CA 证书文件不可读（请检查 CA 证书包路径）',
    ];

    if (isset($map[$errno])) {
        return $map[$errno];
    }

    return $fallback !== '' ? $fallback : "网络错误（curl errno {$errno}）";
}

/**
 * HTTP 状态码释义。
 */
function dmm_translate_http_error(int $code): string
{
    // Cloudflare 专属错误码，国内镜像站大量使用 CF 加速，误报时最难排查
    $cloudflare = [
        520 => '源站返回未知错误',
        521 => '源站已离线',
        522 => '与源站连接超时',
        523 => '源站地址不可达',
        524 => '源站响应超时',
        525 => '与源站 SSL 握手失败',
        526 => '源站 SSL 证书无效',
    ];

    if (isset($cloudflare[$code])) {
        return "HTTP {$code}：Cloudflare 报错 - {$cloudflare[$code]}（镜像源自身故障）";
    }

    if ($code >= 500) {
        return "服务端错误 HTTP {$code}（镜像源自身故障或网关异常）";
    }
    if ($code === 404) {
        return 'HTTP 404：Registry API 不可用（该地址可能不是有效的镜像加速服务）';
    }
    if ($code === 429) {
        return 'HTTP 429：请求被限流';
    }
    if ($code >= 400) {
        return "HTTP {$code}：请求被拒绝";
    }
    if ($code >= 300) {
        return "HTTP {$code}：跳转未成功跟随";
    }
    return "HTTP {$code}：非预期响应";
}

/**
 * 解析可用的 CA 证书包路径。
 *
 * 生产环境（Debian/Ubuntu 镜像）使用系统证书即可；Windows / 便携版 PHP
 * 往往缺少 curl.cainfo 配置，必须显式指定，否则所有 HTTPS 探测都会以
 * "SSL certificate problem: unable to get local issuer certificate" 失败。
 *
 * @return string|null 可用路径，找不到时返回 null（交由 curl 默认行为处理）
 */
function dmm_resolve_ca_bundle(array $config): ?string
{
    $candidates = [];

    // 1. 显式配置优先
    $configured = $config['probe']['ca_bundle'] ?? '';
    if (is_string($configured) && $configured !== '') {
        $candidates[] = $configured;
    }

    // 2. php.ini 配置
    foreach (['curl.cainfo', 'openssl.cafile'] as $iniKey) {
        $value = (string) ini_get($iniKey);
        if ($value !== '') {
            $candidates[] = $value;
        }
    }

    // 3. 环境变量
    foreach (['CURL_CA_BUNDLE', 'SSL_CERT_FILE'] as $envKey) {
        $value = (string) getenv($envKey);
        if ($value !== '') {
            $candidates[] = $value;
        }
    }

    // 4. 常见系统位置
    $candidates[] = '/etc/ssl/certs/ca-certificates.crt';           // Debian / Ubuntu
    $candidates[] = '/etc/pki/tls/certs/ca-bundle.crt';             // RHEL / CentOS
    $candidates[] = '/etc/ssl/cert.pem';                            // Alpine / macOS
    $candidates[] = '/usr/local/share/ca-certificates/cacert.pem';
    $candidates[] = 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt';
    $candidates[] = 'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt';
    $candidates[] = 'C:/php/extras/ssl/cacert.pem';

    foreach ($candidates as $path) {
        if ($path !== '' && @is_file($path) && @is_readable($path)) {
            return $path;
        }
    }

    return null;
}

/* =====================================================================
 * 速率限制
 * ===================================================================== */

/**
 * 基于文件的滑动窗口速率限制。
 *
 * @return array{allowed: bool, remaining: int, retry_after: int}
 */
function dmm_rate_limit(array $config, string $cacheDir, string $ip): array
{
    $rl = $config['rate_limit'];
    if (empty($rl['enabled'])) {
        return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
    }

    $file = $cacheDir . 'rate_' . sha1($ip) . '.json';
    $now = time();
    $window = max(1, (int) $rl['window']);
    $max = max(1, (int) $rl['max_requests']);
    $blockDuration = max(0, (int) $rl['block_duration']);

    $state = ['hits' => [], 'blocked_until' => 0];

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        // 无法写入限流文件时不阻断业务
        return ['allowed' => true, 'remaining' => $max, 'retry_after' => 0];
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            return ['allowed' => true, 'remaining' => $max, 'retry_after' => 0];
        }

        $raw = stream_get_contents($fh);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state['hits'] = is_array($decoded['hits'] ?? null) ? $decoded['hits'] : [];
                $state['blocked_until'] = (int) ($decoded['blocked_until'] ?? 0);
            }
        }

        // 仍在封禁期内
        if ($state['blocked_until'] > $now) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $state['blocked_until'] - $now,
            ];
        }

        // 丢弃窗口外的历史记录
        $state['hits'] = array_values(array_filter(
            $state['hits'],
            static fn($t) => is_int($t) && ($now - $t) < $window
        ));

        if (count($state['hits']) >= $max) {
            $state['blocked_until'] = $blockDuration > 0 ? $now + $blockDuration : 0;
            $retryAfter = $blockDuration > 0 ? $blockDuration : $window;

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state));
            fflush($fh);

            return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
        }

        $state['hits'][] = $now;
        $state['blocked_until'] = 0;

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);

        return [
            'allowed' => true,
            'remaining' => max(0, $max - count($state['hits'])),
            'retry_after' => 0,
        ];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * 清理过期的限流文件（避免 data/cache 无限增长）。
 */
function dmm_gc_rate_files(string $cacheDir, int $window, int $blockDuration): void
{
    $cutoff = time() - max($window, $blockDuration) * 4;
    $files = glob($cacheDir . 'rate_*.json');
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/* =====================================================================
 * 探测引擎
 * ===================================================================== */

/**
 * 并发探测多个服务（curl_multi 单轮完成，无串行回退）。
 *
 * @param array<int, array<string, mixed>> $services 服务定义（含 probe）
 * @return array<int, array<string, mixed>>           与输入顺序一致的探测结果
 */
function dmm_probe_services(array $services, array $config): array
{
    $probeConfig = $config['probe'];

    if ($services === []) {
        return [];
    }

    $mh = curl_multi_init();
    if ($mh === false) {
        return array_map(
            static fn($s) => dmm_probe_failure($s, 'curl_multi_init failed'),
            $services
        );
    }

    // 解析 CA 证书包；找不到时显式报错，避免所有 HTTPS 探测静默失败
    $caBundle = dmm_resolve_ca_bundle($config);

    /** @var array<int, array{ch: resource|\CurlHandle, service: array, started: float}> $handles */
    $handles = [];

    foreach ($services as $index => $service) {
        $probeUrl = $service['probe'] ?? ($service['url'] . '/v2/');
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $probeUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $probeConfig['timeout'],
            CURLOPT_CONNECTTIMEOUT => (int) $probeConfig['connect_timeout'],
            CURLOPT_USERAGENT => $probeConfig['user_agent'],
            CURLOPT_FOLLOWLOCATION => (bool) $probeConfig['follow_redirects'],
            CURLOPT_MAXREDIRS => (int) $probeConfig['max_redirects'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 只取极少量响应体，握手响应本身很小
            CURLOPT_RANGE => '0-2048',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Connection: keep-alive',
            ],
        ];

        // 协议限制（PHP >= 8.2 / libcurl >= 7.85 才提供 *_STR 常量）
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        }

        // 显式指定 CA 包，保证 HTTPS 校验可用
        if ($caBundle !== null) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        // 逃生开关：仅用于确认「是证书问题还是镜像问题」的排查场景
        if (dmm_env_flag('DMM_INSECURE_TLS')) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $options);
        curl_multi_add_handle($mh, $ch);

        $handles[$index] = [
            'ch' => $ch,
            'service' => $service,
            'started' => microtime(true),
        ];
    }

    // 并发执行
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0 && $status === CURLM_OK);

    // 关键：必须排空消息队列。
    // curl_multi 的传输错误（如 CURLE_SSL_PEER_CERTIFICATE=60）只通过消息队列返回，
    // 此时 curl_errno() 仍为 0、CURLINFO_HTTP_CODE 为 0 —— 若只看 errno 会误判为
    // "未收到任何 HTTP 响应"，这正是早期版本"明明能用的镜像检测不出来"的原因之一。
    $queueResults = [];
    while (($msg = curl_multi_info_read($mh)) !== false) {
        if (isset($msg['handle'])) {
            $queueResults[spl_object_id($msg['handle'])] = (int) ($msg['result'] ?? 0);
        }
    }

    $results = [];

    foreach ($handles as $index => $item) {
        $ch = $item['ch'];
        $service = $item['service'];
        $elapsedMs = (int) round((microtime(true) - $item['started']) * 1000);

        $errno = curl_errno($ch);
        $error = curl_error($ch);

        // 消息队列里的 CURLE 结果更可靠，优先采用
        $queueResult = $queueResults[spl_object_id($ch)] ?? null;
        if ($errno === 0 && $queueResult !== null && $queueResult !== CURLE_OK) {
            $errno = $queueResult;
            if ($error === '') {
                $error = dmm_translate_curl_error($queueResult, '');
            }
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        // 用 curl 自身统计的耗时，比外部计时更精确
        $totalTimeMs = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $responseTime = $totalTimeMs > 0 ? $totalTimeMs : $elapsedMs;
        $results[$index] = dmm_build_probe_result(
            $service,
            $errno,
            $error,
            $httpCode,
            $primaryIp,
            $responseTime,
            $config
        );
    }

    curl_multi_close($mh);

    ksort($results);
    return array_values($results);
}

/**
 * 组装单条探测结果。
 *
 * @param array<string, mixed> $service
 * @return array<string, mixed>
 */
function dmm_build_probe_result(
    array $service,
    int $errno,
    string $error,
    int $httpCode,
    string $primaryIp,
    int $responseTime,
    array $config
): array {
    $probeConfig = $config['probe'];
    $thresholds = $config['thresholds'];

    $result = [
        'name' => $service['name'] ?? '',
        'url' => $service['url'] ?? '',
        'provider' => $service['provider'] ?? '',
        'description' => $service['description'] ?? '',
        'region' => $service['region'] ?? 'public',
        'note' => $service['note'] ?? '',
        'status' => 'error',
        'responseTime' => $responseTime,
        'httpCode' => $httpCode,
        'error' => '',
        'errorCode' => '',
        'reachable' => false,
        'authRequired' => false,
        'method' => 'registry-handshake',
        'ip' => $primaryIp,
        'probeUrl' => $service['probe'] ?? '',
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    if ($errno !== 0) {
        $result['reachable'] = false;
        $result['errorCode'] = 'CURL_' . $errno;
        $result['error'] = dmm_translate_curl_error($errno, $error);
        $result['status'] = 'error';
        return $result;
    }

    if ($httpCode === 0) {
        $result['errorCode'] = 'NO_RESPONSE';
        $result['error'] = '未收到任何 HTTP 响应';
        return $result;
    }

    // 能拿到 HTTP 状态码即说明网络层可达
    $result['reachable'] = true;

    if (in_array($httpCode, $probeConfig['success_status'], true)) {
        $result['authRequired'] = in_array($httpCode, $probeConfig['auth_status'], true);
        $result['status'] = dmm_classify_status($responseTime, $thresholds);
        $result['error'] = '';
        return $result;
    }

    $result['errorCode'] = 'HTTP_' . $httpCode;
    $result['error'] = dmm_translate_http_error($httpCode);
    // 5xx 属于服务端故障；4xx（除鉴权码）说明路径/服务异常
    $result['status'] = 'error';
    return $result;
}

/**
 * 探测完全失败（无法初始化 curl 等）。
 *
 * @param array<string, mixed> $service
 * @return array<string, mixed>
 */
function dmm_probe_failure(array $service, string $reason): array
{
    return [
        'name' => $service['name'] ?? '',
        'url' => $service['url'] ?? '',
        'provider' => $service['provider'] ?? '',
        'description' => $service['description'] ?? '',
        'region' => $service['region'] ?? 'public',
        'note' => $service['note'] ?? '',
        'status' => 'error',
        'responseTime' => 0,
        'httpCode' => 0,
        'error' => $reason,
        'errorCode' => 'PROBE_FAILED',
        'reachable' => false,
        'authRequired' => false,
        'method' => 'registry-handshake',
        'ip' => '',
        'probeUrl' => $service['probe'] ?? '',
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

/* =====================================================================
 * 缓存
 * ===================================================================== */

/**
 * 读取缓存。
 *
 * @return array<string, mixed>|null
 */
function dmm_cache_get(string $key, array $config): ?array
{
    $file = $config['cache']['dir'] . 'resp_' . sha1($key) . '.json';
    if (!is_file($file)) {
        return null;
    }

    $mtime = @filemtime($file);
    if ($mtime === false || (time() - $mtime) >= (int) $config['cache']['duration']) {
        return null;
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        @unlink($file);
        return null;
    }

    $data['cached'] = true;
    $data['cache_time'] = date('Y-m-d H:i:s', $mtime);
    $data['cache_age'] = time() - $mtime;
    $data['cache_ttl'] = max(0, (int) $config['cache']['duration'] - (time() - $mtime));

    return $data;
}

/**
 * 原子写入缓存（先写临时文件再 rename，避免读到半截 JSON）。
 *
 * @param array<string, mixed> $data
 */
function dmm_cache_set(string $key, array $data, array $config): void
{
    $file = $config['cache']['dir'] . 'resp_' . sha1($key) . '.json';
    $payload = json_encode($data, DMM_JSON_FLAGS);
    if ($payload === false) {
        return;
    }

    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        return;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

/* =====================================================================
 * 其他
 * ===================================================================== */

/**
 * 汇总统计。
 *
 * @param array<int, array<string, mixed>> $results
 * @return array<string, int|float>
 */
function dmm_summarize(array $results): array
{
    $stats = [
        'total' => 0,
        'fast' => 0,
        'fair' => 0,
        'slow' => 0,
        'very_slow' => 0,
        'error' => 0,
        'available' => 0,
    ];

    foreach ($results as $r) {
        $stats['total']++;
        $status = $r['status'] ?? 'error';
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
        // 只要不是 error 就算可用：慢不等于不可用
        if ($status !== 'error') {
            $stats['available']++;
        }
    }

    $stats['availability'] = $stats['total'] > 0
        ? round($stats['available'] / $stats['total'] * 100, 1)
        : 0.0;

    return $stats;
}

/**
 * 公开的服务列表（不含探针地址，避免暴露内部实现）。
 *
 * @param array<int, array<string, mixed>> $services
 * @return array<int, array<string, mixed>>
 */
function dmm_public_services(array $services): array
{
    $out = [];
    foreach ($services as $service) {
        $out[] = [
            'id' => $service['id'] ?? '',
            'name' => $service['name'] ?? '',
            'url' => $service['url'] ?? '',
            'provider' => $service['provider'] ?? '',
            'description' => $service['description'] ?? '',
            'region' => $service['region'] ?? 'public',
            'note' => $service['note'] ?? '',
        ];
    }
    return $out;
}
