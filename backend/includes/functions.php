<?php
/**
 * 核心功能函数
 */

/**
 * 检查Docker镜像服务状态
 * @param string $url 镜像服务URL
 * @param int $timeout 超时时间（秒）
 * @return array 检测结果
 */
function checkDockerService($url, $timeout = 10) {
    $startTime = microtime(true);
    $result = [
        'url' => $url,
        'status' => 'error',
        'responseTime' => 0,
        'httpCode' => 0,
        'error' => '',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    try {
        // 检测URL格式
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL format');
        }
        
        // 构建检测URL - 尝试访问Docker Registry API v2端点
        $checkUrl = rtrim($url, '/') . '/v2/';
        
        // 初始化cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $checkUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Docker-Monitor/1.0 (Health Check)',
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => true, // 只获取头部信息
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);
        
        // 执行请求
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        // 计算响应时间
        $responseTime = round((microtime(true) - $startTime) * 1000); // 毫秒
        
        $result['responseTime'] = $responseTime;
        $result['httpCode'] = $httpCode;
        
        if ($error) {
            throw new Exception("cURL Error: $error");
        }
        
        // 判断服务状态
        // Docker Registry API v2 通常返回 200, 401, 或 404
        if (in_array($httpCode, [200, 401, 404])) {
            if ($responseTime > 5000) {
                $result['status'] = 'timeout';
            } elseif ($responseTime > 2000) {
                $result['status'] = 'slow';
            } else {
                $result['status'] = 'healthy';
            }
        } else {
            $result['status'] = 'error';
            $result['error'] = "HTTP $httpCode";
        }
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $result['responseTime'] = round((microtime(true) - $startTime) * 1000);
    }
    
    return $result;
}

/**
 * 批量检查多个服务
 * @param array $services 服务列表
 * @param int $timeout 超时时间
 * @return array 检测结果
 */
function checkMultipleServices($services, $timeout = 10) {
    $results = [];
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    
    // 初始化所有cURL句柄
    foreach ($services as $index => $service) {
        $url = $service['url'] ?? '';
        if (empty($url)) continue;
        
        $checkUrl = rtrim($url, '/') . '/v2/';
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $checkUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Docker-Monitor/1.0 (Health Check)',
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);
        
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$index] = [
            'handle' => $ch,
            'service' => $service,
            'startTime' => microtime(true)
        ];
    }
    
    // 执行所有请求
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);
    
    // 处理结果
    foreach ($curlHandles as $index => $data) {
        $ch = $data['handle'];
        $service = $data['service'];
        $startTime = $data['startTime'];
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        $result = [
            'url' => $service['url'],
            'name' => $service['name'] ?? '',
            'provider' => $service['provider'] ?? '',
            'status' => 'error',
            'responseTime' => $responseTime,
            'httpCode' => $httpCode,
            'error' => $error,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // 判断状态
        if (empty($error) && in_array($httpCode, [200, 401, 404])) {
            if ($responseTime > 5000) {
                $result['status'] = 'timeout';
            } elseif ($responseTime > 2000) {
                $result['status'] = 'slow';
            } else {
                $result['status'] = 'healthy';
            }
        } else {
            $result['error'] = $error ?: "HTTP $httpCode";
        }
        
        $results[] = $result;
        
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($multiHandle);
    
    return $results;
}

/**
 * 记录日志
 * @param string $message 日志消息
 * @param string $level 日志级别
 */
function logMessage($message, $level = 'INFO') {
    $logFile = __DIR__ . '/../logs/monitor.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * 验证请求来源
 * @return bool
 */
function validateRequest() {
    // 简单的请求验证，可以根据需要扩展
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 阻止明显的恶意请求
    $blockedAgents = ['bot', 'crawler', 'spider'];
    foreach ($blockedAgents as $blocked) {
        if (stripos($userAgent, $blocked) !== false) {
            return false;
        }
    }
    
    return true;
}

/**
 * 返回JSON响应
 * @param mixed $data 响应数据
 * @param int $code HTTP状态码
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * 返回错误响应
 * @param string $message 错误消息
 * @param int $code HTTP状态码
 */
function errorResponse($message, $code = 400) {
    jsonResponse([
        'success' => false,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ], $code);
}
?>
