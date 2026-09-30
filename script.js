/**
 * Docker 镜像加速服务监控 - 前端逻辑
 *
 * 设计原则（v2.0）：
 *   1. 绝不伪造数据。后端不可用时如实展示错误，不再用随机数模拟延迟。
 *   2. 状态与统计只基于「本次真实探测到的服务」，未探测的服务保持"待检测"，
 *      不会因为部分检测而把其余服务误标为正常。
 *   3. 所有来自后端的文本一律通过 textContent 写入，避免 XSS。
 */

'use strict';

// ==================== 全局状态 ====================

/** @type {Array<{id:string,name:string,url:string,provider:string,description:string,region:string,note:string}>} */
let dockerServices = [];

/** url -> 探测结果 */
let serviceStatus = {};

/** 站点/探测元信息，来自 get_services */
let appMeta = { name: 'Docker 镜像加速服务监控', version: '', site_url: '' };

let currentSort = { field: null, order: 'asc' };
let currentFilter = 'all';

/** 是否已拿到服务列表（未拿到时不允许检测） */
let servicesLoaded = false;

/** 是否正在检测，防止重复请求 */
let isChecking = false;

/** 自动刷新定时器 */
let autoRefreshTimer = null;

const AUTO_REFRESH_MS = 5 * 60 * 1000;
const REQUEST_TIMEOUT_MS = 30000;

// ==================== 工具函数 ====================

/**
 * 统一的 fetch 包装：超时 + 统一错误对象，避免请求悬挂导致 UI 卡死。
 * @param {string} url
 * @param {RequestInit} [options]
 * @returns {Promise<any>}
 */
async function apiFetch(url, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        const text = await response.text();

        let payload = null;
        try {
            payload = text ? JSON.parse(text) : null;
        } catch (e) {
            throw new Error('后端返回了非 JSON 内容（HTTP ' + response.status + '）');
        }

        if (!response.ok) {
            const msg = (payload && (payload.error || payload.errorCode)) || ('HTTP ' + response.status);
            const err = new Error(msg);
            err.status = response.status;
            err.payload = payload;
            throw err;
        }

        return payload;
    } finally {
        clearTimeout(timer);
    }
}

/**
 * 格式化时间为本地字符串。
 * @param {string|Date|null} value
 */
function formatTime(value) {
    if (!value) return '--';
    const d = value instanceof Date ? value : new Date(value);
    if (isNaN(d.getTime())) return '--';
    return d.toLocaleString('zh-CN', { hour12: false });
}

/**
 * 相对时间描述。
 * @param {number} seconds
 */
function formatAge(seconds) {
    if (typeof seconds !== 'number' || seconds < 0) return '';
    if (seconds < 60) return seconds + ' 秒前';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' 分钟前';
    return Math.floor(seconds / 3600) + ' 小时前';
}

// ==================== 初始化 ====================

document.addEventListener('DOMContentLoaded', function () {
    bindEvents();
    initThemeToggle();
    initBackToTop();
    updateLastUpdateTime(null);

    loadServices();
});

function bindEvents() {
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        // 刷新按钮强制重新探测（force=1），不受服务端缓存影响
        refreshBtn.addEventListener('click', function () {
            checkAllServices({ force: true });
        });
    }

    document.querySelectorAll('[data-filter]').forEach(btn => {
        btn.addEventListener('click', function () {
            currentFilter = this.dataset.filter;
            document.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active-filter'));
            this.classList.add('active-filter');
            renderServiceTable();
        });
    });

    document.querySelectorAll('[data-sort]').forEach(th => {
        th.addEventListener('click', function () {
            const field = this.dataset.sort;
            if (currentSort.field === field) {
                currentSort.order = currentSort.order === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.field = field;
                currentSort.order = 'asc';
            }
            updateSortIcons();
            renderServiceTable();
        });
    });

    // 表格内的复制按钮采用事件委托，避免把 URL 拼进 inline onclick
    const tbody = document.getElementById('serviceTable');
    if (tbody) {
        tbody.addEventListener('click', function (event) {
            const btn = event.target.closest('[data-copy-url]');
            if (!btn) return;
            copyToClipboard(btn.dataset.copyUrl);
        });
    }

    // 页面重新可见时，若数据已过期则自动刷新
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') return;
        const last = getLastCheckAt();
        if (last && Date.now() - last > AUTO_REFRESH_MS) {
            checkAllServices();
        }
    });

    autoRefreshTimer = setInterval(function () {
        if (document.visibilityState === 'visible') {
            checkAllServices();
        }
    }, AUTO_REFRESH_MS);
}

