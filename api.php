<?php
/**
 * Docker镜像加速服务监控 - 纯ping API版
 * 仅使用xxapi.cn的ping接口检测网络延迟
 * 使用方法：
 * GET  /api.php?action=check_all - 检测所有服务
 * GET  /api.php?action=quick_check - 快速检测（前10个最快的）
 * POST /api.php?action=check_service - 检测单个服务 {"url": "..."}
 */

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Shanghai');

// 性能优化设置
ini_set('max_execution_time', 20);
ini_set('memory_limit', '64M');

// CORS设置
$allowed_origins = [
    'https://docker.mcya.cn',
    'http://docker.mcya.cn',
    'http://localhost:3000',
    'http://localhost:8000'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header('Content-Type: application/json; charset=utf-8');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Docker服务配置 - 仅保留可用的镜像源
$dockerServices = [
    ['name' => '中科大镜像站', 'url' => 'https://docker.mirrors.ustc.edu.cn', 'provider' => 'USTC'],
    ['name' => '阿里云镜像', 'url' => 'https://registry.cn-hangzhou.aliyuncs.com', 'provider' => '阿里云'],
    ['name' => '腾讯云镜像', 'url' => 'https://mirror.ccs.tencentyun.com', 'provider' => '腾讯云'],
    ['name' => '华为云镜像', 'url' => 'https://swr.cn-north-1.myhuaweicloud.com', 'provider' => '华为云'],
    ['name' => '上海交大镜像', 'url' => 'https://docker.mirrors.sjtug.sjtu.edu.cn', 'provider' => '上海交大'],
    ['name' => '南京大学镜像', 'url' => 'https://docker.nju.edu.cn', 'provider' => '南京大学'],
    ['name' => '毫秒镜像', 'url' => 'https://docker.1ms.run', 'provider' => '木雷坞'],
    ['name' => '1Panel镜像', 'url' => 'https://docker.1panel.live', 'provider' => '1Panel'],
    ['name' => '耗子面板', 'url' => 'https://hub.rat.dev', 'provider' => '耗子面板'],
    ['name' => 'DockerProxy', 'url' => 'https://dockerproxy.net', 'provider' => 'DockerProxy'],
    ['name' => '科技lion', 'url' => 'https://docker.kejilion.pro', 'provider' => '科技lion'],
    ['name' => 'atomhub', 'url' => 'https://atomhub.openatom.cn', 'provider' => '开放原子'],
    ['name' => 'Docker Proxy', 'url' => 'https://dockerpull.com', 'provider' => 'DockerPull'],
    ['name' => 'Docker Hub 官方', 'url' => 'https://hub.docker.com', 'provider' => 'Docker官方']
];

// 缓存配置
$cacheDir = __DIR__ . '/cache/';
$cacheDuration = 600; // 10分钟缓存

// 日志配置
$logDir = __DIR__ . '/logs/';

// 创建目录
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// 基于响应时间的超时配置
$quickTimeout = 2;    // 快速检测2秒
$defaultTimeout = 3;  // 默认超时3秒
$connectTimeout = 1;  // 连接超时1秒

/**
 * 检查单个Docker服务 - 仅使用ping API
 * 状态分级: fast(<100ms) | fair(100-200ms) | slow(>200ms) | error(无响应)
 */
function checkDockerService($url, $timeout = 3) {
    $startTime = microtime(true);
    $result = [
        'url' => $url,
        'status' => 'error',
        'responseTime' => 0,
        'error' => '',
        'method' => '',
        'server' => '',
        'ip' => '',
        'timestamp' => date('Y-m-d H:i:s')
    ];

    try {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL');
        }

        // 从URL中提取主机名
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        if (empty($host)) {
            throw new Exception('Invalid host');
        }

        // 使用第三方ping API检测
        $pingResult = checkWithPingAPI($host, $timeout);

        // 添加调试信息
        if (isset($_GET['debug'])) {
            $result['debug_ping'] = $pingResult;
        }

        // 只要ping API有响应时间，就使用它
        if ($pingResult['responseTime'] > 0) {
            $result['responseTime'] = $pingResult['responseTime'];
            $result['method'] = $pingResult['success'] ? 'Ping API' : 'Ping API (Partial)';
            $result['server'] = $pingResult['server'];
            $result['ip'] = $pingResult['ip'];
            if (!$pingResult['success']) {
                $result['error'] = $pingResult['error'];
            }

            // 完全基于响应时间判断状态 - 映射为前端兼容格式
            if ($pingResult['responseTime'] > 2000) {
                $result['status'] = 'error';
            } elseif ($pingResult['responseTime'] > 1000) {
                $result['status'] = 'slow';
            } elseif ($pingResult['responseTime'] > 500) {
                $result['status'] = 'fair';
            } else {
                $result['status'] = 'fast';
            }
        } else {
            // ping API完全失败
            $result['error'] = $pingResult['error'] ?: 'Ping API failed';
            $result['responseTime'] = round((microtime(true) - $startTime) * 1000);
            $result['status'] = 'error';
        }

    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $result['responseTime'] = round((microtime(true) - $startTime) * 1000);
    }

    return $result;
}

