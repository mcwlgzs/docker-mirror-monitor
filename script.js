// Docker 镜像加速服务监控 - 前端逻辑

// 服务列表（从后端获取，本地作为备用）
let dockerServices = [
    { name: '中科大镜像站', url: 'https://docker.mirrors.ustc.edu.cn', provider: 'USTC', description: '中国科学技术大学开源软件镜像站' },
    { name: '阿里云镜像', url: 'https://registry.cn-hangzhou.aliyuncs.com', provider: '阿里云', description: '阿里云容器镜像服务' },
    { name: '腾讯云镜像', url: 'https://mirror.ccs.tencentyun.com', provider: '腾讯云', description: '腾讯云容器镜像服务' },
    { name: '华为云镜像', url: 'https://swr.cn-north-1.myhuaweicloud.com', provider: '华为云', description: '华为云软件开发生产线' },
    { name: '上海交大镜像', url: 'https://docker.mirrors.sjtug.sjtu.edu.cn', provider: '上海交大', description: '上海交通大学软件源镜像服务' },
    { name: '南京大学镜像', url: 'https://docker.nju.edu.cn', provider: '南京大学', description: '南京大学开源镜像站' },
    { name: '毫秒镜像', url: 'https://docker.1ms.run', provider: '木雷坞', description: '毫秒镜像 CloudFlare 加速' },
    { name: '1Panel 镜像', url: 'https://docker.1panel.live', provider: '1Panel', description: '1Panel CloudFlare 镜像源' },
    { name: '耗子面板', url: 'https://hub.rat.dev', provider: '耗子面板', description: '耗子面板 CloudFlare 镜像' },
    { name: 'DockerProxy', url: 'https://dockerproxy.net', provider: 'DockerProxy', description: 'DockerProxy Oracle CDN' },
    { name: '科技 lion', url: 'https://docker.kejilion.pro', provider: '科技lion', description: '自媒体 UP 主 Nginx 镜像' },
    { name: 'atomhub', url: 'https://atomhub.openatom.cn', provider: '开放原子', description: '开放原子开源基金会镜像' },
    { name: 'Docker Proxy', url: 'https://dockerpull.com', provider: 'DockerPull', description: 'Docker 镜像代理服务' },
    { name: 'Docker Hub 代理', url: 'https://hub.docker.com', provider: 'Docker官方', description: 'Docker Hub 官方源' }
];

// 服务状态数据
let serviceStatus = {};

// 排序和筛选状态
let currentSort = { field: null, order: 'asc' };
let currentFilter = 'all'; // all, fast, fair, slow, error

// 初始化页面
document.addEventListener('DOMContentLoaded', function () {
    loadServicesFromBackend();
    updateLastUpdateTime();

    // 绑定刷新按钮事件
    document.getElementById('refreshBtn').addEventListener('click', function () {
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>刷新中...';
        this.disabled = true;

        checkAllServices().then(() => {
            this.innerHTML = '<i class="fas fa-sync-alt mr-2"></i><span class="hidden sm:inline">刷新</span><span class="sm:hidden">更新</span>';
            this.disabled = false;
            updateLastUpdateTime();
        });
    });

    // 绑定筛选按钮事件
    document.querySelectorAll('[data-filter]').forEach(btn => {
        btn.addEventListener('click', function () {
            currentFilter = this.dataset.filter;
            document.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active-filter'));
            this.classList.add('active-filter');
            renderServiceTable();
        });
    });

    // 绑定排序事件
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

    // 设置定时刷新（每5分钟）
    setInterval(() => {
        checkAllServices();
        updateLastUpdateTime();
    }, 5 * 60 * 1000);

    // 初始化深色模式
    initThemeToggle();
    // 初始化返回顶部按钮
    initBackToTop();
});

// 从后端加载服务列表
async function loadServicesFromBackend() {
    try {
        const response = await fetch('./api.php?action=get_services');
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.data && result.data.length > 0) {
                dockerServices = result.data;
                console.log('已从后端加载服务列表，共', dockerServices.length, '个服务');
            }
        }
    } catch (e) {
        console.warn('从后端加载服务列表失败，使用本地备用列表:', e);
    }

    // 无论是否从后端加载成功，都初始化并开始检测
    initializeServices();
    checkAllServices();
}

// 初始化服务列表
function initializeServices() {
    dockerServices.forEach(service => {
        serviceStatus[service.url] = {
            status: 'checking',
            responseTime: 0,
            lastCheck: null
        };
    });
    renderServiceTable();
    updateStatusCounts();
}