/** 最近一次成功探测的时间戳 */
function getLastCheckAt() {
    let latest = 0;
    Object.values(serviceStatus).forEach(s => {
        if (s && typeof s.checkedAt === 'number' && s.checkedAt > latest) {
            latest = s.checkedAt;
        }
    });
    return latest || null;
}

// ==================== 服务列表加载 ====================

async function loadServices() {
    try {
        const result = await apiFetch('./api.php?action=get_services');

        if (!result || !result.success || !Array.isArray(result.data) || result.data.length === 0) {
            throw new Error('后端未返回有效的服务列表');
        }

        dockerServices = result.data;
        servicesLoaded = true;

        if (result.app) {
            appMeta = Object.assign(appMeta, result.app);
            applyBranding();
        }

        if (result.probe && typeof result.probe.timeout !== 'undefined') {
            appMeta.probeTimeout = Number(result.probe.timeout) || 0;
            applyBranding();
        }

        initializeServices();
        checkAllServices();
    } catch (error) {
        console.error('加载服务列表失败:', error);
        showFatalError(
            '无法连接后端接口',
            error && error.message ? error.message : String(error),
            '请确认 api.php 可访问、PHP 已启用 curl 扩展，且数据目录可写。'
        );
    }
}

/** 把站点名称应用到页面标题与品牌区域 */
function applyBranding() {
    if (appMeta.name && document.title.indexOf('Docker') !== -1) {
        // 保留原标题中的描述部分，仅替换站点名前缀
        document.title = appMeta.name + ' - 实时监控国内 Docker Hub 镜像加速服务状态';
    }

    const brandName = document.querySelector('[data-brand-name]');
    if (brandName && appMeta.name) {
        brandName.textContent = appMeta.name;
    }

    const metaEl = document.querySelector('[data-app-meta]');
    if (metaEl) {
        const parts = [];
        if (appMeta.version) parts.push('版本 ' + appMeta.version);
        if (appMeta.probeTimeout) parts.push('探测超时 ' + appMeta.probeTimeout + 's');
        metaEl.textContent = parts.join(' · ');
    }
}

/** 初始化每个服务的占位状态 */
function initializeServices() {
    const next = {};
    dockerServices.forEach(service => {
        next[service.url] = {
            status: 'pending',
            responseTime: 0,
            error: '',
            checkedAt: null,
        };
    });
    serviceStatus = next;
    renderServiceTable();
    updateStatusCounts();
}

// ==================== 探测 ====================

/**
 * 检测全部服务。
 * 后端已实现并发探测 + 缓存，前端只需一次请求，失败就如实提示。
 * @param {{force?: boolean}} [opts]
 */