/**
 * 使用第三方ping API检测
 */
function checkWithPingAPI($host, $timeout = 3) {
    $result = [
        'success' => false,
        'responseTime' => 0,
        'server' => '',
        'ip' => '',
        'error' => ''
    ];

    $startTime = microtime(true);

    try {
        $apiUrl = 'https://v2.xxapi.cn/api/ping?url=' . urlencode($host);
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout + 2, // 给API额外时间
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Docker-Monitor/4.0',
            CURLOPT_HTTPHEADER => [
                'User-Agent: xiaoxiaoapi/1.0.0 (https://xxapi.cn)'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $totalTime = round((microtime(true) - $startTime) * 1000);

        if ($error) {
            $result['error'] = "API Error: $error";
            return $result;
        }

        if ($httpCode !== 200) {
            $result['error'] = "API HTTP Error: $httpCode";
            return $result;
        }

        $data = json_decode($response, true);
        if (!$data) {
            $result['error'] = "Invalid API response";
            return $result;
        }

        if ($data['code'] === 200 && isset($data['data'])) {
            $result['success'] = true;

            // 解析响应时间 (去掉"ms"后缀)
            $timeStr = $data['data']['time'] ?? '0ms';
            $result['responseTime'] = (int)str_replace('ms', '', $timeStr);

            $result['server'] = $data['data']['server'] ?? '';
            $result['ip'] = $data['data']['ip'] ?? '';
        } else {
            // API返回错误，但尝试提取可用信息
            $result['error'] = $data['msg'] ?? 'API request failed';

            // 即使失败，也尝试提取数据
            if (isset($data['data'])) {
                $timeStr = $data['data']['time'] ?? '0ms';
                $extractedTime = (int)str_replace('ms', '', $timeStr);
                if ($extractedTime > 0) {
                    $result['responseTime'] = $extractedTime;
                    $result['server'] = $data['data']['server'] ?? '';
                    $result['ip'] = $data['data']['ip'] ?? '';
                    // 不设置success=true，但保留数据
                }
            }
        }

    } catch (Exception $e) {
        $result['error'] = 'Ping API error: ' . $e->getMessage();
    }

    return $result;
}





/**
 * 批量检查服务 - 仅使用ping API
 */
function checkMultipleServices($services, $timeout = 3) {
    $results = [];

    foreach ($services as $service) {
        $serviceResult = checkDockerService($service['url'], $timeout);

        // 添加服务信息
        $serviceResult['name'] = $service['name'];
        $serviceResult['provider'] = $service['provider'];

        $results[] = $serviceResult;
    }

    return $results;
}

/**
 * 快速检查服务（检查前10个镜像站）
 */
function quickCheckServices($services, $timeout = 2) {
    // 选择前10个镜像站（已按响应时间排序）
    $quickServices = array_slice($services, 0, 10);
    return checkMultipleServices($quickServices, $timeout);
}

/**
 * 获取缓存
 */
function getCache($key, $cacheDir, $duration) {
    $cacheFile = $cacheDir . md5($key) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $duration) {
        return json_decode(file_get_contents($cacheFile), true);
    }
    return null;
}

/**
 * 设置缓存
 */
