<?php
/**
 * Docker镜像加速服务监控 - 配置文件
 * 所有可配置项集中管理
 */

return [
    // Docker镜像服务列表
    'services' => [
        ['name' => '中科大镜像站', 'url' => 'https://docker.mirrors.ustc.edu.cn', 'provider' => 'USTC', 'description' => '中国科学技术大学开源软件镜像站'],
        ['name' => '阿里云镜像', 'url' => 'https://registry.cn-hangzhou.aliyuncs.com', 'provider' => '阿里云', 'description' => '阿里云容器镜像服务'],
        ['name' => '腾讯云镜像', 'url' => 'https://mirror.ccs.tencentyun.com', 'provider' => '腾讯云', 'description' => '腾讯云容器镜像服务'],
        ['name' => '华为云镜像', 'url' => 'https://swr.cn-north-1.myhuaweicloud.com', 'provider' => '华为云', 'description' => '华为云软件开发生产线'],
        ['name' => '上海交大镜像', 'url' => 'https://docker.mirrors.sjtug.sjtu.edu.cn', 'provider' => '上海交大', 'description' => '上海交通大学软件源镜像服务'],
        ['name' => '南京大学镜像', 'url' => 'https://docker.nju.edu.cn', 'provider' => '南京大学', 'description' => '南京大学开源镜像站'],
        ['name' => '毫秒镜像', 'url' => 'https://docker.1ms.run', 'provider' => '木雷坞', 'description' => '毫秒镜像 CloudFlare 加速'],
        ['name' => '1Panel 镜像', 'url' => 'https://docker.1panel.live', 'provider' => '1Panel', 'description' => '1Panel CloudFlare 镜像源'],
        ['name' => '耗子面板', 'url' => 'https://hub.rat.dev', 'provider' => '耗子面板', 'description' => '耗子面板 CloudFlare 镜像'],
        ['name' => 'DockerProxy', 'url' => 'https://dockerproxy.net', 'provider' => 'DockerProxy', 'description' => 'DockerProxy Oracle CDN'],
        ['name' => '科技 lion', 'url' => 'https://docker.kejilion.pro', 'provider' => '科技lion', 'description' => '自媒体 UP 主 Nginx 镜像'],
        ['name' => 'atomhub', 'url' => 'https://atomhub.openatom.cn', 'provider' => '开放原子', 'description' => '开放原子开源基金会镜像'],
        ['name' => 'Docker Proxy', 'url' => 'https://dockerpull.com', 'provider' => 'DockerPull', 'description' => 'Docker 镜像代理服务'],
        ['name' => 'Docker Hub 官方', 'url' => 'https://hub.docker.com', 'provider' => 'Docker官方', 'description' => 'Docker Hub 官方源'],
    ],

    // 缓存配置
    'cache' => [
        'dir' => __DIR__ . '/cache/',
        'duration' => 600, // 10分钟
    ],

    // 日志配置
    'log' => [
        'dir' => __DIR__ . '/logs/',
    ],

    // 超时配置（秒）
    'timeout' => [
        'quick' => 2,
        'default' => 3,
        'connect' => 1,
    ],

    // CORS 允许的来源
    'cors_origins' => [
        'https://docker.mcya.cn',
        'http://docker.mcya.cn',
        'http://localhost:3000',
        'http://localhost:8000',
    ],

    // 速率限制
    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 30,  // 每窗口最大请求数
        'window' => 60,        // 窗口时间（秒）
    ],

    // 状态阈值（毫秒）
    'thresholds' => [
        'fast' => 500,
        'fair' => 1000,
        'slow' => 2000,
    ],
];
