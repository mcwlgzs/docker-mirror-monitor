# Docker 镜像加速服务监控

由**本机服务器直连探测**国内 Docker Hub 镜像加速服务的可用性与响应速度，并对每个镜像源执行真实的 Docker Registry `/v2/` 握手，而不是依赖第三方接口或 ICMP 延迟。

> **关于早期版本**：v1.x 的检测结果来自模拟数据（前端 `Math.random()`）与第三方 ping 接口，测的是「第三方服务器 → 镜像源」的网络延迟，既不代表镜像站真的能用，也与本机体验无关。v2.0 彻底移除了这两者，页面上展示的每一个状态都来自本服务器的真实探测。历史 issue 中反映的「检测不准确」「数据造假」问题正是本次重写的目标。

## 检测原理

```
浏览器 ──> nginx ──> php-fpm ──> [ curl_multi 并发 ] ──> 各镜像源 /v2/ 握手
                                      │
                                      └──> data/cache（文件缓存，默认 5 分钟）
```

1. **真实握手**：对每个镜像源请求其 Registry 接口 `/v2/`。
   - `200` → 公开可用
   - `401` / `403` → 需要鉴权，属**正常可用**（Docker Registry 规范行为）
   - 其他状态码、连接超时、TLS 失败、DNS 解析失败 → 不可用
2. **本机探测**：探测请求由部署本项目的服务器发出，结果反映该服务器所在网络的实际体验。
3. **并发执行**：单轮 `curl_multi` 同时探测全部镜像源，整体耗时约等于最慢的一个源，而非逐个累加。
4. **证书校验**：开启 `CURLOPT_SSL_VERIFYPEER`，自动探测系统 CA 证书包位置。

## 功能特性

- **真实检测**：Docker Registry `/v2/` 握手，返回 HTTP 状态码与实测耗时
- **并发探测**：`curl_multi` 单轮完成全部镜像源
- **四种状态**：快速 / 一般 / 缓慢 / 极慢，超时或握手失败才算异常
- **失败归因**：区分 DNS 解析失败、TLS 握手失败、连接超时、HTTP 404、Cloudflare 5xx 等具体原因
- **排序筛选**：按提供商、状态、响应时间排序，按状态筛选
- **一键复制**：直接复制镜像地址，用于 `daemon.json`
- **配置指南**：根据本次检测结果自动生成 `registry-mirrors` 配置，只推荐真正可用的镜像源
- **自动刷新**：每 5 分钟刷新，页面重新可见时按需刷新
- **深色模式**：跟随系统偏好，可手动切换
- **缓存机制**：默认 5 分钟文件缓存，`?force=1` 可强制刷新
- **速率限制**：nginx + PHP 双层按 IP 限流
- **健康检查**：`?action=health` 可直接接入监控系统

## 快速开始（Docker Compose，推荐）

```bash
git clone https://github.com/mcwlgzs/docker-mirror-monitor.git
cd docker-mirror-monitor

# 可选：自定义端口等参数
cp .env.example .env

docker compose up -d --build
```

打开 <http://localhost:8080> 即可。首次探测约需 2~8 秒，结果会缓存 5 分钟。

查看状态与日志：

```bash
docker compose ps
docker compose logs -f php
curl -s http://localhost:8080/api.php?action=health
```

停止 / 升级：

```bash
docker compose down            # 停止（保留缓存与日志数据卷）
docker compose up -d --build   # 拉取新代码后重建

docker compose down -v         # 连数据卷一起删除（会清空缓存与日志）
```

### 端口与反向代理

默认映射到宿主机 `8080`。改端口只需编辑 `.env`：

```env
DMM_HTTP_PORT=80
```

放在已有的 nginx / Caddy / Traefik 后面时，请确保转发时保留 `X-Forwarded-For`，
并保持 `DMM_TRUST_PROXY=1`（默认已开启），否则限流会误判客户端 IP。

### 只想要前端 / 直接跑 PHP

不使用 Docker 时，把仓库目录交给任意支持 PHP 的 Web 服务器即可，文档根指向仓库根目录：

