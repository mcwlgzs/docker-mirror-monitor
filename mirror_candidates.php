<?php
/**
 * 镜像源候选验证器（开发辅助，CLI-only）
 *
 * 目的：判断一个加速地址是不是「真的能拉镜像」，而不是只看 /v2/ 握手。
 * 很多站点 /v2/ 返回 200/401，但根本不代理 Docker Hub，拉取时才 404/HTML。
 *
 * 判定流程（完全模拟 docker pull 的协议交互）：
 *   1. GET {url}/v2/                        -> 握手，期望 200 或 401
 *   2. GET {url}/v2/library/alpine/manifests/latest
 *        - 直接 200 且是 manifest JSON      -> 可用（匿名直通）
 *        - 401 + WWW-Authenticate: Bearer   -> 进第 3 步
 *   3. 取 realm/service/scope 换 token，再带 Bearer 重试第 2 步
 *        - 200 且是 manifest JSON           -> 可用（真实代理）
 *        - 其它                              -> 不可用
 *
 * 用法：
 *   php mirror_candidates.php                              # 跑内置候选列表
 *   php mirror_candidates.php --only=<url1,url2> --retry=3  # 只测指定的几个
 *   php mirror_candidates.php --json                        # 输出 JSON
 *
 * 开发辅助脚本，部署镜像不包含它（见 .dockerignore）；上线前可直接删除。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("mirror_candidates.php 只能在命令行运行\n");
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';
dmm_bootstrap($config);

$asJson = in_array('--json', $argv, true);

// 失败重试次数（默认 2），以及 --only=a,b,c 只测部分地址
$retries = 2;
$only    = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--retry=')) {
        $retries = max(1, (int) substr($arg, 8));
    }
    if (str_starts_with($arg, '--only=')) {
        $only = array_filter(array_map('trim', explode(',', substr($arg, 7))));
    }
}

$candidates = [
    // 当前 config.php 里已有的
    'https://docker.mirrors.ustc.edu.cn',
    'https://registry.cn-hangzhou.aliyuncs.com',
    'https://mirror.ccs.tencentyun.com',
    'https://swr.cn-north-1.myhuaweicloud.com',
    'https://docker.mirrors.sjtug.sjtu.edu.cn',
    'https://docker.nju.edu.cn',
    'https://docker.1ms.run',
    'https://docker.1panel.live',
    'https://hub.rat.dev',
    'https://dockerproxy.net',
    'https://docker.kejilion.pro',
    'https://atomhub.openatom.cn',
    'https://dockerpull.com',
    'https://hub.docker.com',
    // 候选：来自 2026 年公开镜像列表
    'https://docker.m.daocloud.io',
    'https://dockerproxy.link',
    'https://proxy.vvvv.ee',
    'https://docker.jiaxin.site',
    'https://docker.xuanyuan.me',
    'https://registry.cyou',
    'https://free.hubfast.cn',
    'https://hubfast.cn',
    'https://docker.hlyun.org',
    'https://do.nark.eu.org',
    'https://dockerpull.org',
    'https://docker.1panel.dev',
    'https://docker.aityp.com',
    'https://mirror.houlang.cloud',
    'https://docker.1panelproxy.com',
    'https://docker.rainbond.cc',
    'https://docker.unsee.tech',
    'https://registry.dockermirror.com',
    'https://docker.hpcloud.cloud',
    'https://docker.zhai.cm',
    'https://hub.littlediary.cn',
    'https://docker.anyhub.us.kg',
    // 补充候选与官方基线
    'https://registry-1.docker.io',
    'https://dockerhub.icu',
    'https://hub1.nat.tf',
    'https://docker.amingg.com',
    'https://docker.1panel.top',
    'https://docker.io',
    'https://mirror.baidubce.com',
    'https://docker.mirrors.aliyun.com',
    'https://dockerproxy.com',
    'https://docker.nat.tf',
    'https://docker.1panel.live',
    'https://dockerhub.azk8s.cn',
    'https://reg-mirror.qiniu.com',
    'https://docker.mirrors.sjtug.sjtu.edu.cn',
];

$caBundle = dmm_resolve_ca_bundle($config);

/** 单次 GET，返回 [errno, httpCode, headers(小写), body, elapsedMs] */
function mc_get(string $url, array $headers, ?string $caBundle, int $timeout = 10): array
{
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'Docker-Mirror-Monitor/check',
        CURLOPT_HEADER         => true,
    ];
    if ($caBundle) {
        $opt[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $opt);

    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    $body    = '';
    $headersOut = [];
    if (is_string($raw)) {
        $headerSize = (int) ($info['header_size'] ?? 0);
        $headerText = substr($raw, 0, $headerSize);
        $body       = substr($raw, $headerSize);
        // 处理重定向导致的多个响应头块：只取最后一块
        $blocks = preg_split("/\r?\n\r?\n/", trim($headerText));
        $last   = end($blocks);
        foreach (explode("\n", (string) $last) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headersOut[strtolower(trim($k))] = trim($v);
            }
        }
    }

    return [
        $errno,
        (int) ($info['http_code'] ?? 0),
        $headersOut,
        $body,
        (int) round((float) ($info['total_time'] ?? 0) * 1000),
    ];
}

