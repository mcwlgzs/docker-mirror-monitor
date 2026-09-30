<?php
/**
 * Docker 镜像加速服务监控 - 配置中心
 *
 * 所有可配置项集中在此文件。支持通过环境变量覆盖，便于容器化部署：
 * 优先级 环境变量 > 本文件默认值。
 *
 * 常用环境变量见 README「环境变量」章节。
 */

declare(strict_types=1);

// ==================== 环境变量读取辅助 ====================

if (!function_exists('dmm_env')) {
    /**
     * 读取环境变量并做类型转换。
     *
     * @param string $key      环境变量名
     * @param mixed  $default  默认值（其类型决定转换目标）
     * @return mixed
     */
    function dmm_env(string $key, $default)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        if (is_bool($default)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_int($default)) {
            return (int) $value;
        }
        if (is_float($default)) {
            return (float) $value;
        }
        if (is_array($default)) {
            // 逗号分隔列表
            $parts = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
            return $parts ?: $default;
        }

        return $value;
    }
}

if (!function_exists('dmm_list')) {
    /**
     * 解析逗号分隔的环境变量为数组。
     *
     * @return string[]
     */
    function dmm_list(string $key, array $default): array
    {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        return $parts ?: $default;
    }
}

// ==================== 运行路径 ====================

$dataDir = rtrim(dmm_env('DMM_DATA_DIR', __DIR__ . '/data'), "/\\") . '/';
$cacheDir = rtrim(dmm_env('DMM_CACHE_DIR', $dataDir . 'cache'), "/\\") . '/';
$logDir = rtrim(dmm_env('DMM_LOG_DIR', $dataDir . 'logs'), "/\\") . '/';

// ==================== 服务列表 ====================
//
// 每个服务可包含以下字段：
//   name        展示名称
//   url         镜像加速地址（会展示给用户复制）
//   provider    提供商（用于前端图标与排序）
//   description 描述
//   probe       探针地址，默认 url + '/v2/'。Docker Registry 的 /v2/ 握手端点
//               是判断一个镜像源「是否真的可用」的标准方式。
//   region      可用范围标记：public（公网可用）/ vpc（仅云内网可用）
//   note        附加说明（前端 tooltip）
//
// 注意：探针必须由「本监控服务器」直接发起，这样才能反映真实网络路径。
//
// ⚠️ 关于镜像源的可用性
//   公共加速站生命周期很短，下面这份列表在 2026-09 由 mirror_candidates.php
//   逐个做过「真实拉取」验证（握手 → 取 token → 拉取 library/alpine:latest 的
//   manifest，四步全部通过才算可用）。若某个源又失效了，请重新跑：
//       php mirror_candidates.php            # 全量候选
//       php mirror_candidates.php --only=<url1,url2> --retry=3
//   然后把通过验证的地址补进来，把失效的删掉。
//
//   不要收录以下类型的地址：
//     * hub.docker.com            —— 网站，不是 Registry 端点
//     * registry.cn-hangzhou.aliyuncs.com 等云厂商「自家」Registry
//       （/v2/ 返回 401 但没有可用于 Docker Hub 的 token，不是加速器）
//     * 已下线的镜像站（中科大 USTC、上海交大、南京大学、网易 163 均已停服）
//
//   阿里云等厂商的加速器是控制台按账号下发的专属地址，形如
//   https://<你的编码>.mirror.aliyuncs.com，且仅同云账号可用；请通过
//   DMM_SERVICES_JSON 自行加入，不要写死在默认列表里。