- 需要 PHP 7.4+（推荐 8.1+），扩展：`curl`、`json`、`openssl`
- 需要开放 `data/` 目录的写权限
- 所有配置项都可通过环境变量覆盖，见 [config.php](config.php) 与 `.env.example`

本地快速验证：

```bash
php selftest.php            # 完整自检（含真实探测）
php selftest.php --offline  # 只检查环境与配置，不联网
node dom_smoke.mjs          # 前端渲染冒烟测试（无需浏览器、无需依赖）
```

`dom_smoke.mjs` 会在 Node 里用一套极简 DOM 桩直接执行真实的 `script.js`，喂入固定的接口数据，
断言表格渲染、状态分类、筛选排序、配置命令生成、XSS 转义与主题切换等浏览器分支。
它不依赖 jsdom 等第三方包，属于开发辅助脚本，部署镜像不会包含它。

## 项目结构

```
docker-mirror-monitor/
├── index.html                  # 主页面
├── script.js                   # 前端逻辑（渲染、排序、筛选、主题）
├── theme.js                    # Tailwind 配置（含 darkMode: 'class'）
├── api.php                     # HTTP 入口与路由
├── lib.php                     # 探测、缓存、限流、错误翻译等核心实现
├── config.php                  # 配置（支持环境变量覆盖）
├── selftest.php                # 自检脚本（可接入 CI）
├── test.html                   # API 自检面板（仅供内网调试，默认不部署）
├── dom_smoke.mjs               # 前端渲染冒烟测试（Node，无第三方依赖）
├── pretty_json.php             # 开发辅助：格式化 API 响应
├── check_config_errors.php     # 开发辅助：验证配置校验逻辑
├── check_service_shape.php     # 开发辅助：验证单源探测返回结构
├── mirror_candidates.php       # 开发辅助：真实拉取验证候选镜像源是否可用
├── docker-compose.yml          # 一键部署
├── .env.example                # 环境变量示例
├── .github/workflows/ci.yml    # CI：PHP 自检 + 前端冒烟 + 镜像构建
├── docker/
│   ├── nginx/
│   │   ├── Dockerfile
│   │   └── default.conf        # 站点配置、安全头、限流、静态托管
│   └── php/
│       ├── Dockerfile
│       ├── php-fpm.conf
│       ├── opcache.ini
│       └── entrypoint.sh       # 数据目录准备 + 启动自检
└── data/                       # 运行时数据（缓存 + 日志，自动创建）
```

## API 说明

| 端点 | 方法 | 说明 |
|------|------|------|
| `?action=get_services` | GET | 获取镜像源列表与站点信息 |
| `?action=check_all` | GET | 探测全部镜像源 |
| `?action=quick_check` | GET | 只探测前 N 个（默认 6），用于首屏快速出结果 |
| `?action=check_service` | POST | 探测单个镜像源，请求体 `{"url": "..."}` |
| `?action=health` | GET | 健康检查，异常时返回 503 |
| `?action=version` | GET | 版本与运行环境 |

通用参数：

- `force=1` — 跳过缓存，强制重新探测
- `pretty=1` — 格式化输出 JSON，便于人工查看

响应示例：

```json
{
  "success": true,
  "engine": "registry-handshake",
  "scope": "all",
  "check_time_ms": 1865,
  "cached": false,
  "stats": { "total": 11, "fast": 3, "fair": 1, "slow": 2, "very_slow": 1, "error": 4, "available": 7, "availability": 63.6 },
  "data": [
    {
      "name": "DaoCloud 镜像",
      "url": "https://docker.m.daocloud.io",
      "probeUrl": "https://docker.m.daocloud.io/v2/",
      "httpCode": 401,
      "responseTime": 95,
      "status": "fast",
      "reachable": true,
      "authRequired": true,
      "method": "registry-handshake",
      "error": ""
    }
  ]
}
```

> 字段说明：`data` 是服务数组，**每一项就是一行结果**（不是嵌套结构）。
> `status` 只会是 `fast` / `fair` / `slow` / `very_slow` / `error` / `pending` 之一。
> `check_all` 等接口返回的顶层字段为
> `success, data, stats, cached, check_time_ms, engine, scope, timestamp, cache_time, cache_age, cache_ttl`。