/** 从 WWW-Authenticate: Bearer realm="...",service="...",scope="..." 里取值 */
function mc_parse_challenge(string $header): array
{
    $out = ['realm' => '', 'service' => '', 'scope' => ''];
    if (stripos($header, 'bearer') === false) {
        return $out;
    }
    foreach (['realm', 'service', 'scope'] as $key) {
        if (preg_match('/' . $key . '="([^"]*)"/i', $header, $m)) {
            $out[$key] = $m[1];
        }
    }
    return $out;
}

$manifestAccept = implode(', ', [
    'application/vnd.docker.distribution.manifest.list.v2+json',
    'application/vnd.docker.distribution.manifest.v2+json',
    'application/vnd.oci.image.index.v1+json',
    'application/vnd.oci.image.manifest.v1+json',
]);

$results = [];

if ($only) {
    $candidates = array_values(array_filter($candidates, static function ($u) use ($only) {
        return in_array(rtrim($u, '/'), $only, true) || in_array($u, $only, true);
    }));
}

/** 带重试的完整验证：返回 [ok(bool), row] */
function mc_verify(string $base, ?string $caBundle, string $manifestAccept, int $retries): array
{
    $attempts = [];
    for ($i = 0; $i < $retries; $i++) {
        [$row, $ok] = mc_verify_once($base, $caBundle, $manifestAccept);
        $row['attempts'] = $i + 1;
        if ($ok) {
            return [true, $row];
        }
        $attempts[] = $row;
        if ($i + 1 < $retries) {
            usleep(400000); // 400ms 退避，避开瞬时限流
        }
    }
    $last = end($attempts);
    $last['attempts'] = $retries;
    $last['detail'] .= sprintf('（重试 %d 次仍失败）', $retries);
    return [false, $last];
}

