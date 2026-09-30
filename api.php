<?php
/**
 * Docker 镜像加速服务监控 - API
 *
 * 端点：
 *   GET  ?action=get_services   - 获取服务列表与站点信息
 *   GET  ?action=check_all      - 并发检测全部服务（带缓存）
 *   GET  ?action=quick_check    - 并发检测前 N 个服务（带缓存）
 *   POST ?action=check_service  - 检测单个服务 {"url": "..."}
 *   GET  ?action=health         - 健康检查 / 容器探针
 *   GET  ?action=version        - 版本信息
 *
 * 参数：
 *   force=1   跳过缓存强制重新检测
 *
 * 设计要点（v2.0）：
 *   1. 探针由本服务器直接发起，测量「本机 → 镜像源」的真实可达性与握手耗时。
 *      v1.x 依赖第三方 ping 接口，测的是「第三方服务器 → 镜像源」，既不准也不稳。
 *   2. 使用 Docker Registry 规范定义的 /v2/ 握手端点判断镜像源是否真的可用，
 *      200（公开）与 401/403（正常要求鉴权）都算可用。
 *   3. 全部服务在单轮 curl_multi 中并发探测，整体耗时约等于最慢的一个源，
 *      而不是逐个串行累加（v1.x 的串行回退是"检测慢"的主因）。
 *
 * 本文件只负责 HTTP 入口与路由；探测、缓存、限流等实现见 lib.php。
 */

// ==================== 初始化 ====================

/** @var array<string, mixed> $config */
try {
    $config = require __DIR__ . '/config.php';
    require_once __DIR__ . '/lib.php';
} catch (Throwable $e) {
    // 配置写错时（URL 非法、id 重复等）必须在输出任何内容之前失败，
    // 否则前端只会看到一个语法残缺的 JSON，难以定位问题。
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    }
    error_log('[docker-mirror-monitor] 配置加载失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => '配置加载失败：' . $e->getMessage(),
        'errorCode' => 'CONFIG_ERROR',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}

dmm_bootstrap($config);

// 数据目录就绪状态：健康检查与错误响应都要用到
$cacheDir = (string) $config['cache']['dir'];
$logDir = (string) $config['log']['dir'];
$dirErrors = [];
if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
    $dirErrors[] = "缓存目录不可写: {$cacheDir}";
}
if (!is_dir($logDir) || !is_writable($logDir)) {
    $dirErrors[] = "日志目录不可写: {$logDir}";
}
$dirReady = $dirErrors === [];

// ==================== 日志 ====================

/**
 * 记录一次检测请求。
 *
 * @param array<string, mixed> $stats
 */
function dmm_log_request(array $config, string $action, array $stats, bool $cached, int $durationMs): void
{
    if (empty($config['log']['enabled'])) {
        return;
    }

    $logDir = $config['log']['dir'];
    if (!is_dir($logDir) || !is_writable($logDir)) {
        return;
    }

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '-');
    $ua = preg_replace('/[\r\n\t]+/', ' ', $ua) ?? '-';

    $line = sprintf(
        "%s | %s | %s | %dms | total=%d ok=%d slow=%d err=%d | %s | %s\n",
        date('Y-m-d H:i:s'),
        $action,
        $cached ? 'HIT' : 'MISS',
        $durationMs,
        $stats['total'] ?? 0,
        ($stats['fast'] ?? 0) + ($stats['fair'] ?? 0),
        ($stats['slow'] ?? 0) + ($stats['very_slow'] ?? 0),
        $stats['error'] ?? 0,
        dmm_client_ip($config),
        substr($ua, 0, 120)
    );

    @file_put_contents(
        $logDir . 'access_' . date('Y-m-d') . '.log',
        $line,
        FILE_APPEND | LOCK_EX
    );
}

/**
 * 清理过期日志。
 */