## 状态说明

| 状态 | 判定条件 | 页面展示 |
|------|----------|----------|
| 快速 (fast) | 握手成功且 < 500ms | 绿色 |
| 一般 (fair) | 500 ~ 1000ms | 蓝色 |
| 缓慢 (slow) | 1000 ~ 2000ms | 橙色 |
| 极慢 (very_slow) | > 2000ms 但握手成功 | 橙色，标注「极慢」 |
| 异常 (error) | 握手失败、超时、证书错误 | 红色，附带具体原因 |
| 待检测 (pending) | 本轮未探测（如 quick_check 只覆盖部分源） | 灰色 |

阈值可通过 `DMM_THRESHOLD_FAST` / `DMM_THRESHOLD_FAIR` / `DMM_THRESHOLD_SLOW` 调整。

## 配置

所有配置集中在 [config.php](config.php)，每一项都可通过环境变量覆盖，容器部署时在 `.env` 中设置即可。常用项：

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `DMM_HTTP_PORT` | `8080` | 宿主机映射端口（仅 compose 使用） |
| `DMM_PROBE_TIMEOUT` | `8` | 单个镜像源探测总超时（秒） |
| `DMM_PROBE_CONNECT_TIMEOUT` | `5` | 建立连接超时（秒） |
| `DMM_CACHE_DURATION` | `300` | 探测结果缓存时长（秒） |
| `DMM_RATE_LIMIT_MAX` | `60` | 限流窗口内最大请求数 |
| `DMM_RATE_LIMIT_WINDOW` | `60` | 限流窗口（秒） |
| `DMM_RATE_LIMIT_BLOCK` | `120` | 触发限流后的封禁时长（秒） |
| `DMM_TRUST_PROXY` | `1`（compose） | 是否信任 `X-Forwarded-For` |
| `DMM_DATA_DIR` | `./data` | 缓存与日志目录 |
| `DMM_SITE_URL` | 空 | 站点公开地址，用于 canonical / og:url |
| `DMM_CORS_ORIGINS` | 本地开发地址 | CORS 白名单，逗号分隔；同源部署可留空 |
| `DMM_CA_BUNDLE` | 自动探测 | 自定义 CA 证书包路径 |
| `DMM_DEBUG` | `0` | 返回详细错误信息（仅调试期开启） |

完整列表见 `.env.example`。

### 镜像源可用性的地域差异

探测结果**只代表部署本项目的服务器所处的网络**。典型例子：

- `mirror.ccs.tencentyun.com`（腾讯云）在公共 DNS 下无法解析——腾讯云加速地址
  仅限**腾讯云内网**使用，因此配置里标记为 `region=vpc`，页面会明确提示，
  避免把「本地不可用」误报成故障。
- 如果你的服务器在境外，部分国内镜像源会探测失败，这属于预期行为。

### 镜像源会失效：这份列表是怎么来的

公共加速站的生命周期很短，`config.php` 里的默认列表在 **2026-09** 用
[mirror_candidates.php](mirror_candidates.php) 逐个做过**真实拉取**验证，而不只是看
`/v2/` 握手：

```
握手 GET {url}/v2/  →  401 就按 WWW-Authenticate 换 token
                    →  拉取 /v2/library/alpine/manifests/latest
                    →  返回 200 且是 manifest JSON 才算「可用」
```

四步全过才算合格。用同一个脚本可以随时重新体检：

```bash
php mirror_candidates.php                              # 跑全量候选
php mirror_candidates.php --only=<url1,url2> --retry=3  # 只测指定的几个，失败重试
php mirror_candidates.php --json                        # 机器可读输出
```

**为什么必须做真实拉取**：只握手会把假可用当成可用。例如
`registry.cn-hangzhou.aliyuncs.com` 的 `/v2/` 返回 401，但它并不是 Docker Hub
加速器，换不到可用的 token；`docker.nju.edu.cn` 返回 403，同样拉不动镜像。
早期的检测逻辑把 200/401/403 一律算「可用」，于是页面上把这两个源显示成正常，
用户照着配置指南去改 `daemon.json` 却发现 `docker pull` 依然失败——这正是
「接口不能用」的主要来源。现在默认列表里已经没有这类源。

