# =============================================================================
# Docker 镜像加速服务监控 - Nginx 镜像
#
# 只做静态页面托管 + 反向代理到 php-fpm，因此镜像极小。
# =============================================================================

FROM nginx:1.27-alpine

LABEL org.opencontainers.image.title="docker-mirror-monitor (nginx)" \
      org.opencontainers.image.description="静态页面与 API 反向代理" \
      org.opencontainers.image.licenses="MIT"

RUN set -eux; \
    apk add --no-cache curl tzdata; \
    rm -f /etc/nginx/conf.d/default.conf

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --chown=nginx:nginx index.html script.js theme.js favicon.ico /usr/share/nginx/html/
COPY --chown=nginx:nginx demo.png /usr/share/nginx/html/

ENV TZ=Asia/Shanghai

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -fsS http://127.0.0.1/healthz || exit 1
