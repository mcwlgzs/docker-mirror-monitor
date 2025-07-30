<?php
/**
 * API 配置文件
 */

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0); // 生产环境设置为 0

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// API配置
define('API_VERSION', '1.0');
define('MAX_CONCURRENT_CHECKS', 50);
define('DEFAULT_TIMEOUT', 10);
define('CACHE_DURATION', 300); // 5分钟缓存

// Docker服务列表配置
$dockerServices = [
    [
        'name' => '中科大镜像站',
        'url' => 'https://docker.mirrors.ustc.edu.cn',
        'provider' => 'USTC',
        'description' => '中国科学技术大学开源软件镜像站',
        'category' => 'university'
    ],
    [
        'name' => '网易云镜像',
        'url' => 'https://hub-mirror.c.163.com',
        'provider' => '网易云',
        'description' => '网易云提供的 Docker Hub 镜像',
        'category' => 'commercial'
    ],
    [
        'name' => '阿里云镜像',
        'url' => 'https://registry.cn-hangzhou.aliyuncs.com',
        'provider' => '阿里云',
        'description' => '阿里云容器镜像服务',
        'category' => 'cloud'
    ],
    [
        'name' => '腾讯云镜像',
        'url' => 'https://mirror.ccs.tencentyun.com',
        'provider' => '腾讯云',
        'description' => '腾讯云容器镜像服务',
        'category' => 'cloud'
    ],
    [
        'name' => '华为云镜像',
        'url' => 'https://swr.cn-north-1.myhuaweicloud.com',
        'provider' => '华为云',
        'description' => '华为云软件开发生产线',
        'category' => 'cloud'
    ],
    [
        'name' => 'DaoCloud 镜像',
        'url' => 'https://f1361db2.m.daocloud.io',
        'provider' => 'DaoCloud',
        'description' => 'DaoCloud 提供的镜像加速服务',
        'category' => 'commercial'
    ],
    [
        'name' => '百度云镜像',
        'url' => 'https://mirror.baidubce.com',
        'provider' => '百度云',
        'description' => '百度智能云容器镜像服务',
        'category' => 'cloud'
    ],
    [
        'name' => '七牛云镜像',
        'url' => 'https://reg-mirror.qiniu.com',
        'provider' => '七牛云',
        'description' => '七牛云提供的 Docker 镜像加速',
        'category' => 'cloud'
    ],
    [
        'name' => '上海交大镜像',
        'url' => 'https://docker.mirrors.sjtug.sjtu.edu.cn',
        'provider' => '上海交大',
        'description' => '上海交通大学软件源镜像服务',
        'category' => 'university'
    ],
    [
        'name' => '清华大学镜像',
        'url' => 'https://mirrors.tuna.tsinghua.edu.cn/docker-ce',
        'provider' => '清华大学',
        'description' => '清华大学开源软件镜像站',
        'category' => 'university'
    ],
    [
        'name' => '南京大学镜像',
        'url' => 'https://docker.nju.edu.cn',
        'provider' => '南京大学',
        'description' => '南京大学开源镜像站',
        'category' => 'university'
    ],
    [
        'name' => '字节跳动镜像',
        'url' => 'https://cr.volcengine.com',
        'provider' => '字节跳动',
        'description' => '火山引擎容器镜像服务',
        'category' => 'cloud'
    ],
    [
        'name' => '京东云镜像',
        'url' => 'https://hub-mirror.jdcloud.com',
        'provider' => '京东云',
        'description' => '京东云容器镜像服务',
        'category' => 'cloud'
    ],
    [
        'name' => '又拍云镜像',
        'url' => 'https://docker.mirrors.upyun.com',
        'provider' => '又拍云',
        'description' => '又拍云 Docker 镜像加速',
        'category' => 'cloud'
    ],
    [
        'name' => 'Azure 中国镜像',
        'url' => 'https://dockerhub.azk8s.cn',
        'provider' => 'Azure',
        'description' => 'Microsoft Azure 中国镜像',
        'category' => 'cloud'
    ],
    [
        'name' => '中国官方镜像',
        'url' => 'https://registry.docker-cn.com',
        'provider' => 'Docker官方',
        'description' => 'Docker 官方中国镜像',
        'category' => 'official'
    ],
    [
        'name' => '毫秒镜像',
        'url' => 'https://docker.1ms.run',
        'provider' => '木雷坞',
        'description' => '毫秒镜像 CloudFlare 加速',
        'category' => 'community'
    ],
    [
        'name' => '1Panel 镜像',
        'url' => 'https://docker.1panel.live',
        'provider' => '1Panel',
        'description' => '1Panel CloudFlare 镜像源',
        'category' => 'community'
    ],
    [
        'name' => '耗子面板',
        'url' => 'https://hub.rat.dev',
        'provider' => '耗子面板',
        'description' => '耗子面板 CloudFlare 镜像',
        'category' => 'community'
    ],
    [
        'name' => '轩辕镜像',
        'url' => 'https://docker.xuanyuan.me',
        'provider' => '轩辕镜像',
        'description' => '轩辕镜像 CloudFlare 免费版',
        'category' => 'community'
    ],
    [
        'name' => 'DockerProxy',
        'url' => 'https://dockerproxy.net',
        'provider' => 'DockerProxy',
        'description' => 'DockerProxy Oracle CDN',
        'category' => 'community'
    ],
    [
        'name' => 'Fast360',
        'url' => 'https://hub.fast360.xyz',
        'provider' => 'Fast360',
        'description' => 'Fast360 Nginx 镜像源',
        'category' => 'community'
    ]
];

// 缓存配置
$cacheConfig = [
    'enabled' => true,
    'directory' => __DIR__ . '/../cache/',
    'duration' => CACHE_DURATION
];

// 日志配置
$logConfig = [
    'enabled' => true,
    'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
    'max_size' => 10 * 1024 * 1024, // 10MB
    'max_files' => 5
];

// 安全配置
$securityConfig = [
    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 100, // 每小时最大请求数
        'window' => 3600 // 时间窗口（秒）
    ],
    'allowed_ips' => [], // 空数组表示允许所有IP
    'blocked_ips' => []
];

// 创建必要的目录
$directories = [
    $cacheConfig['directory'],
    __DIR__ . '/../logs/'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
?>
