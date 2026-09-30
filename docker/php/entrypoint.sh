#!/bin/sh
# =============================================================================
# Docker 镜像加速服务监控 - PHP-FPM 容器入口脚本
#
# 只做三件事：准备可写数据目录、必要时自检、把 PID 1 交给 php-fpm。
# 幂等：容器重启不会破坏已有数据卷内容。
# =============================================================================

set -e

DATA_DIR="${DMM_DATA_DIR:-/var/www/html/data}"
CACHE_DIR="$DATA_DIR/cache"
LOG_DIR="$DATA_DIR/logs"

log() {
    echo "[entrypoint] $*"
}

# ---- 1. 数据目录 ----
# 挂载命名卷时目录属主可能是 root，而 PHP-FPM 以 www-data 运行，必须放开写权限。
mkdir -p "$CACHE_DIR" "$LOG_DIR"

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "$DATA_DIR" 2>/dev/null || true
    # 只给属主写权限，不用 777
    chmod -R u+rwX,go+rX "$DATA_DIR" 2>/dev/null || true
else
    log "警告：非 root 启动，若数据卷属主不是当前用户写入会失败"
fi

if [ ! -w "$DATA_DIR" ]; then
    log "错误：数据目录不可写 ($DATA_DIR)，缓存与日志会失效"
    log "      请检查挂载权限，或设置 DMM_DATA_DIR 指向可写路径"
fi

# ---- 2. 启动前自检（可用 DMM_SKIP_SELFTEST=1 关闭）----
if [ "${DMM_SKIP_SELFTEST:-0}" != "1" ]; then
    if php /var/www/html/selftest.php --offline >/tmp/selftest.out 2>&1; then
        log "自检通过（离线模式）"
    else
        log "自检未通过，输出如下（不影响启动，仅提示）："
        sed 's/^/    /' /tmp/selftest.out
    fi
fi

# ---- 3. 前台运行 php-fpm ----
log "启动 php-fpm：数据目录=$DATA_DIR 用户=$(id -un)"
exec "$@"