async function checkAllServices(opts = {}) {
    if (!servicesLoaded) return;
    if (isChecking) return;

    isChecking = true;
    setRefreshButtonState(true);

    // 未检测过的服务保持 pending，已有结果的先沿用（避免闪烁）
    dockerServices.forEach(service => {
        const s = serviceStatus[service.url];
        if (!s || !s.checkedAt) {
            serviceStatus[service.url] = {
                status: 'checking',
                responseTime: 0,
                error: '',
                checkedAt: null,
            };
        }
    });
    renderServiceTable();

    const action = 'check_all';
    const url = './api.php?action=' + action + (opts.force ? '&force=1' : '');

    try {
        const result = await apiFetch(url);

        if (!result || !result.success || !Array.isArray(result.data)) {
            throw new Error((result && result.error) || '后端返回数据格式异常');
        }

        // 用后端返回的真实结果覆盖；未在本次返回中的服务标记为「未检测」
        const returned = new Set();

        result.data.forEach(item => {
            if (!item || !item.url) return;
            returned.add(item.url);
            serviceStatus[item.url] = {
                status: item.status || 'error',
                responseTime: Number(item.responseTime) || 0,
                httpCode: Number(item.httpCode) || 0,
                error: item.error || '',
                errorCode: item.errorCode || '',
                method: item.method || '',
                ip: item.ip || '',
                authRequired: !!item.authRequired,
                reachable: !!item.reachable,
                checkedAt: Date.now(),
            };
        });

        dockerServices.forEach(service => {
            if (!returned.has(service.url)) {
                serviceStatus[service.url] = {
                    status: 'pending',
                    responseTime: 0,
                    error: '本次未检测',
                    checkedAt: null,
                };
            }
        });

        renderServiceTable();
        updateStatusCounts();
        updateLastUpdateTime(result.timestamp);
        updateProbeMeta(result);
        renderConfigGuide();

        if (result.cached) {
            showNotification(
                '数据来自服务端缓存（' + formatAge(Number(result.cache_age) || 0) + '）',
                'info'
            );
        }
    } catch (error) {
        console.error('检测失败:', error);

        // 明确标记为不可用，绝不填充模拟数据
        dockerServices.forEach(service => {
            serviceStatus[service.url] = {
                status: 'error',
                responseTime: 0,
                error: '检测失败：' + (error && error.message ? error.message : '未知错误'),
                errorCode: 'FRONTEND_REQUEST_FAILED',
                checkedAt: Date.now(),
            };
        });

        renderServiceTable();
        updateStatusCounts();
        updateLastUpdateTime(null);
        showNotification('检测失败：' + (error && error.message ? error.message : '无法连接后端'), 'error');
    } finally {
        isChecking = false;
        setRefreshButtonState(false);
    }
}

/** 更新探测元信息展示（引擎 / 耗时） */
function updateProbeMeta(result) {
    const el = document.getElementById('probeMeta');
    if (!el) return;

    const parts = [];
    if (typeof result.check_time_ms === 'number' && !result.cached) {
        parts.push('本次探测耗时 ' + result.check_time_ms + 'ms');
    }
    if (result.engine === 'registry-handshake') {
        parts.push('探测方式：Registry /v2/ 握手');
    }
    el.textContent = parts.join(' · ');
}

/**
 * 按本次真实检测结果生成配置指南里的 registry-mirrors 列表。
 *
 * v1.x 的指南写死了 USTC / 网易等已经下线的地址，用户照着配只会失败，
 * 这也是 issue #1「列出的镜像源真的可用吗」的一部分。这里只推荐
 * 「本次探测确认可用且响应不超过 fair 阈值」的公共服务，VPC 专用源不推荐给个人用户。
 */
function renderConfigGuide() {
    const usable = dockerServices
        .map(service => ({ service: service, status: serviceStatus[service.url] }))
        .filter(entry => {
            const s = entry.status;
            if (!s) return false;
            if (entry.service.region === 'vpc') return false;
            if (s.reachable === false) return false;
            return (s.status === 'fast' || s.status === 'fair') && s.responseTime > 0;
        })
        .sort((a, b) => a.status.responseTime - b.status.responseTime)
        .slice(0, 3);

    if (!usable.length) {
        // 一个可用的都没有时不编造推荐，明确说明
        const noteEl = document.getElementById('guideNote');
        if (noteEl) {
            noteEl.textContent = '本次检测没有发现可用的公共镜像源，请稍后重试或自建镜像加速服务。';
        }
        return;
    }

    const urls = usable.map(entry => entry.service.url);

    const linesHost = document.querySelector('[data-mirror-lines]');
    if (linesHost) {
        linesHost.textContent = '';
        urls.forEach((url, index) => {
            const line = document.createElement('div');
            line.className = 'code-line';
            const value = document.createElement('span');
            value.className = 'code-value';
            value.textContent = '"' + url + '"' + (index < urls.length - 1 ? ',' : '');
            line.appendChild(value);
            linesHost.appendChild(line);
        });
    }

    const winList = document.getElementById('windowsMirrorList');
    if (winList) {
        winList.textContent = '';
        urls.forEach(url => {
            const code = document.createElement('code');
            code.className = 'block text-xs bg-white rounded px-2 py-1 text-gray-800';
            code.textContent = url;
            winList.appendChild(code);
        });
    }

    const noteEl = document.getElementById('guideNote');
    if (noteEl) {
        const fastest = usable[0];
        noteEl.textContent = '以上地址来自本次检测结果（最快：' + fastest.service.name +
            '，' + fastest.status.responseTime + 'ms）。镜像源随时可能变动，请以页面实时状态为准。';
    }
}