// 检查单个服务状态
async function checkServiceStatus(service) {
    try {
        const response = await fetch('./api.php?action=check_service', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: service.url })
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const result = await response.json();

        serviceStatus[service.url] = {
            status: result.data ? result.data.status : 'error',
            responseTime: result.data ? result.data.responseTime : 0,
            lastCheck: new Date(),
            error: result.data ? result.data.error : null
        };
    } catch (error) {
        console.error(`Error checking ${service.name}:`, error);
        serviceStatus[service.url] = {
            status: 'error',
            responseTime: 0,
            lastCheck: new Date(),
            error: error.message
        };
    }
}

// 检查所有服务 - 多级回退
async function checkAllServices() {
    // 1) 优先尝试 POST /check_all
    try {
        const response = await fetch('./api.php?action=check_all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ timeout: 10, use_cache: true })
        });

        if (response.ok) {
            const result = await response.json();
            if (result.success) {
                result.data.forEach(serviceData => {
                    serviceStatus[serviceData.url] = {
                        status: serviceData.status,
                        responseTime: serviceData.responseTime,
                        lastCheck: new Date(serviceData.timestamp),
                        error: serviceData.error || null
                    };
                });
                renderServiceTable();
                updateStatusCounts();
                if (result.cached) {
                    console.log('使用缓存数据，缓存时间:', result.cache_time);
                } else {
                    console.log('检测完成，耗时:', result.check_time_ms + 'ms');
                }
                return;
            }
        }
    } catch (error) {
        console.warn('批量API失败，尝试回退 GET /quick_check:', error);
    }

    // 2) 回退到 GET /quick_check
    try {
        const res2 = await fetch('./api.php?action=quick_check');
        if (res2.ok) {
            const result2 = await res2.json();
            if (result2.success) {
                result2.data.forEach(serviceData => {
                    serviceStatus[serviceData.url] = {
                        status: serviceData.status,
                        responseTime: serviceData.responseTime,
                        lastCheck: new Date(serviceData.timestamp),
                        error: serviceData.error || null
                    };
                });
                renderServiceTable();
                updateStatusCounts();
                console.log('使用 quick_check 成功', result2.cached ? '(缓存)' : '');
                return;
            }
        }
    } catch (e) {
        console.warn('quick_check 也失败，尝试单个检测:', e);
    }

    // 3) 回退到单个检测
    try {
        const promises = dockerServices.map(service => checkServiceStatus(service));
        await Promise.all(promises);
        renderServiceTable();
        updateStatusCounts();
        const failures = Object.values(serviceStatus).filter(s => s.status === 'error').length;
        if (failures > dockerServices.length * 0.7) {
            throw new Error('后端不可用，触发演示模式');
        }
        return;
    } catch (e) {
        console.warn('单个检测失败，进入演示模式:', e);
    }

    // 4) 演示模式
    dockerServices.forEach(service => {
        const rt = Math.floor(Math.random() * 1800) + 80;
        let status;
        if (rt < 500) status = 'fast';
        else if (rt < 1000) status = 'fair';
        else if (rt < 2000) status = 'slow';
        else status = 'error';

        serviceStatus[service.url] = {
            status,
            responseTime: rt,
            lastCheck: new Date(),
            error: '演示模式（后端不可用）'
        };
    });
    showNotification('后端未运行，已进入演示模式', 'warning');
    renderServiceTable();
    updateStatusCounts();
}

// 获取排序和筛选后的服务列表
function getFilteredAndSortedServices() {
    let services = [...dockerServices];

    // 筛选
    if (currentFilter !== 'all') {
        services = services.filter(service => {
            const status = serviceStatus[service.url];
            if (!status) return false;
            if (currentFilter === 'normal') {
                return status.status === 'fast' || status.status === 'fair';
            }
            return status.status === currentFilter;
        });
    }

    // 排序
    if (currentSort.field) {
        services.sort((a, b) => {
            const statusA = serviceStatus[a.url] || {};
            const statusB = serviceStatus[b.url] || {};
            let valA, valB;

            switch (currentSort.field) {
                case 'provider':
                    valA = a.provider;
                    valB = b.provider;
                    break;
                case 'status':
                    const statusOrder = { fast: 0, fair: 1, slow: 2, error: 3, checking: 4 };
                    valA = statusOrder[statusA.status] ?? 5;
                    valB = statusOrder[statusB.status] ?? 5;
                    break;
                case 'responseTime':
                    valA = statusA.responseTime || 99999;
                    valB = statusB.responseTime || 99999;
                    break;
                default:
                    return 0;
            }

            if (valA < valB) return currentSort.order === 'asc' ? -1 : 1;
            if (valA > valB) return currentSort.order === 'asc' ? 1 : -1;
            return 0;
        });
    }

    return services;
}