function setCache($key, $data, $cacheDir) {
    $cacheFile = $cacheDir . md5($key) . '.json';
    file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * 记录性能日志
 */
function logPerformance($action, $responseTime, $successRate, $servicesCount, $cached = false) {
    global $logDir;

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $cacheStatus = $cached ? 'HIT' : 'MISS';

    $logEntry = sprintf(
        "%s | %s | %s | %dms | %.1f%% | %d服务 | %s | %s\n",
        date('Y-m-d H:i:s'),
        $action,
        $cacheStatus,
        $responseTime,
        $successRate,
        $servicesCount,
        $ip,
        substr($userAgent, 0, 50)
    );

    $logFile = $logDir . 'performance_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * 返回JSON响应
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// 主逻辑
try {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'check_service':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                jsonResponse(['success' => false, 'error' => 'Only POST method allowed'], 405);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || empty($input['url'])) {
                jsonResponse(['success' => false, 'error' => 'URL parameter required'], 400);
            }

            $result = checkDockerService($input['url'], $input['timeout'] ?? $defaultTimeout);
            jsonResponse(['success' => true, 'data' => $result]);
            break;

        case 'quick_check':
            // 快速检测模式 - 检查前10个服务
            $cacheKey = 'quick_' . date('Y-m-d-H-i');
            $cached = getCache($cacheKey, $cacheDir, $cacheDuration);
            if ($cached && !isset($_GET['force'])) {
                $cached['cached'] = true;
                jsonResponse($cached);
            }

            $startTime = microtime(true);
            $results = quickCheckServices($dockerServices, $quickTimeout);
            $totalTime = round((microtime(true) - $startTime) * 1000);

            $stats = ['total' => 0, 'fast' => 0, 'fair' => 0, 'slow' => 0, 'error' => 0];
            foreach ($results as $result) {
                $stats['total']++;
                $status = $result['status'];
                if (isset($stats[$status])) {
                    $stats[$status]++;
                }
            }

            $response = [
                'success' => true,
                'data' => $results,
                'stats' => $stats,
                'cached' => false,
                'check_time_ms' => $totalTime,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // 记录性能日志
            $successCount = ($stats['fast'] ?? 0) + ($stats['fair'] ?? 0) + ($stats['slow'] ?? 0);
            $successRate = $stats['total'] > 0 ? ($successCount / $stats['total']) * 100 : 0;
            logPerformance('quick_check', $totalTime, $successRate, $stats['total'], false);

            setCache($cacheKey, $response, $cacheDir);
            jsonResponse($response);
            break;

        case 'check_all':
            // 完整检测模式
            $cacheKey = 'all_' . date('Y-m-d-H-i');
            $cached = getCache($cacheKey, $cacheDir, $cacheDuration);
            if ($cached && !isset($_GET['force'])) {
                $cached['cached'] = true;
                jsonResponse($cached);
            }

            $startTime = microtime(true);
            $results = checkMultipleServices($dockerServices, $defaultTimeout);
            $totalTime = round((microtime(true) - $startTime) * 1000);

            $stats = ['total' => 0, 'fast' => 0, 'fair' => 0, 'slow' => 0, 'error' => 0];
            foreach ($results as $result) {
                $stats['total']++;
                $stats[$result['status']]++;
            }

            $response = [
                'success' => true,
                'data' => $results,
                'stats' => $stats,
                'cached' => false,
                'check_time_ms' => $totalTime,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // 记录性能日志
            $successCount = ($stats['fast'] ?? 0) + ($stats['fair'] ?? 0) + ($stats['slow'] ?? 0);
            $successRate = $stats['total'] > 0 ? ($successCount / $stats['total']) * 100 : 0;
            logPerformance('check_all', $totalTime, $successRate, $stats['total'], false);

            setCache($cacheKey, $response, $cacheDir);
            jsonResponse($response);
            break;

        default:
            jsonResponse([
                'success' => false,
                'error' => 'Invalid action',
                'available_actions' => ['check_service', 'check_all', 'quick_check'],
                'usage' => [
                    'GET /api.php?action=quick_check - 快速检测前10个服务',
                    'GET /api.php?action=check_all - 检测所有服务',
                    'POST /api.php?action=check_service - 检测单个服务'
                ]
            ], 400);
    }

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    jsonResponse(['success' => false, 'error' => $e->getMessage(), 'debug' => [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]], 500);
} catch (Error $e) {
    error_log("PHP Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    jsonResponse(['success' => false, 'error' => 'PHP Error: ' . $e->getMessage(), 'debug' => [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]], 500);
}
?>