function setRefreshButtonState(loading) {
    const btn = document.getElementById('refreshBtn');
    if (!btn) return;

    btn.disabled = loading;
    btn.setAttribute('aria-busy', loading ? 'true' : 'false');

    if (loading) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2" aria-hidden="true"></i>检测中...';
    } else {
        btn.innerHTML = '<i class="fas fa-sync-alt mr-2" aria-hidden="true"></i><span class="hidden sm:inline">刷新</span><span class="sm:hidden">更新</span>';
    }
}

// ==================== 渲染 ====================

function getFilteredAndSortedServices() {
    let services = dockerServices.slice();

    if (currentFilter !== 'all') {
        services = services.filter(service => {
            const status = serviceStatus[service.url];
            if (!status) return false;
            if (currentFilter === 'normal') {
                return status.status === 'fast' || status.status === 'fair';
            }
            if (currentFilter === 'slow') {
                // 「缓慢」筛选同时包含握手成功但极慢的服务
                return status.status === 'slow' || status.status === 'very_slow';
            }
            return status.status === currentFilter;
        });
    }

    if (currentSort.field) {
        const statusOrder = { fast: 0, fair: 1, slow: 2, very_slow: 3, error: 4, checking: 5, pending: 6 };
        const dir = currentSort.order === 'asc' ? 1 : -1;

        services.sort((a, b) => {
            const sa = serviceStatus[a.url] || {};
            const sb = serviceStatus[b.url] || {};
            let va, vb;

            switch (currentSort.field) {
                case 'provider':
                    va = a.provider || '';
                    vb = b.provider || '';
                    break;
                case 'status':
                    va = statusOrder[sa.status] ?? 9;
                    vb = statusOrder[sb.status] ?? 9;
                    break;
                case 'responseTime':
                    // 未探测/失败的排到最后
                    va = (sa.checkedAt && sa.responseTime > 0) ? sa.responseTime : Number.MAX_SAFE_INTEGER;
                    vb = (sb.checkedAt && sb.responseTime > 0) ? sb.responseTime : Number.MAX_SAFE_INTEGER;
                    break;
                default:
                    return 0;
            }

            if (va < vb) return -1 * dir;
            if (va > vb) return 1 * dir;
            return 0;
        });
    }

    return services;
}

function updateSortIcons() {
    document.querySelectorAll('[data-sort]').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (!icon) return;

        if (currentSort.field === th.dataset.sort) {
            icon.className = 'sort-icon fas fa-sort-' + (currentSort.order === 'asc' ? 'up' : 'down') + ' ml-1 text-apple-blue';
            th.setAttribute('aria-sort', currentSort.order === 'asc' ? 'ascending' : 'descending');
        } else {
            icon.className = 'sort-icon fas fa-sort ml-1 text-apple-gray opacity-50';
            th.setAttribute('aria-sort', 'none');
        }
    });
}

function renderServiceTable() {
    const tableBody = document.getElementById('serviceTable');
    if (!tableBody) return;

    tableBody.innerHTML = '';

    const services = getFilteredAndSortedServices();

    if (services.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'px-6 py-8 text-center text-apple-gray';
        cell.textContent = servicesLoaded ? '没有匹配的服务' : '正在加载服务列表...';
        row.appendChild(cell);
        tableBody.appendChild(row);
        return;
    }

    const fragment = document.createDocumentFragment();
    services.forEach(service => {
        fragment.appendChild(createServiceRow(service, serviceStatus[service.url]));
    });
    tableBody.appendChild(fragment);
}

/**
 * 创建一行。status 允许为 undefined（此时显示"待检测"），不再抛异常。
 * @param {object} service
 * @param {object|undefined} status
 */
