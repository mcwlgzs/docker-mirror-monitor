# =============================================================================
# Docker 镜像加速服务监控 - PHP-FPM 镜像
#
# 为什么用 nginx + php-fpm 而不是 php -S 内置服务器：
#   内置服务器是单进程模型，一个慢探测请求就会阻塞其它所有请求，
#   而本项目的探测最长要等 8 秒；php-fpm 才能支撑多用户并发访问。
#
# 构建上下文 = 仓库根目录：
#   docker build -f docker/php/Dockerfile -t docker-mirror-monitor-php .
# =============================================================================

FROM php:8.2-fpm-alpine

LABEL org.opencontainers.image.title="docker-mirror-monitor (php-fpm)" \
      org.opencontainers.image.description="由本机直连探测国内 Docker Hub 镜像加速服务可用性" \
      org.opencontainers.image.licenses="MIT"

# ---- 依赖 ----
# curl：探测镜像源必需
# ca-certificates：HTTPS 证书链，缺它所有探测都会以 curl 60 失败
# tzdata：支持通过 TZ 环境变量切换时区
RUN set -eux; \
    apk add --no-cache curl ca-certificates tzdata; \
    update-ca-certificates; \
    php -r 'exit(function_exists("curl_multi_init") ? 0 : 1);' \
        || (echo "curl_multi 不可用，探测引擎无法工作" >&2 && exit 1); \
    php -r 'exit(extension_loaded("json") ? 0 : 1);' \
        || (echo "json 扩展缺失" >&2 && exit 1); \
    php -r 'exit(extension_loaded("openssl") ? 0 : 1);' \
        || (echo "openssl 扩展缺失，HTTPS 探测不可用" >&2 && exit 1)

WORKDIR /var/www/html

# ---- 配置 ----
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-dmm.conf
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
RUN { \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /proc/self/fd/2'; \
        echo 'expose_php = Off'; \
        echo 'file_uploads = Off'; \
        echo 'date.timezone = Asia/Shanghai'; \
        echo 'curl.cainfo = /etc/ssl/certs/ca-certificates.crt'; \
        echo 'openssl.cafile = /etc/ssl/certs/ca-certificates.crt'; \
    } > /usr/local/etc/php/conf.d/zz-dmm.ini

# ---- 应用代码 ----
# 刻意不 COPY data/：可写目录由数据卷提供，避免镜像里带着上一轮的缓存和日志
COPY --chown=www-data:www-data \
     api.php \
     config.php \
     lib.php \
     selftest.php \
     index.html \
     script.js \
     theme.js \
     favicon.ico \
     /var/www/html/

COPY --chown=root:root docker/php/entrypoint.sh /usr/local/bin/dmm-entrypoint.sh

RUN set -eux; \
    chmod 0755 /usr/local/bin/dmm-entrypoint.sh; \
    mkdir -p /var/www/html/data/cache /var/www/html/data/logs; \
    chown -R www-data:www-data /var/www/html/data

# 缓存与日志目录（nginx 容器不需要它，只有 php 容器挂载）
VOLUME ["/var/www/html/data"]

# 变量名必须与 config.php 中 dmm_env() 读取的键完全一致，
# 否则用户按这里的名字传入环境变量会静默无效（例如旧的 DMM_CACHE_TTL
# 其实无人读取，真正的键是 DMM_CACHE_DURATION）。
ENV TZ=Asia/Shanghai \
    DMM_DATA_DIR=/var/www/html/data \
    DMM_CACHE_DURATION=300 \
    DMM_LOG_RETENTION_DAYS=7 \
    DMM_PROBE_TIMEOUT=8 \
    DMM_PROBE_CONNECT_TIMEOUT=5 \
    DMM_RATE_LIMIT_MAX=60 \
    DMM_RATE_LIMIT_WINDOW=60 \
    DMM_RATE_LIMIT_BLOCK=120 \
    DMM_TRUST_PROXY=0

EXPOSE 9000

# 容器级健康检查：数据目录可写 + 探测引擎可用
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD php -r 'exit(is_writable("/var/www/html/data/cache") && function_exists("curl_multi_init") ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/dmm-entrypoint.sh"]
CMD ["php-fpm", "-F"]