// 更新排序图标
function updateSortIcons() {
    document.querySelectorAll('[data-sort]').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (!icon) return;
        const field = th.dataset.sort;
        if (currentSort.field === field) {
            icon.className = 'sort-icon fas fa-sort-' + (currentSort.order === 'asc' ? 'up' : 'down') + ' ml-1 text-apple-blue';
        } else {
            icon.className = 'sort-icon fas fa-sort ml-1 text-apple-gray opacity-50';
        }
    });
}

// 渲染服务状态表格
function renderServiceTable() {
    const tableBody = document.getElementById('serviceTable');
    tableBody.innerHTML = '';

    const services = getFilteredAndSortedServices();

    if (services.length === 0) {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="5" class="px-6 py-8 text-center text-apple-gray">没有匹配的服务</td>';
        tableBody.appendChild(row);
        return;
    }

    services.forEach(service => {
        const status = serviceStatus[service.url];
        const row = createServiceRow(service, status);
        tableBody.appendChild(row);
    });
}

// 创建服务行
function createServiceRow(service, status) {
    const row = document.createElement('tr');
    row.className = 'hover:bg-gray-50/50 transition-colors duration-200 border-b border-apple-border last:border-b-0';

    const statusInfo = getStatusInfo(status.status);
    const responseTimeText = status.responseTime > 0 ? `${status.responseTime}ms` : '--';
    const providerIcon = getProviderIcon(service.provider);

    row.innerHTML = `
        <td class="px-3 md:px-6 py-3 md:py-5 whitespace-nowrap">
            <div class="flex items-center space-x-2 md:space-x-3">
                <div class="w-8 h-8 md:w-10 md:h-10 ${providerIcon.bgClass} rounded-apple flex items-center justify-center">
                    <i class="${providerIcon.icon} ${providerIcon.textClass} text-xs md:text-sm"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-xs md:text-sm font-semibold text-gray-900 truncate">${service.provider}</div>
                    <div class="text-xs text-apple-gray truncate hidden sm:block">${service.description}</div>
                </div>
            </div>
        </td>
        <td class="px-3 md:px-6 py-3 md:py-5">
            <div class="text-xs md:text-sm text-gray-900 font-mono bg-gray-50 px-2 md:px-3 py-1 rounded-apple border break-words">
                ${service.url}
            </div>
        </td>
        <td class="px-3 md:px-6 py-3 md:py-5 whitespace-nowrap">
            <span class="inline-flex items-center px-2 md:px-3 py-1 rounded-apple text-xs font-medium ${statusInfo.bgClass} ${statusInfo.textClass}">
                ${statusInfo.text}
            </span>
        </td>
        <td class="px-3 md:px-6 py-3 md:py-5 whitespace-nowrap">
            <div class="text-xs md:text-sm font-medium text-gray-900">${responseTimeText}</div>
            ${status.responseTime > 0 ? `<div class="text-xs text-apple-gray hidden sm:block">${getResponseTimeLevel(status.responseTime)}</div>` : ''}
        </td>
        <td class="px-3 md:px-6 py-3 md:py-5 whitespace-nowrap">
            <button onclick="copyToClipboard('${service.url}')" class="bg-apple-blue bg-opacity-10 text-apple-blue hover:bg-opacity-20 px-3 md:px-4 py-1.5 md:py-2 rounded-apple text-xs md:text-sm font-medium transition-colors duration-200">
                <i class="fas fa-copy mr-1 md:mr-2"></i>复制
            </button>
        </td>
    `;

    return row;
}

// 获取状态信息（统一术语，移除 healthy 死代码）
function getStatusInfo(status) {
    switch (status) {
        case 'fast':
            return { text: '快速', bgClass: 'bg-apple-green bg-opacity-10', textClass: 'text-apple-green' };
        case 'fair':
            return { text: '一般', bgClass: 'bg-blue-100', textClass: 'text-blue-600' };
        case 'slow':
            return { text: '缓慢', bgClass: 'bg-apple-orange bg-opacity-10', textClass: 'text-apple-orange' };
        case 'error':
            return { text: '异常', bgClass: 'bg-apple-red bg-opacity-10', textClass: 'text-apple-red' };
        default:
            return { text: '检查中', bgClass: 'bg-apple-gray bg-opacity-10', textClass: 'text-apple-gray' };
    }
}