function createServiceRow(service, status) {
    const row = document.createElement('tr');
    row.className = 'hover:bg-gray-50/50 transition-colors duration-200 border-b border-apple-border last:border-b-0';

    const state = normalizeStatus(status);
    const statusInfo = getStatusInfo(state.status);
    const providerIcon = getProviderIcon(service.provider);

    // ---- 服务商 ----
    const tdProvider = document.createElement('td');
    tdProvider.className = 'px-3 md:px-6 py-3 md:py-5 whitespace-nowrap';

    const providerWrap = document.createElement('div');
    providerWrap.className = 'flex items-center space-x-2 md:space-x-3';

    const iconBox = document.createElement('div');
    iconBox.className = 'w-8 h-8 md:w-10 md:h-10 ' + providerIcon.bgClass + ' rounded-apple flex items-center justify-center';
    const icon = document.createElement('i');
    icon.className = providerIcon.icon + ' ' + providerIcon.textClass + ' text-xs md:text-sm';
    icon.setAttribute('aria-hidden', 'true');
    iconBox.appendChild(icon);

    const nameWrap = document.createElement('div');
    nameWrap.className = 'min-w-0 flex-1';

    const nameEl = document.createElement('div');
    nameEl.className = 'text-xs md:text-sm font-semibold text-gray-900 truncate';
    // 服务商已收录时显示品牌名（更短更规整）；否则显示服务全名，避免把自定义标识当品牌名显示
    const displayName = providerIcon.isKnown
        ? (service.provider || service.name || '未知')
        : (service.name || service.provider || '未知');
    nameEl.textContent = displayName;
    const fullName = service.name || service.provider || '';
    if (fullName && fullName !== displayName) {
        nameEl.title = fullName;
    }

    const descEl = document.createElement('div');
    descEl.className = 'text-xs text-apple-gray truncate hidden sm:block';
    descEl.textContent = service.description || '';

    nameWrap.appendChild(nameEl);
    nameWrap.appendChild(descEl);
    providerWrap.appendChild(iconBox);
    providerWrap.appendChild(nameWrap);
    tdProvider.appendChild(providerWrap);

    // ---- 地址 ----
    const tdUrl = document.createElement('td');
    tdUrl.className = 'px-3 md:px-6 py-3 md:py-5';

    const urlEl = document.createElement('div');
    urlEl.className = 'text-xs md:text-sm text-gray-900 font-mono bg-gray-50 px-2 md:px-3 py-1 rounded-apple border break-all';
    urlEl.textContent = service.url || '';

    tdUrl.appendChild(urlEl);

    // ---- 状态（含失败原因） ----
    const tdStatus = document.createElement('td');
    tdStatus.className = 'px-3 md:px-6 py-3 md:py-5 whitespace-nowrap';

    const badge = document.createElement('span');
    badge.className = 'inline-flex items-center px-2 md:px-3 py-1 rounded-apple text-xs font-medium ' + statusInfo.bgClass + ' ' + statusInfo.textClass;
    badge.textContent = statusInfo.text;

    tdStatus.appendChild(badge);

    if (state.error) {
        const errEl = document.createElement('div');
        errEl.className = 'text-xs text-apple-gray mt-1 max-w-[16rem] truncate';
        errEl.textContent = state.error;
        errEl.title = state.error;
        tdStatus.appendChild(errEl);
    }

    if (state.authRequired) {
        const authEl = document.createElement('div');
        authEl.className = 'text-xs text-apple-gray mt-1';
        authEl.textContent = '需鉴权（正常）';
        tdStatus.appendChild(authEl);
    }

    // ---- 响应时间 ----
    const tdTime = document.createElement('td');
    tdTime.className = 'px-3 md:px-6 py-3 md:py-5 whitespace-nowrap';

    const timeEl = document.createElement('div');
    timeEl.className = 'text-xs md:text-sm font-medium text-gray-900';
    timeEl.textContent = state.responseTime > 0 ? state.responseTime + 'ms' : '--';
    tdTime.appendChild(timeEl);

    if (state.responseTime > 0) {
        const levelEl = document.createElement('div');
        levelEl.className = 'text-xs text-apple-gray hidden sm:block';
        levelEl.textContent = getResponseTimeLevel(state.responseTime);
        tdTime.appendChild(levelEl);
    }

    if (state.httpCode > 0) {
        const codeEl = document.createElement('div');
        codeEl.className = 'text-xs text-apple-gray hidden sm:block';
        codeEl.textContent = 'HTTP ' + state.httpCode;
        tdTime.appendChild(codeEl);
    }

    // ---- 操作 ----
    const tdAction = document.createElement('td');
    tdAction.className = 'px-3 md:px-6 py-3 md:py-5 whitespace-nowrap';

    const copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.dataset.copyUrl = service.url || '';
    copyBtn.className = 'bg-apple-blue bg-opacity-10 text-apple-blue hover:bg-opacity-20 px-3 md:px-4 py-1.5 md:py-2 rounded-apple text-xs md:text-sm font-medium transition-colors duration-200';
    copyBtn.innerHTML = '<i class="fas fa-copy mr-1 md:mr-2" aria-hidden="true"></i>复制';
    copyBtn.setAttribute('aria-label', '复制 ' + (service.name || '') + ' 地址');

    tdAction.appendChild(copyBtn);

    row.appendChild(tdProvider);
    row.appendChild(tdUrl);
    row.appendChild(tdStatus);
    row.appendChild(tdTime);
    row.appendChild(tdAction);

    return row;
}

