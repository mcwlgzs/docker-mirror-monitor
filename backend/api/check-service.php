<?php
/**
 * 单个服务检测API
 * 用法: POST /api/check-service.php
 * 参数: {"url": "https://docker.mirrors.ustc.edu.cn"}
 */

require_once '../includes/cors.php';
require_once '../includes/functions.php';
require_once 'config.php';

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Only POST method allowed', 405);
}

// 验证请求来源
if (!validateRequest()) {
    errorResponse('Request blocked', 403);
}

try {
    // 获取请求数据
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        errorResponse('Invalid JSON format');
    }
    
    // 验证必需参数
    if (empty($data['url'])) {
        errorResponse('URL parameter is required');
    }
    
    $url = trim($data['url']);
    $timeout = isset($data['timeout']) ? (int)$data['timeout'] : DEFAULT_TIMEOUT;
    
    // 限制超时时间
    if ($timeout < 1 || $timeout > 30) {
        $timeout = DEFAULT_TIMEOUT;
    }
    
    // 记录请求日志
    logMessage("Checking service: $url", 'INFO');
    
    // 检查服务状态
    $result = checkDockerService($url, $timeout);
    
    // 返回结果
    jsonResponse([
        'success' => true,
        'data' => $result,
        'api_version' => API_VERSION,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    logMessage("Error checking service: " . $e->getMessage(), 'ERROR');
    errorResponse('Internal server error: ' . $e->getMessage(), 500);
}
?>
