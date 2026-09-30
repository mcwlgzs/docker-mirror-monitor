<?php
/**
 * 开发者辅助脚本：验证 config.php 的校验逻辑会拒绝非法配置。
 * 用法：php check_config_errors.php
 *
 * 该脚本仅供开发调试，部署时可以删除。
 */

// 仅供命令行使用，禁止 web 访问
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("check_config_errors.php 只能在命令行运行\n");
}

$cases = [
    'URL 非法' => '[{"name":"x","url":"not-a-url"}]',
    // 用显式不同的 id 才能命中 URL 重复这条校验（否则会先被 id 重复拦下）
    'URL 重复' => '[{"id":"a","name":"a","url":"https://a.example.com"},{"id":"b","name":"b","url":"https://a.example.com"}]',
    'id 重复' => '[{"id":"same","name":"a","url":"https://a.example.com"},{"id":"same","name":"b","url":"https://b.example.com"}]',
    '正常配置' => '[{"name":"ok","url":"https://ok.example.com"}]',
];

$failed = 0;
foreach ($cases as $label => $json) {
    putenv('DMM_SERVICES_JSON=' . $json);
    try {
        $config = require __DIR__ . '/config.php';
        printf("  [%s] %s -> 未抛异常（services=%d）%s\n",
            $label === '正常配置' ? '期望通过' : '期望拒绝',
            $label,
            count($config['services']),
            $label === '正常配置' ? '' : '  <== 不符合预期'
        );
        if ($label !== '正常配置') {
            $failed++;
        }
    } catch (Throwable $e) {
        $expected = $label !== '正常配置';
        printf("  [%s] %s -> %s%s\n",
            $expected ? '期望拒绝' : '期望通过',
            $label,
            $e->getMessage(),
            $expected ? '' : '  <== 不符合预期'
        );
        if (!$expected) {
            $failed++;
        }
    }
}

putenv('DMM_SERVICES_JSON');

echo $failed === 0 ? "\n配置校验行为符合预期。\n" : "\n有 {$failed} 项不符合预期。\n";
exit($failed === 0 ? 0 : 1);