/** 把可能为 undefined 的状态收敛为可渲染对象 */
function normalizeStatus(status) {
    if (!status || typeof status !== 'object') {
        return {
            status: 'pending',
            responseTime: 0,
            httpCode: 0,
            error: '',
            authRequired: false,
            checkedAt: null,
        };
    }
    return {
        status: status.status || 'pending',
        responseTime: Number(status.responseTime) || 0,
        httpCode: Number(status.httpCode) || 0,
        error: status.error || '',
        authRequired: !!status.authRequired,
        checkedAt: status.checkedAt || null,
    };
}

function getStatusInfo(status) {
    switch (status) {
        case 'fast':
            return { text: '快速', bgClass: 'bg-apple-green bg-opacity-10', textClass: 'text-apple-green' };
        case 'fair':
            return { text: '一般', bgClass: 'bg-blue-100', textClass: 'text-blue-600' };
        case 'slow':
            return { text: '缓慢', bgClass: 'bg-apple-orange bg-opacity-10', textClass: 'text-apple-orange' };
        case 'very_slow':
            // 握手成功但耗时超过慢阈值：可用，只是很慢，不该显示为「异常」
            return { text: '极慢', bgClass: 'bg-apple-orange bg-opacity-10', textClass: 'text-apple-orange' };
        case 'error':
            return { text: '异常', bgClass: 'bg-apple-red bg-opacity-10', textClass: 'text-apple-red' };
        case 'checking':
            return { text: '检测中', bgClass: 'bg-apple-blue bg-opacity-10', textClass: 'text-apple-blue' };
        case 'pending':
        default:
            return { text: '待检测', bgClass: 'bg-apple-gray bg-opacity-10', textClass: 'text-apple-gray' };
    }
}