$services = [
    [
        'name' => 'DaoCloud 镜像',
        'url' => 'https://docker.m.daocloud.io',
        'provider' => 'DaoCloud',
        'description' => 'DaoCloud 公共镜像代理（实测响应最快）',
    ],
    [
        'name' => '毫秒镜像',
        'url' => 'https://docker.1ms.run',
        'provider' => '木雷坞',
        'description' => '毫秒镜像，公益公共加速源',
    ],
    [
        'name' => '华为云 SWR',
        'url' => 'https://swr.cn-north-1.myhuaweicloud.com',
        'provider' => '华为云',
        'description' => '华为云容器镜像服务公共只读地址',
    ],
    [
        'name' => 'Nat.tf 镜像',
        'url' => 'https://hub1.nat.tf',
        'provider' => 'Nat.tf',
        'description' => 'Nat.tf 公共镜像代理（匿名可拉取）',
    ],
    [
        'name' => '轩辕镜像',
        'url' => 'https://docker.xuanyuan.me',
        'provider' => '轩辕镜像',
        'description' => '轩辕镜像免费版（匿名可拉取，仅同步 Docker Hub）',
    ],
    [
        'name' => '简行镜像',
        'url' => 'https://docker.jiaxin.site',
        'provider' => '简行镜像',
        'description' => '简行镜像公共加速（匿名可拉取）',
    ],
    [
        'name' => 'HubFast 镜像',
        'url' => 'https://free.hubfast.cn',
        'provider' => 'HubFast',
        'description' => 'HubFast 免费镜像代理（匿名可拉取）',
    ],
    [
        'name' => '1Panel 镜像',
        'url' => 'https://docker.1panel.live',
        'provider' => '1Panel',
        'description' => '1Panel 官方公共镜像源',
    ],
    [
        'name' => 'DockerProxy',
        'url' => 'https://dockerproxy.net',
        'provider' => 'DockerProxy',
        'description' => 'DockerProxy 公共代理（匿名可拉取）',
    ],
    [
        'name' => '腾讯云镜像',
        'url' => 'https://mirror.ccs.tencentyun.com',
        'provider' => '腾讯云',
        'description' => '腾讯云容器镜像服务',
        'region' => 'vpc',
        'note' => '仅腾讯云服务器内网可访问，公网访问必然失败',
    ],
    [
        'name' => 'Docker Hub 官方',
        'url' => 'https://registry-1.docker.io',
        'provider' => 'Docker官方',
        'description' => 'Docker Hub 官方源（国内直连不稳定，作为基线对照）',
    ],
];

// 允许通过环境变量覆盖整个服务列表（JSON 数组），便于自建镜像源接入
$servicesOverride = getenv('DMM_SERVICES_JSON');
if (is_string($servicesOverride) && trim($servicesOverride) !== '') {
    $decoded = json_decode($servicesOverride, true);
    if (is_array($decoded) && $decoded !== []) {
        $services = $decoded;
    }
}

// 补齐默认字段
foreach ($services as $index => $service) {
    $service['url'] = rtrim($service['url'] ?? '', '/');
    if (empty($service['probe'])) {
        $service['probe'] = $service['url'] . '/v2/';
    }
    $service['region'] = $service['region'] ?? 'public';
    $service['note'] = $service['note'] ?? '';
    // id 由 url 派生，稳定且可读；用于前端 data 属性与前端的行定位
    $service['id'] = $service['id'] ?? substr(sha1($service['url']), 0, 12);
    $services[$index] = $service;
}

// 校验：URL 必须合法、id 不能重复，否则前端行定位与单源探测都会出错。
// 配置写错时宁可让接口明确报错，也不要静默返回半残数据。
$seenIds = [];
$seenUrls = [];
foreach ($services as $index => $service) {
    $url = (string) $service['url'];
    if (filter_var($url, FILTER_VALIDATE_URL) === false || strpos($url, 'http') !== 0) {
        throw new RuntimeException(sprintf('services[%d] 的 url 非法：%s', (int) $index, $url));
    }
    if (isset($seenIds[$service['id']])) {
        throw new RuntimeException(sprintf('services[%d] 的 id 重复：%s', (int) $index, (string) $service['id']));
    }
    if (isset($seenUrls[$url])) {
        throw new RuntimeException(sprintf('services[%d] 的 url 重复：%s', (int) $index, $url));
    }
    $seenIds[$service['id']] = true;
    $seenUrls[$url] = true;
}

// ==================== 返回配置 ====================

