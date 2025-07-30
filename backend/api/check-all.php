<?php
/**
 * 批量检测所有服务API
 * 用法: GET /api/check-all.php 或 POST /api/check-all.php
 */

require_once '../includes/cors.php';
require_once '../includes/functions.php';
require_once 'config.php';

// 验证请求来源
if (!validateRequest()) {
    errorResponse('Request blocked', 403);
}

try {
    $timeout = DEFAULT_TIMEOUT;
    $useCache = true;
    
    // 处理请求参数
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if ($data) {
            $timeout = isset($data['timeout']) ? (int)$data['timeout'] : DEFAULT_TIMEOUT;
            $useCache = isset($data['use_cache']) ? (bool)$data['use_cache'] : true;
        }
    } else {
        $timeout = isset($_GET['timeout']) ? (int)$_GET['timeout'] : DEFAULT_TIMEOUT;
        $useCache = isset($_GET['no_cache']) ? false : true;
    }
    
    // 限制超时时间
    if ($timeout < 1 || $timeout > 30) {
        $timeout = DEFAULT_TIMEOUT;
    }
    
    // 缓存文件路径
    $cacheFile = $cacheConfig['directory'] . 'all_services_' . md5(serialize($dockerServices)) . '.json';
    
    // 检查缓存
    if ($useCache && $cacheConfig['enabled'] && file_exists($cacheFile)) {
        $cacheTime = filemtime($cacheFile);
        if (time() - $cacheTime < $cacheConfig['duration']) {
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            if ($cachedData) {
                logMessage("Returning cached results", 'INFO');
                
                jsonResponse([
                    'success' => true,
                    'data' => $cachedData['results'],
                    'cached' => true,
                    'cache_time' => date('Y-m-d H:i:s', $cacheTime),
                    'total_services' => count($cachedData['results']),
                    'api_version' => API_VERSION,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }
    
    // 记录请求日志
    logMessage("Checking all services (timeout: {$timeout}s)", 'INFO');
    
    // 限制并发检测数量
    $servicesToCheck = array_slice($dockerServices, 0, MAX_CONCURRENT_CHECKS);
    
    // 批量检测服务
    $startTime = microtime(true);
    $results = checkMultipleServices($servicesToCheck, $timeout);
    $totalTime = round((microtime(true) - $startTime) * 1000);
    
    // 统计结果
    $stats = [
        'total' => count($results),
        'healthy' => 0,
        'slow' => 0,
        'error' => 0,
        'timeout' => 0
    ];
    
    foreach ($results as $result) {
        $stats[$result['status']]++;
    }
    
    // 保存到缓存
    if ($cacheConfig['enabled']) {
        $cacheData = [
            'results' => $results,
            'stats' => $stats,
            'check_time' => $totalTime,
            'timestamp' => time()
        ];
        
        file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
    }
    
    logMessage("Checked {$stats['total']} services in {$totalTime}ms", 'INFO');
    
    // 返回结果
    jsonResponse([
        'success' => true,
        'data' => $results,
        'stats' => $stats,
        'cached' => false,
        'check_time_ms' => $totalTime,
        'total_services' => count($results),
        'api_version' => API_VERSION,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    logMessage("Error checking all services: " . $e->getMessage(), 'ERROR');
    errorResponse('Internal server error: ' . $e->getMessage(), 500);
}
?>