function getProviderIcon(provider) {
    const iconMap = {
        'USTC': { icon: 'fas fa-university', bgClass: 'bg-blue-100', textClass: 'text-blue-600' },
        '阿里云': { icon: 'fas fa-cloud-sun', bgClass: 'bg-orange-100', textClass: 'text-orange-600' },
        '腾讯云': { icon: 'fas fa-cloud-rain', bgClass: 'bg-blue-100', textClass: 'text-blue-600' },
        '华为云': { icon: 'fas fa-microchip', bgClass: 'bg-red-100', textClass: 'text-red-600' },
        '上海交大': { icon: 'fas fa-graduation-cap', bgClass: 'bg-indigo-100', textClass: 'text-indigo-600' },
        '南京大学': { icon: 'fas fa-book-open', bgClass: 'bg-green-100', textClass: 'text-green-600' },
        'Docker官方': { icon: 'fab fa-docker', bgClass: 'bg-blue-100', textClass: 'text-blue-600' },
        '木雷坞': { icon: 'fas fa-bolt', bgClass: 'bg-yellow-100', textClass: 'text-yellow-600' },
        '1Panel': { icon: 'fas fa-desktop', bgClass: 'bg-blue-100', textClass: 'text-blue-600' },
        '耗子面板': { icon: 'fas fa-mouse', bgClass: 'bg-gray-100', textClass: 'text-gray-600' },
        'DockerProxy': { icon: 'fas fa-network-wired', bgClass: 'bg-green-100', textClass: 'text-green-600' },
        '科技lion': { icon: 'fas fa-video', bgClass: 'bg-orange-100', textClass: 'text-orange-600' },
        '开放原子': { icon: 'fas fa-atom', bgClass: 'bg-purple-100', textClass: 'text-purple-600' },
        'DockerPull': { icon: 'fas fa-download', bgClass: 'bg-cyan-100', textClass: 'text-cyan-600' },
        'DaoCloud': { icon: 'fas fa-cube', bgClass: 'bg-sky-100', textClass: 'text-sky-600' },
        'Nat.tf': { icon: 'fas fa-globe', bgClass: 'bg-teal-100', textClass: 'text-teal-600' },
        '轩辕镜像': { icon: 'fas fa-shield-alt', bgClass: 'bg-purple-100', textClass: 'text-purple-600' },
        '简行镜像': { icon: 'fas fa-feather-alt', bgClass: 'bg-emerald-100', textClass: 'text-emerald-600' },
        'HubFast': { icon: 'fas fa-tachometer-alt', bgClass: 'bg-amber-100', textClass: 'text-amber-600' },
    };

    const known = Object.prototype.hasOwnProperty.call(iconMap, provider);
    if (known) {
        return Object.assign({ isKnown: true }, iconMap[provider]);
    }

    // 未收录的服务商：不要把 provider 字段直接显示给用户（可能是自定义的英文标识），
    // 由调用方回退到服务名称。
    return { isKnown: false, icon: 'fas fa-server', bgClass: 'bg-gray-100', textClass: 'text-gray-600' };
}

function getResponseTimeLevel(responseTime) {
    if (responseTime < 500) return '极快';
    if (responseTime < 1000) return '快速';
    if (responseTime < 2000) return '正常';
    return '缓慢';
}

/**
 * 更新统计。
 * 只统计「本次已探测」的服务，未探测的归入待检测，避免虚报正常数。
 */
function updateStatusCounts() {
    let normalCount = 0;
    let slowCount = 0;
    let errorCount = 0;
    let pendingCount = 0;

    dockerServices.forEach(service => {
        const state = normalizeStatus(serviceStatus[service.url]);
        switch (state.status) {
            case 'fast':
            case 'fair':
                normalCount++;
                break;
            case 'slow':
            case 'very_slow':
                slowCount++;
                break;
            case 'error':
                errorCount++;
                break;
            default:
                pendingCount++;
                break;
        }
    });

    const total = dockerServices.length;

    setText('normalCount', normalCount);
    setText('slowCount', slowCount);
    setText('errorCount', errorCount);
    setText('totalCount', total);

    const pendingEl = document.getElementById('pendingCount');
    if (pendingEl) {
        // 仅在存在待检测项时显示，避免干扰主视图
        pendingEl.textContent = pendingCount > 0 ? '待检测 ' + pendingCount : '';
        pendingEl.classList.toggle('hidden', pendingCount === 0);
    }

    setBarWidth('normalProgress', total, normalCount);
    setBarWidth('slowProgress', total, slowCount);
    setBarWidth('errorProgress', total, errorCount);
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = String(value);
}

/** 计算百分比，total 为 0 时安全返回 0% */
function setBarWidth(id, total, count) {
    const el = document.getElementById(id);
    if (!el) return;
    const pct = total > 0 ? (count / total) * 100 : 0;
    el.style.width = pct.toFixed(2) + '%';
}

function updateLastUpdateTime(value) {
    const el = document.getElementById('lastUpdate');
    if (el) {
        el.textContent = value ? formatTime(value) : '--';
    }
}

// ==================== 交互反馈 ====================

