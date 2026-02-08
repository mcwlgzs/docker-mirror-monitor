<?php
/**
 * Docker镜像加速服务监控 - API
 *
 * 端点：
 * GET  /api.php?action=check_all     - 检测所有服务
 * GET  /api.php?action=quick_check   - 快速检测（前10个）
 * POST /api.php?action=check_service - 检测单个服务 {"url": "..."}
 * GET  /api.php?action=get_services  - 获取服务列表
 * GET  /api.php?action=health        - 健康检查
 */

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Shanghai');

// 性能优化设置
ini_set('max_execution_time', 30);
ini_set('memory_limit', '64M');

// 加载配置
$config = require __DIR__ . '/config.php';

$dockerServices = $config['services'];
$cacheDir = $config['cache']['dir'];
$cacheDuration = $config['cache']['duration'];
$logDir = $config['log']['dir'];
$quickTimeout = $config['timeout']['quick'];
$defaultTimeout = $config['timeout']['default'];
$connectTimeout = $config['timeout']['connect'];
$thresholds = $config['thresholds'];

// CORS设置
$allowed_origins = $config['cors_origins'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // 不匹配时不设置通配符，仅允许白名单来源
    header("Access-Control-Allow-Origin: " . $allowed_origins[0]);
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

// 创建目录
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ==================== 速率限制 ====================

/**
 * 简单的基于文件的速率限制
 */
function checkRateLimit($config) {
    if (!$config['rate_limit']['enabled']) {
        return true;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = $config['cache']['dir'];
    $rateFile = $cacheDir . 'rate_' . md5($ip) . '.json';
    $maxRequests = $config['rate_limit']['max_requests'];
    $window = $config['rate_limit']['window'];
    $now = time();

    $data = ['requests' => [], 'blocked_until' => 0];
    if (file_exists($rateFile)) {
        $data = json_decode(file_get_contents($rateFile), true) ?: $data;
    }

    // 清理过期记录
    $data['requests'] = array_filter($data['requests'], function ($t) use ($now, $window) {
        return ($now - $t) < $window;
    });

    if (count($data['requests']) >= $maxRequests) {
        return false;
    }

    $data['requests'][] = $now;
    file_put_contents($rateFile, json_encode($data), LOCK_EX);
    return true;
}

// ==================== 检测函数 ====================

/**
 * 使用第三方ping API检测单个主机
 */
function checkWithPingAPI($host, $timeout = 3) {
    $result = [
        'success' => false,
        'responseTime' => 0,
        'server' => '',
        'ip' => '',
        'error' => ''
    ];

    try {
        $apiUrl = 'https://v2.xxapi.cn/api/ping?url=' . urlencode($host);
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout + 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Docker-Monitor/5.0',
            CURLOPT_HTTPHEADER => [
                'User-Agent: xiaoxiaoapi/1.0.0 (https://xxapi.cn)'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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
            $timeStr = $data['data']['time'] ?? '0ms';
            $result['responseTime'] = (int)str_replace('ms', '', $timeStr);
            $result['server'] = $data['data']['server'] ?? '';
            $result['ip'] = $data['data']['ip'] ?? '';
        } else {
            $result['error'] = $data['msg'] ?? 'API request failed';
            if (isset($data['data'])) {
                $timeStr = $data['data']['time'] ?? '0ms';
                $extractedTime = (int)str_replace('ms', '', $timeStr);
                if ($extractedTime > 0) {
                    $result['responseTime'] = $extractedTime;
                    $result['server'] = $data['data']['server'] ?? '';
                    $result['ip'] = $data['data']['ip'] ?? '';
                }
            }
        }
    } catch (Exception $e) {
        $result['error'] = 'Ping API error: ' . $e->getMessage();
    }

    return $result;
}

/**
 * 备用检测方案：直接 cURL HEAD 请求
 */
function checkWithDirectCurl($url, $timeout = 3) {
    $result = [
        'success' => false,
        'responseTime' => 0,
        'server' => '',
        'ip' => '',
        'error' => ''
    ];

    $startTime = microtime(true);

    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Docker-Monitor/5.0',
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsed = round((microtime(true) - $startTime) * 1000);

        if ($error) {
            $result['error'] = $error;
            $result['responseTime'] = $elapsed;
            return $result;
        }

        $result['success'] = ($httpCode > 0 && $httpCode < 500);
        $result['responseTime'] = $elapsed;
        $result['ip'] = $primaryIp ?: '';
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $result['responseTime'] = round((microtime(true) - $startTime) * 1000);
    }

    return $result;
}

/**
 * 检查单个Docker服务（带备用方案）
 */
function checkDockerService($url, $timeout = 3) {
    global $thresholds;

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

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $result['error'] = 'Invalid URL';
        return $result;
    }

    $parsedUrl = parse_url($url);
    $host = $parsedUrl['host'] ?? '';
    if (empty($host)) {
        $result['error'] = 'Invalid host';
        return $result;
    }

    // 主检测：ping API
    $pingResult = checkWithPingAPI($host, $timeout);

    if ($pingResult['responseTime'] > 0) {
        $result['responseTime'] = $pingResult['responseTime'];
        $result['method'] = 'Ping API';
        $result['server'] = $pingResult['server'];
        $result['ip'] = $pingResult['ip'];
        $result['error'] = $pingResult['error'] ?: '';
    } else {
        // 备用检测：直接 cURL
        $directResult = checkWithDirectCurl($url, $timeout);
        $result['responseTime'] = $directResult['responseTime'];
        $result['method'] = 'Direct cURL';
        $result['ip'] = $directResult['ip'];
        $result['error'] = $directResult['error'] ?: '';

        if (!$directResult['success'] && $result['responseTime'] <= 0) {
            $result['status'] = 'error';
            return $result;
        }
    }

    // 基于响应时间判断状态
    if ($result['responseTime'] > $thresholds['slow']) {
        $result['status'] = 'error';
    } elseif ($result['responseTime'] > $thresholds['fair']) {
        $result['status'] = 'slow';
    } elseif ($result['responseTime'] > $thresholds['fast']) {
        $result['status'] = 'fair';
    } else {
        $result['status'] = 'fast';
    }

    return $result;
}