return [
    'app' => [
        // 站点名称与品牌信息，前端通过 /api.php?action=get_services 获取
        'name' => dmm_env('DMM_APP_NAME', 'Docker 镜像加速服务监控'),
        'site_url' => dmm_env('DMM_SITE_URL', ''),
        'version' => '2.0.0',
        // 输出详细诊断信息（错误码、探测方法等）
        'debug' => dmm_env('DMM_DEBUG', false),
    ],

    'services' => $services,

    // 数据目录（缓存 + 日志），容器部署时挂载此目录即可持久化
    'data_dir' => $dataDir,

    // 缓存配置
    'cache' => [
        'dir' => $cacheDir,
        'duration' => dmm_env('DMM_CACHE_DURATION', 300),
        // 缓存文件由非 root 用户写入
        'mode' => 0755,
    ],

    // 日志配置
    'log' => [
        'dir' => $logDir,
        'enabled' => dmm_env('DMM_LOG_ENABLED', true),
        // 日志保留天数，过期自动清理（0 = 不清理）
        'retention_days' => dmm_env('DMM_LOG_RETENTION_DAYS', 7),
    ],

    // 探测配置（秒 / 毫秒）
    'probe' => [
        // 并发探测全部服务，单个请求总超时。
        // 全部探针并发执行，因此整体耗时约等于最慢的那一个；
        // 8s 可在"容忍跨境慢站点"与"不拖垮接口响应"之间取得平衡。
        'timeout' => dmm_env('DMM_PROBE_TIMEOUT', 8),
        // 建立连接超时
        'connect_timeout' => dmm_env('DMM_PROBE_CONNECT_TIMEOUT', 5),
        // Registry 握手成功后立即中断响应体，避免下载多余数据
        'abort_after_first_byte' => true,
        // 探测使用的 User-Agent
        'user_agent' => dmm_env('DMM_PROBE_UA', 'Docker-Mirror-Monitor/2.0 (+https://github.com/mcwlgzs/docker-mirror-monitor)'),
        // CA 证书包路径。留空则自动探测系统位置（Debian/Alpine/RHEL 均可自动命中）。
        // 若 PHP 报 "unable to get local issuer certificate"，请显式设置此项或 DMM_CA_BUNDLE。
        'ca_bundle' => dmm_env('DMM_CA_BUNDLE', ''),
        // 视为「Registry 握手成功」的 HTTP 状态码。
        // Docker Registry 规范：/v2/ 返回 200（公开）或 401（需要鉴权，属正常）。
        'success_status' => [200, 401, 403],
        // 判定为「服务存在但需要鉴权」的状态码
        'auth_status' => [401, 403],
        // 是否跟随跳转（仅同源跳转，防止被恶意站点拖垮）
        'follow_redirects' => true,
        'max_redirects' => 3,
    ],

    // 响应时间阈值（毫秒），决定 fast / fair / slow / error
    'thresholds' => [
        'fast' => dmm_env('DMM_THRESHOLD_FAST', 500),
        'fair' => dmm_env('DMM_THRESHOLD_FAIR', 1000),
        'slow' => dmm_env('DMM_THRESHOLD_SLOW', 2000),
    ],

    // CORS：留空表示同源部署，不发送 Access-Control-Allow-Origin
    'cors_origins' => dmm_list('DMM_CORS_ORIGINS', [
        'http://localhost:3000',
        'http://localhost:8000',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:8000',
    ]),

    // 速率限制（按客户端 IP 的文件计数）
    'rate_limit' => [
        'enabled' => dmm_env('DMM_RATE_LIMIT_ENABLED', true),
        'max_requests' => dmm_env('DMM_RATE_LIMIT_MAX', 60),
        'window' => dmm_env('DMM_RATE_LIMIT_WINDOW', 60),
        // 超限后的封禁时长（秒），避免持续撞击
        'block_duration' => dmm_env('DMM_RATE_LIMIT_BLOCK', 120),
    ],

    // 反向代理支持：置 1 时信任 X-Forwarded-For 取真实 IP
    'trust_proxy' => dmm_env('DMM_TRUST_PROXY', false),
];