function mc_verify_once(string $base, ?string $caBundle, string $manifestAccept): array
{
    $row  = [
        'url'          => $base,
        'v2_code'      => 0,
        'v2_errno'     => 0,
        'v2_ms'        => 0,
        'manifest'     => 'fail',
        'http_code'    => 0,
        'detail'       => '',
        'media_type'   => '',
    ];

    // 1. 握手（与线上探测同一条路径）
    [$errno, $code, $hdrs, $body, $ms] = mc_get($base . '/v2/', ['Accept: application/json'], $caBundle);
    $row['v2_errno'] = $errno;
    $row['v2_code']  = $code;
    $row['v2_ms']    = $ms;
    if ($errno !== 0) {
        $row['detail'] = '连接失败：' . dmm_translate_curl_error($errno, 'CURL error');
        return [$row, false];
    }
    if ($code !== 200 && $code !== 401) {
        $row['detail'] = '握手异常 HTTP ' . $code;
        return [$row, false];
    }

    // 2. 真实拉取 manifests
    $manifestUrl = $base . '/v2/library/alpine/manifests/latest';
    $acceptHdr   = ['Accept: ' . $manifestAccept];
    [$e2, $c2, $h2, $b2, $ms2] = mc_get($manifestUrl, $acceptHdr, $caBundle);

    $token = '';
    if ($c2 === 401 && !empty($h2['www-authenticate'])) {
        // 3. 走 token 流程
        $challenge = mc_parse_challenge($h2['www-authenticate']);
        if ($challenge['realm'] === '') {
            $row['detail'] = '401 但 WWW-Authenticate 无法解析';
            return [$row, false];
        }
        $query = [];
        if ($challenge['service'] !== '') {
            $query['service'] = $challenge['service'];
        }
        $query['scope'] = $challenge['scope'] !== '' ? $challenge['scope'] : 'repository:library/alpine:pull';
        $tokenUrl = $challenge['realm'] . (strpos($challenge['realm'], '?') === false ? '?' : '&') . http_build_query($query);

        [$e3, $c3, $h3, $b3, $ms3] = mc_get($tokenUrl, ['Accept: application/json'], $caBundle);
        if ($e3 === 0 && $c3 === 200) {
            $tj    = json_decode($b3, true);
            $token = is_array($tj) ? (string) ($tj['token'] ?? $tj['access_token'] ?? '') : '';
        }
        if ($token === '') {
            $row['detail'] = sprintf('取 token 失败（HTTP %s）', $c3 ?: ('err ' . $e3));
            return [$row, false];
        }
        [$e2, $c2, $h2, $b2, $ms2] = mc_get($manifestUrl, array_merge($acceptHdr, ['Authorization: Bearer ' . $token]), $caBundle);
    }

    $row['http_code'] = $c2;
    if ($e2 !== 0) {
        $row['detail'] = '拉取失败：' . dmm_translate_curl_error($e2, 'CURL error');
        return [$row, false];
    }
    if ($c2 === 200) {
        $decoded = json_decode($b2, true);
        if (is_array($decoded) && isset($decoded['schemaVersion'])) {
            $row['manifest']   = 'ok';
            $row['media_type'] = (string) ($decoded['mediaType'] ?? '');
            if ($row['media_type'] === '' && isset($decoded['manifests'])) {
                $row['media_type'] = 'manifest-list(' . count($decoded['manifests']) . ')';
            }
            $row['detail'] = '真实可拉取' . ($token !== '' ? '（需 token）' : '（匿名）');
            return [$row, true];
        }
        $row['detail'] = 'HTTP 200 但不是 manifest JSON（首 60 字节：' . substr(preg_replace('/\s+/', ' ', $b2), 0, 60) . '）';
        return [$row, false];
    }
    if ($c2 === 401 || $c2 === 403) {
        $row['detail'] = '需要鉴权但无法换到 token（HTTP ' . $c2 . '）';
        return [$row, false];
    }
    if ($c2 === 404) {
        $row['detail'] = '不代理 Docker Hub（manifests 404）';
        return [$row, false];
    }
    $row['detail'] = 'HTTP ' . $c2;
    return [$row, false];
}

foreach ($candidates as $base) {
    $base = rtrim($base, '/');
    [, $row] = mc_verify($base, $caBundle, $manifestAccept, $retries);
    $results[$base] = $row;
}

if ($asJson) {
    echo json_encode(array_values($results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$ok = [];
$bad = [];
foreach ($results as $r) {
    if ($r['manifest'] === 'ok') {
        $ok[] = $r;
    } else {
        $bad[] = $r;
    }
}
usort($ok, static fn($a, $b) => $a['v2_ms'] <=> $b['v2_ms']);
usort($bad, static fn($a, $b) => strcmp($a['url'], $b['url']));

printf("=== 可用（真实拉到 library/alpine:latest manifest）：%d 个 ===\n", count($ok));
printf("%-42s %6s %8s %s\n", 'URL', 'HTTP', '握手ms', '说明');
foreach ($ok as $r) {
    printf("%-42s %6d %8d %s\n", $r['url'], $r['http_code'], $r['v2_ms'], $r['detail'] . ' / ' . $r['media_type']);
}

printf("\n=== 不可用：%d 个 ===\n", count($bad));
printf("%-42s %6s %8s %s\n", 'URL', 'HTTP', '握手ms', '原因');
foreach ($bad as $r) {
    printf("%-42s %6d %8d %s\n", $r['url'], $r['v2_code'], $r['v2_ms'], $r['detail']);
}

printf("\n直接可放进 daemon.json 的 registry-mirrors：\n");
foreach ($ok as $r) {
    printf("  \"%s\",\n", $r['url']);
}

exit(count($ok) > 0 ? 0 : 1);
