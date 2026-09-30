<?php
/**
 * 本地自检脚本：不需要 web 服务器，直接验证配置、环境与真实镜像源探测。
 *
 * 用法：
 *   php selftest.php            # 完整自检（含真实网络探测）
 *   php selftest.php --offline  # 跳过网络探测，只检查环境与配置
 *
 * 退出码：0 = 全部通过，1 = 有失败项。可用于 CI 或容器启动前检查。
 *
 * 注意：这里调用的是 lib.php 中的真实函数（dmm_resolve_ca_bundle /
 * dmm_probe_services），不复制一份探测实现，避免自检通过而线上失败。
 */

declare(strict_types=1);

// 仅供命令行使用：绝不允许通过 web 访问，否则会泄露服务器路径与环境信息
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("selftest.php 只能在命令行运行：php selftest.php\n");
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';
dmm_bootstrap($config);

$offline = in_array('--offline', $argv ?? [], true);

$fail = 0;
$pass = 0;

function cut(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $length);
    }
    return substr($value, 0, $length);
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "  [OK]   {$label}" . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    }
}

echo "== 环境检查 ==" . PHP_EOL;
check('PHP >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);
check('curl 扩展', extension_loaded('curl'));
check('curl_multi 可用（并发探测依赖）', function_exists('curl_multi_init'));
check('json 扩展', extension_loaded('json'));
check('openssl 扩展（HTTPS 探测依赖）', extension_loaded('openssl'));

$caBundle = dmm_resolve_ca_bundle($config);
check(
    '可用的 CA 证书包',
    $caBundle !== null,
    $caBundle !== null ? $caBundle : '未找到，HTTPS 探测会全部失败（curl 60）'
);

echo PHP_EOL . "== 数据目录可写 ==" . PHP_EOL;
foreach ([$config['cache']['dir'], $config['log']['dir']] as $dir) {
    $dir = (string) $dir;
    check(rtrim($dir, '/\\'), is_dir($dir) && is_writable($dir));
}

echo PHP_EOL . "== 服务配置 ==" . PHP_EOL;
check('服务数量 > 0', count($config['services']) > 0, count($config['services']) . ' 个');

$badUrls = [];
$dupIds = [];
$seenIds = [];
$hasVpc = false;
foreach ($config['services'] as $service) {
    $probe = isset($service['probe']) && $service['probe'] !== ''
        ? $service['probe']
        : (string) $service['url'] . '/v2/';
    if (filter_var($probe, FILTER_VALIDATE_URL) === false) {
        $badUrls[] = ($service['id'] ?? '?') . '=' . $probe;
    }
    if (isset($seenIds[$service['id']])) {
        $dupIds[] = $service['id'];
    }
    $seenIds[$service['id']] = true;
    if (($service['region'] ?? 'public') === 'vpc') {
        $hasVpc = true;
    }
}
check('探测地址均为合法 URL', $badUrls === [], implode(', ', $badUrls));
check('服务 id 无重复', $dupIds === [], implode(', ', $dupIds));
check('VPC 专用源已标注 region', $hasVpc);

echo PHP_EOL . "== 缓存与限流 ==" . PHP_EOL;
$cacheKey = 'selftest:' . getmypid();
dmm_cache_set($cacheKey, ['ok' => true, 'value' => 42], $config);
$cached = dmm_cache_get($cacheKey, $config);
check('缓存写入后可读回', is_array($cached) && ($cached['value'] ?? null) === 42);
check('缓存带 cached 标记', is_array($cached) && ($cached['cached'] ?? false) === true);
$cacheFile = $config['cache']['dir'] . 'resp_' . sha1($cacheKey) . '.json';
if (is_file($cacheFile)) {
    @unlink($cacheFile);
}
check('缓存使用原子写入（无残留 tmp）', glob($config['cache']['dir'] . '*.tmp') === []);

$rl = dmm_rate_limit($config, (string) $config['cache']['dir'], 'selftest-' . getmypid());
check('限流函数返回结构正确', isset($rl['allowed'], $rl['remaining'], $rl['retry_after']));
$rlFile = $config['cache']['dir'] . 'rate_' . sha1('selftest-' . getmypid()) . '.json';
if (is_file($rlFile)) {
    @unlink($rlFile);
}

if ($offline) {
    echo PHP_EOL . "== 结果（离线模式，已跳过网络探测）==" . PHP_EOL;
    echo "通过 {$pass} 项，失败 {$fail} 项" . PHP_EOL;
    exit($fail === 0 ? 0 : 1);
}

echo PHP_EOL . "== 真实探测（并发，单源超时 " . $config['probe']['timeout'] . "s）==" . PHP_EOL;

$started = microtime(true);
$results = dmm_probe_services($config['services'], $config);
$elapsed = (int) round((microtime(true) - $started) * 1000);

$reachable = 0;
foreach ($results as $result) {
    if (($result['status'] ?? 'error') !== 'error') {
        $reachable++;
    }
}

usort($results, static function ($a, $b) {
    $aOk = ($a['status'] ?? 'error') !== 'error';
    $bOk = ($b['status'] ?? 'error') !== 'error';
    if ($aOk !== $bOk) {
        return $aOk ? -1 : 1;
    }
    return ($a['responseTime'] ?? 0) <=> ($b['responseTime'] ?? 0);
});

echo sprintf("  %-14s %-6s %-8s %-8s %s", '服务', 'HTTP', '耗时', '状态', '说明') . PHP_EOL;
foreach ($results as $result) {
    $ok = ($result['status'] ?? 'error') !== 'error';
    $note = $ok
        ? (($result['authRequired'] ?? false) ? '可用（需鉴权，正常）' : '可用')
        : (string) ($result['error'] ?? '检测失败');
    echo sprintf(
        "  %-14s %-6s %-8s %-8s %s",
        cut((string) $result['name'], 12),
        ($result['httpCode'] ?? 0) > 0 ? $result['httpCode'] : '-',
        ($result['responseTime'] ?? 0) > 0 ? $result['responseTime'] . 'ms' : '-',
        (string) ($result['status'] ?? 'error'),
        $note
    ) . PHP_EOL;
}

echo PHP_EOL;
$sumMs = 0;
foreach ($results as $result) {
    $sumMs += (int) ($result['responseTime'] ?? 0);
}
// 串行执行的总耗时应约等于各源耗时之和；并发执行则接近最慢的那个源。
// 并发耗时明显小于累加和，才说明 curl_multi 真的在并发工作。
check(
    '并发探测生效（总耗时 < 各源耗时之和）',
    $elapsed < $sumMs,
    '实际 ' . $elapsed . 'ms，串行累加约 ' . $sumMs . 'ms'
);
check('至少有一个镜像源可用', $reachable > 0, $reachable . '/' . count($results) . ' 可用');

echo PHP_EOL . "== 结果 ==" . PHP_EOL;
echo "通过 {$pass} 项，失败 {$fail} 项" . PHP_EOL;
echo "提示：镜像源可用性随时间变化，个别源不可用属于正常现象。" . PHP_EOL;

exit($fail === 0 ? 0 : 1);