已被移除的失效地址（如仍需要请自行通过 `DMM_SERVICES_JSON` 加回）：
中科大 USTC、上海交大、南京大学、网易 `hub-mirror.c.163.com`、耗子面板
`hub.rat.dev`、科技 lion、开放原子 AtomHub、DockerPull、`hub.docker.com`
（它是网站不是 Registry 端点，官方源应为 `registry-1.docker.io`）。

> 云厂商的专属加速器（阿里云 `https://<你的编码>.mirror.aliyuncs.com` 等）是
> 控制台按账号下发的，**只对同一个云账号/同云内网生效**，所以不写进默认列表。
> 部署在对应云上时，用 `DMM_SERVICES_JSON` 把自己的地址加进来即可。

## 添加或修改镜像源

编辑 [config.php](config.php) 中的 `$services` 数组：

```php
[
    'name'        => '自建镜像',
    'url'         => 'https://mirror.example.com',
    'provider'    => '自建',
    'description' => '内部 Harbor 加速地址',
    'region'      => 'public',              // public 或 vpc
    'note'        => '',                    // 需要特别说明时填写
    'probe'       => 'https://mirror.example.com/v2/',  // 可省略，默认 url + /v2/
],
```

或用环境变量整体覆盖（适合容器部署，无需重建镜像）：

```env
DMM_SERVICES_JSON=[{"name":"自建镜像","url":"https://mirror.example.com","provider":"自建"}]
```

配置会在加载时校验：URL 必须合法、`id` 与 `url` 不能重复，不合法时接口直接返回 `CONFIG_ERROR` 而不是输出半残数据。

## 技术栈

- **前端**：原生 HTML / CSS / JavaScript（ES6+），Tailwind CSS 与 Font Awesome 走 CDN
- **后端**：PHP 7.4+（容器内为 8.2），`curl_multi` 并发探测，文件缓存
- **部署**：Docker Compose（nginx + php-fpm），或任意 PHP 主机

## 安全设计

- 探测目标来自服务端白名单，`check_service` 只接受列表中已登记的 URL，避免被当作 SSRF 跳板
- 开启 TLS 证书校验（v1.x 曾关闭）
- nginx 层除 `api.php` 外拒绝所有 `.php`，并屏蔽 `config.php`、`lib.php`、`docker-compose.yml` 等内部文件
- 输出安全响应头：`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、`Permissions-Policy`、CSP
- 前端渲染全部使用 `textContent` / `createElement`，不拼接 `innerHTML`，避免镜像源信息引入 XSS
- 容器以非 root 的 `www-data` 运行 PHP-FPM，并启用 `no-new-privileges`
- `DMM_DEBUG=0` 时错误详情不返回给客户端，只写日志

## 贡献指南

1. Fork 本项目
2. 创建特性分支（`git checkout -b feature/AmazingFeature`）
3. 提交更改（`git commit -m 'Add some AmazingFeature'`）
4. 推送到分支（`git push origin feature/AmazingFeature`）
5. 开启 Pull Request

提交前建议先跑一遍：

```bash
php selftest.php            # 环境、配置与探测自检
php selftest.php --offline  # 只检查环境与配置，不联网
php check_config_errors.php # 配置校验逻辑自检
node dom_smoke.mjs          # 前端渲染冒烟测试
```

以上检查（外加镜像构建与接口冒烟）已配置在 [`.github/workflows/ci.yml`](.github/workflows/ci.yml) 中，
提交 PR 后会自动执行。

## 许可证

本项目采用 MIT 许可证，详见 [LICENSE](LICENSE)。

## 联系方式

- 项目地址：[GitHub](https://github.com/mcwlgzs/docker-mirror-monitor)
- 问题反馈：[Issues](https://github.com/mcwlgzs/docker-mirror-monitor/issues)
- 邮箱：mcwlgzs@qq.com

---

**免责声明**：本项目的探测结果反映的是**运行本服务的服务器**在探测时刻的网络状况，仅供排查参考。镜像源的可用性会随时间与网络环境变化，请以自己机器上的 `docker pull` 实际结果为准。