// 获取提供商图标
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
    };

    return iconMap[provider] || { icon: 'fas fa-server', bgClass: 'bg-gray-100', textClass: 'text-gray-600' };
}

// 获取响应时间级别
function getResponseTimeLevel(responseTime) {
    if (responseTime < 500) return '极快';
    if (responseTime < 1000) return '快速';
    if (responseTime < 2000) return '正常';
    return '缓慢';
}

// 更新状态统计（统一术语：fast+fair=正常，slow=缓慢，error=异常）
function updateStatusCounts() {
    let normalCount = 0;
    let slowCount = 0;
    let errorCount = 0;
    const total = dockerServices.length;

    Object.values(serviceStatus).forEach(status => {
        switch (status.status) {
            case 'fast':
            case 'fair':
                normalCount++;
                break;
            case 'slow':
                slowCount++;
                break;
            case 'error':
                errorCount++;
                break;
        }
    });

    document.getElementById('normalCount').textContent = normalCount;
    document.getElementById('slowCount').textContent = slowCount;
    document.getElementById('errorCount').textContent = errorCount;
    document.getElementById('totalCount').textContent = total;

    const normalProgress = document.getElementById('normalProgress');
    const slowProgress = document.getElementById('slowProgress');
    const errorProgress = document.getElementById('errorProgress');

    if (normalProgress) normalProgress.style.width = `${(normalCount / total) * 100}%`;
    if (slowProgress) slowProgress.style.width = `${(slowCount / total) * 100}%`;
    if (errorProgress) errorProgress.style.width = `${(errorCount / total) * 100}%`;
}

// 更新最后更新时间
function updateLastUpdateTime() {
    const now = new Date();
    document.getElementById('lastUpdate').textContent = now.toLocaleString('zh-CN');
}

// 复制到剪贴板
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showNotification('已复制到剪贴板: ' + text, 'success');
    }).catch(() => {
        showNotification('复制失败，请手动复制', 'error');
    });
}

// 显示通知
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-20 right-6 p-4 rounded-apple-lg shadow-apple-xl z-50 ${getNotificationClass(type)} transform translate-x-full transition-transform duration-300`;
    notification.innerHTML = `
        <div class="flex items-center space-x-3">
            <div class="w-8 h-8 rounded-apple flex items-center justify-center ${getNotificationIconBg(type)}">
                <i class="${getNotificationIcon(type)} text-white text-sm"></i>
            </div>
            <div>
                <div class="font-medium text-gray-900">${getNotificationTitle(type)}</div>
                <div class="text-sm text-apple-gray">${message}</div>
            </div>
        </div>
    `;

    document.body.appendChild(notification);

    setTimeout(() => { notification.classList.remove('translate-x-full'); }, 100);
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => { notification.remove(); }, 300);
    }, 3000);
}

function getNotificationClass() {
    return 'bg-white border border-apple-border';
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
        default: return '信息';
    }
}

// 初始化深色模式切换
function initThemeToggle() {
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const html = document.documentElement;

    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
        html.classList.add('dark');
        themeIcon.className = 'fas fa-sun';
    } else {
        html.classList.remove('dark');
        themeIcon.className = 'fas fa-moon';
    }

    themeToggle.addEventListener('click', function () {
        html.classList.toggle('dark');
        if (html.classList.contains('dark')) {
            themeIcon.className = 'fas fa-sun';
            localStorage.setItem('theme', 'dark');
        } else {
            themeIcon.className = 'fas fa-moon';
            localStorage.setItem('theme', 'light');
        }
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (!localStorage.getItem('theme')) {
            if (e.matches) {
                html.classList.add('dark');
                themeIcon.className = 'fas fa-sun';
            } else {
                html.classList.remove('dark');
                themeIcon.className = 'fas fa-moon';
            }
        }
    });
}

// 初始化返回顶部按钮
function initBackToTop() {
    const backToTopBtn = document.getElementById('backToTop');
    let ticking = false;

    function updateBackToTop() {
        if (window.pageYOffset > 400) {
            backToTopBtn.classList.remove('hidden');
        } else {
            backToTopBtn.classList.add('hidden');
        }
        ticking = false;
    }

    window.addEventListener('scroll', function () {
        if (!ticking) {
            requestAnimationFrame(updateBackToTop);
            ticking = true;
        }
    });

    backToTopBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}