function copyToClipboard(text) {
    if (!text) return;

    const done = () => showNotification('已复制: ' + text, 'success');
    const fallback = () => {
        // 非 HTTPS / 无权限时的兜底方案
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
        } catch (e) {
            showNotification('复制失败，请手动复制', 'error');
        }
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else {
        fallback();
    }
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = 'fixed top-20 right-6 p-4 rounded-apple-lg shadow-apple-xl z-50 bg-white border border-apple-border transform translate-x-full transition-transform duration-300';
    notification.setAttribute('role', 'status');

    const wrap = document.createElement('div');
    wrap.className = 'flex items-center space-x-3';

    const iconBg = document.createElement('div');
    iconBg.className = 'w-8 h-8 rounded-apple flex items-center justify-center ' + getNotificationIconBg(type);

    const icon = document.createElement('i');
    icon.className = getNotificationIcon(type) + ' text-white text-sm';
    icon.setAttribute('aria-hidden', 'true');
    iconBg.appendChild(icon);

    const body = document.createElement('div');

    const title = document.createElement('div');
    title.className = 'font-medium text-gray-900';
    title.textContent = getNotificationTitle(type);

    const text = document.createElement('div');
    text.className = 'text-sm text-apple-gray';
    text.textContent = message;

    body.appendChild(title);
    body.appendChild(text);
    wrap.appendChild(iconBg);
    wrap.appendChild(body);
    notification.appendChild(wrap);
    document.body.appendChild(notification);

    setTimeout(() => notification.classList.remove('translate-x-full'), 100);
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => notification.remove(), 300);
    }, 4000);
}

function getNotificationIconBg(type) {
    switch (type) {
        case 'success': return 'bg-apple-green';
        case 'error': return 'bg-apple-red';
        case 'warning': return 'bg-apple-orange';
        default: return 'bg-apple-blue';
    }
}

function getNotificationIcon(type) {
    switch (type) {
        case 'success': return 'fas fa-check';
        case 'error': return 'fas fa-times';
        case 'warning': return 'fas fa-exclamation';
        default: return 'fas fa-info';
    }
}

function getNotificationTitle(type) {
    switch (type) {
        case 'success': return '成功';
        case 'error': return '错误';
        case 'warning': return '警告';
        default: return '提示';
    }
}

/** 后端完全不可用时的显著提示（替换掉旧版的"演示模式"） */
function showFatalError(title, detail, hint) {
    const tbody = document.getElementById('serviceTable');
    if (tbody) {
        tbody.innerHTML = '';
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'px-6 py-10 text-center';

        const h = document.createElement('div');
        h.className = 'text-apple-red font-semibold mb-2';
        h.textContent = title;

        const d = document.createElement('div');
        d.className = 'text-sm text-apple-gray mb-1';
        d.textContent = detail;

        const hintEl = document.createElement('div');
        hintEl.className = 'text-xs text-apple-gray';
        hintEl.textContent = hint;

        cell.appendChild(h);
        cell.appendChild(d);
        cell.appendChild(hintEl);
        row.appendChild(cell);
        tbody.appendChild(row);
    }

    updateStatusCounts();
    showNotification(title + '：' + detail, 'error');
}

// ==================== 主题与滚动 ====================

function initThemeToggle() {
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    if (!themeToggle || !themeIcon) return;

    const html = document.documentElement;

    const applyTheme = (dark) => {
        html.classList.toggle('dark', dark);
        themeIcon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
        themeToggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    };

    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(savedTheme === 'dark' || (!savedTheme && prefersDark));

    themeToggle.addEventListener('click', function () {
        const dark = !html.classList.contains('dark');
        applyTheme(dark);
        localStorage.setItem('theme', dark ? 'dark' : 'light');
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (!localStorage.getItem('theme')) {
            applyTheme(e.matches);
        }
    });
}

function initBackToTop() {
    const backToTopBtn = document.getElementById('backToTop');
    if (!backToTopBtn) return;

    let ticking = false;

    function update() {
        backToTopBtn.classList.toggle('hidden', window.pageYOffset <= 400);
        ticking = false;
    }

    window.addEventListener('scroll', function () {
        if (!ticking) {
            requestAnimationFrame(update);
            ticking = true;
        }
    });

    backToTopBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}