/**
 * 使用 curl_multi 并发检查多个服务
 */
function checkMultipleServicesConcurrent($services, $timeout = 3) {
    global $thresholds;

    $mh = curl_multi_init();
    $handles = [];

    // 为每个服务创建 cURL handle（ping API）
    foreach ($services as $i => $service) {
        $parsedUrl = parse_url($service['url']);
        $host = $parsedUrl['host'] ?? '';
        if (empty($host)) continue;

        $apiUrl = 'https://v2.xxapi.cn/api/ping?url=' . urlencode($host);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout + 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Docker-Monitor/5.0',
            CURLOPT_HTTPHEADER => [
                'User-Agent: xiaoxiaoapi/1.0.0 (https://xxapi.cn)'
            ]
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[$i] = ['ch' => $ch, 'service' => $service, 'host' => $host];
    }

    // 并发执行
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    // 收集结果
    $results = [];
    foreach ($handles as $i => $item) {
        $ch = $item['ch'];
        $service = $item['service'];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $serviceResult = [
            'url' => $service['url'],
            'name' => $service['name'],
            'provider' => $service['provider'],
            'status' => 'error',
            'responseTime' => 0,
            'error' => '',
            'method' => 'Ping API (concurrent)',
            'server' => '',
            'ip' => '',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if (!$error && $httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && $data['code'] === 200 && isset($data['data'])) {
                $timeStr = $data['data']['time'] ?? '0ms';
                $rt = (int)str_replace('ms', '', $timeStr);
                $serviceResult['responseTime'] = $rt;
                $serviceResult['server'] = $data['data']['server'] ?? '';
                $serviceResult['ip'] = $data['data']['ip'] ?? '';

                if ($rt > $thresholds['slow']) {
                    $serviceResult['status'] = 'error';
                } elseif ($rt > $thresholds['fair']) {
                    $serviceResult['status'] = 'slow';
                } elseif ($rt > $thresholds['fast']) {
                    $serviceResult['status'] = 'fair';
                } else {
                    $serviceResult['status'] = 'fast';
                }
            } elseif ($data && isset($data['data'])) {
                $timeStr = $data['data']['time'] ?? '0ms';
                $rt = (int)str_replace('ms', '', $timeStr);
                if ($rt > 0) {
                    $serviceResult['responseTime'] = $rt;
                    $serviceResult['server'] = $data['data']['server'] ?? '';
                    $serviceResult['ip'] = $data['data']['ip'] ?? '';
                    if ($rt > $thresholds['slow']) {
                        $serviceResult['status'] = 'error';
                    } elseif ($rt > $thresholds['fair']) {
                        $serviceResult['status'] = 'slow';
                    } elseif ($rt > $thresholds['fast']) {
                        $serviceResult['status'] = 'fair';
                    } else {
                        $serviceResult['status'] = 'fast';
                    }
                }
                $serviceResult['error'] = $data['msg'] ?? '';
            } else {
                $serviceResult['error'] = 'Invalid API response';
            }
        } else {
            $serviceResult['error'] = $error ?: "HTTP $httpCode";
        }

        // 如果 ping API 完全失败，尝试直接 cURL 备用
        if ($serviceResult['status'] === 'error' && $serviceResult['responseTime'] <= 0) {
            $directResult = checkWithDirectCurl($service['url'], $timeout);
            if ($directResult['responseTime'] > 0) {
                $serviceResult['responseTime'] = $directResult['responseTime'];
                $serviceResult['method'] = 'Direct cURL (fallback)';
                $serviceResult['ip'] = $directResult['ip'];
                $serviceResult['error'] = $directResult['error'] ?: '';

                if ($directResult['success']) {
                    $rt = $directResult['responseTime'];
                    if ($rt > $thresholds['slow']) {
                        $serviceResult['status'] = 'error';
                    } elseif ($rt > $thresholds['fair']) {
                        $serviceResult['status'] = 'slow';
                    } elseif ($rt > $thresholds['fast']) {
                        $serviceResult['status'] = 'fair';
                    } else {
                        $serviceResult['status'] = 'fast';
                    }
                }
            }
        }

        $results[] = $serviceResult;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// ==================== 缓存函数 ====================

/**
 * 获取缓存（使用稳定的缓存 key）
 */
function getCache($key, $cacheDir, $duration) {
    $cacheFile = $cacheDir . md5($key) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $duration) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data) {
            $data['cache_time'] = date('Y-m-d H:i:s', filemtime($cacheFile));
            return $data;
        }
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

// ==================== 日志函数 ====================

/**
 * 记录性能日志（带输入清理）
 */
function logPerformance($action, $responseTime, $successRate, $servicesCount, $cached = false) {
    global $logDir;

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    // 清理 user agent 中的换行符和特殊字符
    $userAgent = preg_replace('/[\r\n\t]/', ' ', substr($userAgent, 0, 100));
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
        $userAgent
    );

    $logFile = $logDir . 'performance_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// ==================== 响应函数 ====================

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// ==================== 主逻辑 ====================

try {
    // 速率限制检查
    if (!checkRateLimit($config)) {
        jsonResponse([
            'success' => false,
            'error' => '请求过于频繁，请稍后再试',
            'retry_after' => $config['rate_limit']['window']
        ], 429);
    }

    $action = $_GET['action'] ?? '';

    switch ($action) {
        // 获取服务列表（前端从此获取，不再硬编码）
        case 'get_services':
            jsonResponse([
                'success' => true,
                'data' => array_map(function ($s) {
                    return [
                        'name' => $s['name'],
                        'url' => $s['url'],
                        'provider' => $s['provider'],
                        'description' => $s['description'],
                    ];
                }, $dockerServices),
                'total' => count($dockerServices),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        // 健康检查端点
        case 'health':
            $cacheWritable = is_writable($cacheDir);
            $logWritable = is_writable($logDir);
            $curlAvailable = function_exists('curl_init');

            $healthy = $cacheWritable && $logWritable && $curlAvailable;
            jsonResponse([
                'success' => true,
                'status' => $healthy ? 'healthy' : 'degraded',
                'checks' => [
                    'cache_writable' => $cacheWritable,
                    'log_writable' => $logWritable,
                    'curl_available' => $curlAvailable,
                    'php_version' => PHP_VERSION,
                    'services_count' => count($dockerServices),
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ], $healthy ? 200 : 503);
            break;

        // 检测单个服务
        case 'check_service':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                jsonResponse(['success' => false, 'error' => 'Only POST method allowed'], 405);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || empty($input['url'])) {
                jsonResponse(['success' => false, 'error' => 'URL parameter required'], 400);
            }

            // 验证 URL 是否在允许的服务列表中
            $allowedUrls = array_column($dockerServices, 'url');
            if (!in_array($input['url'], $allowedUrls)) {
                jsonResponse(['success' => false, 'error' => 'URL not in allowed service list'], 403);
            }

            $result = checkDockerService($input['url'], $input['timeout'] ?? $defaultTimeout);
            jsonResponse(['success' => true, 'data' => $result]);
            break;

        // 快速检测（前10个服务）
        case 'quick_check':
            $cacheKey = 'quick_check';
            $cached = getCache($cacheKey, $cacheDir, $cacheDuration);
            if ($cached && !isset($_GET['force'])) {
                $cached['cached'] = true;
                logPerformance('quick_check', 0, 0, 0, true);
                jsonResponse($cached);
            }

            $startTime = microtime(true);
            $quickServices = array_slice($dockerServices, 0, 10);
            $results = checkMultipleServicesConcurrent($quickServices, $quickTimeout);
            $totalTime = round((microtime(true) - $startTime) * 1000);

            $stats = ['total' => 0, 'fast' => 0, 'fair' => 0, 'slow' => 0, 'error' => 0];
            foreach ($results as $r) {
                $stats['total']++;
                if (isset($stats[$r['status']])) {
                    $stats[$r['status']]++;
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

            $successCount = $stats['fast'] + $stats['fair'] + $stats['slow'];
            $successRate = $stats['total'] > 0 ? ($successCount / $stats['total']) * 100 : 0;
            logPerformance('quick_check', $totalTime, $successRate, $stats['total'], false);

            setCache($cacheKey, $response, $cacheDir);
            jsonResponse($response);
            break;

        // 检测所有服务
        case 'check_all':
            $cacheKey = 'check_all';
            $cached = getCache($cacheKey, $cacheDir, $cacheDuration);
            if ($cached && !isset($_GET['force'])) {
                $cached['cached'] = true;
                logPerformance('check_all', 0, 0, 0, true);
                jsonResponse($cached);
            }

            $startTime = microtime(true);
            $results = checkMultipleServicesConcurrent($dockerServices, $defaultTimeout);
            $totalTime = round((microtime(true) - $startTime) * 1000);

            $stats = ['total' => 0, 'fast' => 0, 'fair' => 0, 'slow' => 0, 'error' => 0];
            foreach ($results as $r) {
                $stats['total']++;
                if (isset($stats[$r['status']])) {
                    $stats[$r['status']]++;
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

            $successCount = $stats['fast'] + $stats['fair'] + $stats['slow'];
            $successRate = $stats['total'] > 0 ? ($successCount / $stats['total']) * 100 : 0;
            logPerformance('check_all', $totalTime, $successRate, $stats['total'], false);

            setCache($cacheKey, $response, $cacheDir);
            jsonResponse($response);
            break;

        default:
            jsonResponse([
                'success' => false,
                'error' => 'Invalid action',
                'available_actions' => ['get_services', 'check_service', 'check_all', 'quick_check', 'health'],
            ], 400);
    }

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
} catch (Error $e) {
    error_log("PHP Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