function dmm_gc_logs(array $config): void
{
    $retention = (int) ($config['log']['retention_days'] ?? 0);
    if ($retention <= 0) {
        return;
    }

    $cutoff = time() - $retention * 86400;
    $files = glob($config['log']['dir'] . 'access_*.log');
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
 * 引导：CORS / 限流 / 路由
 * ===================================================================== */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowList = $config['cors_origins'];
if ($origin !== '' && in_array($origin, $allowList, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
$clientIp = dmm_client_ip($config);

// ---- 健康检查走最轻路径，供容器探针高频调用，不受限流影响 ----
if ($action === 'health' || $action === 'version') {
    $cacheWritable = is_dir($cacheDir) && is_writable($cacheDir);
    $logWritable = is_dir($logDir) && is_writable($logDir);
    $curlAvailable = function_exists('curl_init');
    $curlMulti = function_exists('curl_multi_init');

    if ($action === 'version') {
        dmm_json([
            'success' => true,
            'name' => $config['app']['name'],
            'version' => $config['app']['version'],
            'php' => PHP_VERSION,
            'services' => count($config['services']),
            'timestamp' => date('c'),
        ]);
    }

    $healthy = $cacheWritable && $logWritable && $curlAvailable && $curlMulti && $dirReady;

    dmm_json([
        'success' => $healthy,
        'status' => $healthy ? 'healthy' : 'degraded',
        'version' => $config['app']['version'],
        'checks' => [
            'cache_writable' => $cacheWritable,
            'log_writable' => $logWritable,
            'curl_available' => $curlAvailable,
            'curl_multi_available' => $curlMulti,
            'ca_bundle' => dmm_resolve_ca_bundle($config),
            'services_count' => count($config['services']),
            'php_version' => PHP_VERSION,
        ],
        'errors' => $dirErrors,
        'timestamp' => date('c'),
    ], $healthy ? 200 : 503);
}

// ---- 限流 ----
$rate = dmm_rate_limit($config, $cacheDir, $clientIp);
if (!$rate['allowed']) {
    header('Retry-After: ' . $rate['retry_after']);
    dmm_json([
        'success' => false,
        'error' => '请求过于频繁，请稍后再试',
        'errorCode' => 'RATE_LIMITED',
        'retry_after' => $rate['retry_after'],
    ], 429);
}

header('X-RateLimit-Remaining: ' . max(0, (int) $rate['remaining']));

if ($dirErrors !== [] && !in_array($action, ['get_services'], true)) {
    dmm_json([
        'success' => false,
        'error' => '数据目录不可写',
        'errorCode' => 'E_DATA_DIR',
        'errors' => $dirErrors,
    ], 500);
}

try {
    switch ($action) {
        /* ---------------- 服务列表 ---------------- */
        case 'get_services':
            dmm_json([
                'success' => true,
                'data' => dmm_public_services($config['services']),
                'total' => count($config['services']),
                'app' => [
                    'name' => $config['app']['name'],
                    'version' => $config['app']['version'],
                    'site_url' => $config['app']['site_url'],
                ],
                'probe' => [
                    'engine' => 'registry-handshake',
                    'description' => '由本服务器直接对镜像源的 /v2/ 端点发起握手，200/401/403 均视为可用',
                    'timeout' => $config['probe']['timeout'],
                ],
                'thresholds' => $config['thresholds'],
                'cache' => [
                    'duration' => (int) $config['cache']['duration'],
                ],
                'timestamp' => date('c'),
            ]);
            break;

        /* ---------------- 检测单个服务 ---------------- */
        case 'check_service':
            if ($method !== 'POST') {
                dmm_json([
                    'success' => false,
                    'error' => 'check_service 仅支持 POST',
                    'errorCode' => 'METHOD_NOT_ALLOWED',
                ], 405);
            }

            $raw = file_get_contents('php://input');
            if ($raw === false || $raw === '') {
                dmm_json([
                    'success' => false,
                    'error' => '请求体为空，需要 JSON: {"url": "https://..."}',
                    'errorCode' => 'EMPTY_BODY',
                ], 400);
            }

            $input = json_decode($raw, true);
            if (!is_array($input) || empty($input['url']) || !is_string($input['url'])) {
                dmm_json([
                    'success' => false,
                    'error' => '缺少 url 参数',
                    'errorCode' => 'INVALID_INPUT',
                ], 400);
            }

            $requestedUrl = rtrim(trim($input['url']), '/');

            // 只允许检测服务列表中已登记的地址（防止被当作 SSRF 跳板）
            $matched = null;
            foreach ($config['services'] as $service) {
                if (rtrim($service['url'], '/') === $requestedUrl) {
                    $matched = $service;
                    break;
                }
            }

            if ($matched === null) {
                dmm_json([
                    'success' => false,
                    'error' => '该地址不在受监控的服务列表中',
                    'errorCode' => 'URL_NOT_ALLOWED',
                    'allowed' => array_column($config['services'], 'url'),
                ], 403);
            }

            $results = dmm_probe_services([$matched], $config);
            $result = $results[0] ?? dmm_probe_failure($matched, '探测未返回结果');

            dmm_log_request($config, 'check_service', [
                'total' => 1,
                'fast' => $result['status'] === 'fast' ? 1 : 0,
                'fair' => $result['status'] === 'fair' ? 1 : 0,
                'slow' => $result['status'] === 'slow' ? 1 : 0,
                'very_slow' => $result['status'] === 'very_slow' ? 1 : 0,
                'error' => $result['status'] === 'error' ? 1 : 0,
            ], false, (int) $result['responseTime']);

            dmm_json(['success' => true, 'data' => $result, 'timestamp' => date('c')]);
            break;

        /* ---------------- 批量检测 ---------------- */
        case 'check_all':
        case 'quick_check':
            $isQuick = ($action === 'quick_check');
            // quick_check 只检测前 6 个（覆盖主流公网镜像源），显著降低探测开销
            $subset = $isQuick
                ? array_slice($config['services'], 0, (int) dmm_env('DMM_QUICK_LIMIT', 6))
                : $config['services'];

            $cacheKey = $action . ':' . count($subset) . ':' . $config['app']['version'];
            $force = isset($_GET['force']) && $_GET['force'] !== '0';

            if (!$force) {
                $cached = dmm_cache_get($cacheKey, $config);
                if ($cached !== null) {
                    dmm_log_request($config, $action, $cached['stats'] ?? [], true, 0);
                    dmm_json($cached);
                }
            }

            $start = microtime(true);
            $results = dmm_probe_services($subset, $config);
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $stats = dmm_summarize($results);

            $response = [
                'success' => true,
                'data' => $results,
                'stats' => $stats,
                'cached' => false,
                'check_time_ms' => $durationMs,
                'engine' => 'registry-handshake',
                'scope' => $isQuick ? 'quick' : 'all',
                'timestamp' => date('c'),
            ];

            dmm_cache_set($cacheKey, $response, $config);
            dmm_log_request($config, $action, $stats, false, $durationMs);
            dmm_gc_logs($config);
            dmm_gc_rate_files($cacheDir, (int) $config['rate_limit']['window'], (int) $config['rate_limit']['block_duration']);

            dmm_json($response);
            break;

        /* ---------------- 兜底 ---------------- */
        default:
            dmm_json([
                'success' => false,
                'error' => '未知的 action',
                'errorCode' => 'UNKNOWN_ACTION',
                'available_actions' => [
                    'get_services', 'check_all', 'quick_check', 'check_service', 'health', 'version',
                ],
            ], 400);
    }
} catch (Throwable $e) {
    error_log(sprintf(
        '[docker-mirror-monitor] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    dmm_json([
        'success' => false,
        'error' => '服务器内部错误',
        'errorCode' => 'INTERNAL_ERROR',
        'detail' => !empty($config['app']['debug']) ? $e->getMessage() : null,
    ], 500);
}
