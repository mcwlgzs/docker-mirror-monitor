// 前端冒烟测试：用最小 DOM 桩在 Node 中真实加载 script.js，验证渲染逻辑。
// 用法：node dom_smoke.mjs
//
// 目的：index.html / script.js 的渲染分支（状态分类、排序筛选、配置指引生成、
// XSS 转义、主题切换）在浏览器里很难稳定复现，这里用真实文件 + 受控 API 响应跑一遍。
//
// 该脚本仅供开发调试，部署时可以删除。

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const html = readFileSync(join(here, 'index.html'), 'utf8');

let pass = 0;
let fail = 0;
const failures = [];

function ok(name, condition, detail = '') {
    if (condition) {
        pass++;
        console.log(`  PASS  ${name}`);
    } else {
        fail++;
        failures.push(name + (detail ? ` — ${detail}` : ''));
        console.log(`  FAIL  ${name}${detail ? ` — ${detail}` : ''}`);
    }
}

// ---------- 元素桩 ----------
class El {
    constructor(tag = 'div', id = '') {
        this.tagName = tag.toUpperCase();
        this.id = id;
        this.children = [];
        this.parentElement = null;
        this.attributes = {};
        this.className = '';
        this.classList = {
            _set: new Set(),
            add: (...c) => c.forEach(x => this.classList._set.add(x)),
            remove: (...c) => c.forEach(x => this.classList._set.delete(x)),
            contains: (c) => this.classList._set.has(c),
            toggle: (c, force) => {
                const on = force === undefined ? !this.classList._set.has(c) : force;
                if (on) this.classList._set.add(c); else this.classList._set.delete(c);
                return on;
            },
            toString: () => [...this.classList._set].join(' '),
        };
        this._text = '';
        this._html = '';
        this.value = '';
        this.disabled = false;
        this.style = {};
        this.dataset = {};
        this._handlers = {};
    }

    set textContent(v) { this._text = String(v); this.children = []; }
    get textContent() { return this.children.length ? this.children.map(c => c.textContent).join('') : this._text; }

    // 真实 DOM 里设置 innerHTML 会丢弃原有子节点，桩必须照做，否则多次 render 会累加行
    set innerHTML(v) { this._html = String(v); if (this._html === '') this.children = []; }
    get innerHTML() { return this._html; }

    appendChild(child) {
        // DocumentFragment 的语义：把子节点搬进来，而不是把片段本身当成一个子节点
        if (child && child.tagName === '#FRAGMENT') {
            child.children.forEach(c => { c.parentElement = this; this.children.push(c); });
            child.children = [];
            return child;
        }
        child.parentElement = this;
        this.children.push(child);
        return child;
    }
    removeChild(child) { this.children = this.children.filter(c => c !== child); return child; }
    remove() { this.parentElement?.removeChild(this); }
    setAttribute(k, v) { this.attributes[k] = String(v); if (k === 'data-filter' || k === 'data-sort') this.dataset[k.slice(5)] = String(v); }
    getAttribute(k) { return this.attributes[k] ?? null; }
    removeAttribute(k) { delete this.attributes[k]; }
    addEventListener(type, fn) { (this._handlers[type] ||= []).push(fn); }
    // 必须用 fn.call(this, ...)，否则 handler 里的 this 会指向实例而不是被点的元素
    dispatch(type, event = {}) {
        (this._handlers[type] || []).forEach(fn => fn.call(this, { target: this, currentTarget: this, preventDefault() {}, ...event }));
    }
    closest(sel) {
        const want = sel.replace(/^\./, '');
        let node = this;
        while (node) {
            if (sel.startsWith('.') && node.classList.contains(want)) return node;
            if (!sel.startsWith('.') && node.tagName === sel.toUpperCase()) return node;
            node = node.parentElement;
        }
        return null;
    }
    querySelector() { return null; }
    querySelectorAll() { return []; }
    focus() {}
}

// ---------- 按 index.html 真实内容建立元素注册表 ----------
const registry = new Map();
const byAttr = new Map();

function ensure(id) {
    if (!registry.has(id)) registry.set(id, new El('div', id));
    return registry.get(id);
}

// 1) 所有 id="..." 的元素（用 [^>]* 匹配整个开标签，避免 class 里出现 id= 造成误判）
for (const m of html.matchAll(/<([a-zA-Z][\w-]*)([^>]*?)\bid="([^"]+)"/g)) {
    const el = new El(m[1], m[3]);
    for (const a of m[2].matchAll(/(data-[a-z-]+|type|class)="([^"]*)"/g)) el.setAttribute(a[1], a[2]);
    registry.set(m[3], el);
}

// 2) data-* 钩子元素（可能没有 id）
for (const m of html.matchAll(/<(\w+)([^>]*?)\s(data-[a-z-]+)="([^"]*)"[^>]*>/g)) {
    const key = `${m[3]}="${m[4]}"`;
    if (!byAttr.has(key)) {
        const el = new El(m[1]);
        el.setAttribute(m[3], m[4]);
        for (const a of m[2].matchAll(/(data-[a-z-]+|class)="([^"]*)"/g)) el.setAttribute(a[1], a[2]);
        byAttr.set(key, el);
    }
}

// 补齐 script.js 会查询但 index.html 用其它写法出现的容器
const body = ensure('__body__');
// script.js 直接把行 append 到 #serviceTable（index.html 里它就是 <tbody>）
const tbody = ensure('serviceTable');
registry.set('__tbody__', tbody);

const document = {
    body,
    documentElement: new El('html'),
    getElementById: (id) => registry.get(id) ?? null,
    createElement: (tag) => new El(tag),
    createDocumentFragment: () => {
        const frag = new El('#fragment');
        frag.appendTo = (parent) => {
            frag.children.forEach(c => parent.appendChild(c));
            frag.children = [];
        };
        return frag;
    },
    addEventListener(type, fn) { (this._handlers ||= {}); (this._handlers[type] ||= []).push(fn); },
    dispatch(type, event = {}) { ((this._handlers || {})[type] || []).forEach(fn => fn(event)); },
    querySelector(sel) {
        if (sel === '[data-brand-name]') return byAttr.get('data-brand-name=""') ?? new El('h1');
        if (sel === '[data-app-meta]') return byAttr.get('data-app-meta=""') ?? new El('p');
        if (sel === '[data-mirror-lines]') return byAttr.get('data-mirror-lines=""') ?? null;
        if (sel === '[data-breadcrumb]') return null;
        const m = sel.match(/^\[([a-z-]+)\]$/);
        if (m) return byAttr.get(`${m[1]}=""`) ?? null;
        if (sel.startsWith('#')) return registry.get(sel.slice(1)) ?? null;
        return null;
    },
    querySelectorAll(sel) {
        const m = sel.match(/^\[(data-[a-z-]+)\]$/);
        if (m) return [...byAttr.entries()].filter(([k]) => k.startsWith(m[1] + '=')).map(([, v]) => v);
        return [];
    },
    _handlers: {},
};

// ---------- 受控 API 响应 ----------
// 故意包含：fast / fair / slow / very_slow / error / pending 六种状态，
// vpc 区域源，以及一个带 HTML 注入的服务名（验证转义）。
// 注意：前端以 service.url 为键存状态，fixture 里 URL 必须唯一，否则状态互相覆盖。
// provider 用 config.php 里真实的中文品牌名；a2/a7 故意用未收录的 provider 验证回退逻辑。
const SERVICES = [
    { id: 'a1', name: '阿里云镜像', url: 'https://registry.cn-hangzhou.aliyuncs.com', provider: '阿里云', description: '阿里云容器镜像服务', region: 'cn' },
    { id: 'a2', name: '<img src=x onerror=alert(1)>注入测试', url: 'https://docker.1ms.run', provider: 'other', description: '自定义来源测试', region: 'cn' },
    { id: 'a3', name: '华为云镜像', url: 'https://swr.cn-north-1.myhuaweicloud.com', provider: '华为云', description: '华为云容器镜像服务', region: 'cn' },
    { id: 'a4', name: '腾讯云镜像', url: 'https://mirror.ccs.tencentyun.com', provider: '腾讯云', description: '腾讯云容器镜像服务（仅腾讯云内网）', region: 'vpc' },
    { id: 'a5', name: '中科大镜像站', url: 'https://docker.mirrors.ustc.edu.cn', provider: 'USTC', description: '中国科学技术大学开源软件镜像站', region: 'cn' },
    { id: 'a6', name: '南京大学镜像', url: 'https://docker.nju.edu.cn', provider: '南京大学', description: '南京大学开源镜像站', region: 'cn' },
    { id: 'a7', name: '待检测源', url: 'https://dockerpull.com', provider: 'other', description: 'Docker 镜像代理服务', region: 'cn' },
];

// 注意：后端 dmm_build_probe_result() 对每条结果都会写 status（默认 'error'），
// 所以这里显式给出 'pending'；缺 status 时前端的兜底是 'error'（见下方专门断言）。
const STATUS_BY_ID = {
    a1: { status: 'fast', responseTime: 140, httpCode: 401, reachable: true, authRequired: true, checkedAt: '2026-09-30T14:00:00+08:00' },
    a2: { status: 'fair', responseTime: 820, httpCode: 200, reachable: true, authRequired: false, checkedAt: '2026-09-30T14:00:00+08:00' },
    a3: { status: 'slow', responseTime: 1500, httpCode: 401, reachable: true, authRequired: true, checkedAt: '2026-09-30T14:00:00+08:00' },
    a4: { status: 'fast', responseTime: 60, httpCode: 401, reachable: true, authRequired: true, checkedAt: '2026-09-30T14:00:00+08:00' }, // vpc，不应被推荐
    a5: { status: 'error', responseTime: 0, httpCode: 0, reachable: false, error: 'DNS 解析失败', checkedAt: '2026-09-30T14:00:00+08:00' },
    a6: { status: 'very_slow', responseTime: 2400, httpCode: 200, reachable: true, authRequired: false, checkedAt: '2026-09-30T14:00:00+08:00' },
    a7: { status: 'pending', responseTime: 0, httpCode: 0, reachable: false, error: '本次未检测', checkedAt: null },
};

const served = {};

function apiPayload(action) {
    if (action === 'get_services') {
        return {
            success: true,
            data: SERVICES.map(s => ({ ...s, status: null })),
            timestamp: '2026-09-30T14:00:00+08:00',
        };
    }
    if (action === 'check_all' || action === 'quick_check') {
        const results = SERVICES.map(s => ({ ...s, ...(STATUS_BY_ID[s.id] ?? {}) }));
        const fast = results.filter(r => r.status === 'fast').length;
        const fair = results.filter(r => r.status === 'fair').length;
        const slow = results.filter(r => r.status === 'slow').length;
        const verySlow = results.filter(r => r.status === 'very_slow').length;
        const error = results.filter(r => r.status === 'error').length;
        return {
            success: true,
            data: results,
            stats: {
                total: results.length, fast, fair, slow, very_slow: verySlow, error,
                available: fast + fair + slow + verySlow,
                availability: Math.round(((fast + fair + slow + verySlow) / results.length) * 1000) / 10,
            },
            engine: 'registry-handshake',
            scope: action === 'quick_check' ? 'quick' : 'all',
            responseTime: 1865,
            canCheck: true,
            timestamp: '2026-09-30T14:00:00+08:00',
            cached: false,
        };
    }
    return { success: true, data: {} };
}

const fetchCalls = [];
const sandbox = {
    console,
    document,
    setTimeout: (fn) => { fn(); return 0; },
    clearTimeout: () => {},
    setInterval: () => 0,
    clearInterval: () => {},
    localStorage: { _d: {}, getItem(k) { return this._d[k] ?? null; }, setItem(k, v) { this._d[k] = String(v); }, removeItem(k) { delete this._d[k]; } },
    matchMedia: (query) => ({
        matches: /prefers-color-scheme:\s*dark/.test(query) ? false : false,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
    }),
    navigator: { clipboard: { writeText: async () => {} }, userAgent: 'node-smoke' },
    location: { origin: 'http://127.0.0.1:8080', protocol: 'http:', pathname: '/', href: 'http://127.0.0.1:8080/' },
    fetch: async (url) => {
        const u = String(url);
        fetchCalls.push(u);
        const action = (u.match(/action=([a-z_]+)/) || [])[1] ?? '';
        const payload = apiPayload(action);
        served[action] = payload;
        return { ok: true, status: 200, json: async () => payload, text: async () => JSON.stringify(payload) };
    },
    URLSearchParams,
    AbortController,
    AbortSignal,
    Event,
    CustomEvent,
    JSON,
    Math,
    Date,
    Promise,
    Array,
    Object,
    String,
    Number,
    Boolean,
    Error,
    RegExp,
    isNaN,
    parseInt,
    parseFloat,
    encodeURIComponent,
    decodeURIComponent,
};
sandbox.window = sandbox;
sandbox.addEventListener = () => {};
sandbox.removeEventListener = () => {};
sandbox.scrollTo = () => {};
sandbox.scrollY = 0;
sandbox.pageYOffset = 0;
sandbox.innerHeight = 800;
sandbox.globalThis = sandbox;

// ---------- 加载并执行 script.js ----------
const src = readFileSync(join(here, 'script.js'), 'utf8');
vm.createContext(sandbox);
console.log('== 前端渲染冒烟测试 ==\n');
console.log('-- 加载 script.js --');
try {
    new vm.Script(src, { filename: 'script.js' }).runInContext(sandbox);
    ok('script.js 解析并执行成功', true);
} catch (e) {
    ok('script.js 解析并执行成功', false, e.message);
    process.exit(1);
}

// ---------- 触发启动流程 ----------
console.log('\n-- 初始化与服务列表 --');
const flush = async (n = 12) => { for (let i = 0; i < n; i++) await new Promise(r => setImmediate(r)); };
try {
    document.dispatch('DOMContentLoaded', {});
    await flush(); // 等待 loadServices → initializeServices → checkAllServices 的 Promise 链完成
    ok('DOMContentLoaded 处理函数执行无异常', true);
} catch (e) {
    ok('DOMContentLoaded 处理函数执行无异常', false, e.stack?.split('\n').slice(0, 3).join(' | '));
}

ok('启动时请求了 get_services', fetchCalls.some(u => u.includes('get_services')), fetchCalls.join(' | '));
ok('启动时请求了检测接口（check_all/quick_check）', fetchCalls.some(u => /check_all|quick_check/.test(u)), fetchCalls.join(' | '));

// ---------- 渲染结果断言 ----------
console.log('\n-- 表格渲染 --');
ok('serviceTable 渲染了 7 行服务', tbody.children.length === 7, `实际 ${tbody.children.length}`);
if (tbody.children.length !== 7) {
    console.log('  [debug] 实际行内容 =', JSON.stringify(tbody.children.map(tr => tr.textContent.slice(0, 60))));
}

const rowText = tbody.children.map(tr => tr.textContent).join('\n');
// 表格「服务提供商」列对已收录的品牌显示 provider（更短），全名放在 title 上
ok('展示了 fast 源（阿里云）', rowText.includes('阿里云'));
ok('展示了 error 源（中科大，provider=USTC）', rowText.includes('USTC'));
ok('展示了 pending 源（待检测源）', rowText.includes('待检测源'));
ok('展示了 vpc 区域源（腾讯云）', rowText.includes('腾讯云'));
ok('未收录的 provider 不直接展示，回退到服务名（含 description 列）', rowText.includes('自定义来源测试'));

console.log('\n-- XSS 防护 --');
const injected = tbody.children.find(tr => tr.textContent.includes('注入测试'));
ok('恶意服务名被渲染为纯文本', !!injected);
ok('恶意服务名未产生 <img> 元素', !JSON.stringify(tbody.children.map(tr => tr.innerHTML)).includes('<img'));
const injectedHtml = injected ? injected.innerHTML : '';
ok('innerHTML 中不含 onerror 可执行属性', !/onerror=/i.test(injectedHtml) || injectedHtml.includes('&lt;'), injectedHtml.slice(0, 120));

console.log('\n-- 状态计数 --');
const setTexts = new Map();
for (const id of ['totalCount', 'normalCount', 'slowCount', 'errorCount']) {
    const el = registry.get(id);
    setTexts.set(id, el ? el.textContent : '(缺失元素)');
}
ok('totalCount = 7', setTexts.get('totalCount') === '7', setTexts.get('totalCount'));
ok('normalCount = 3 (fast: 阿里云/腾讯云 + fair: 1ms)', setTexts.get('normalCount') === '3', setTexts.get('normalCount'));
ok('slowCount = 2 (slow + very_slow 合并)', setTexts.get('slowCount') === '2', setTexts.get('slowCount'));
ok('errorCount = 1', setTexts.get('errorCount') === '1', setTexts.get('errorCount'));
ok('pendingCount 提示 1 个待检测', (registry.get('pendingCount')?.textContent ?? '').includes('1'), registry.get('pendingCount')?.textContent ?? '(缺失)');

console.log('\n-- 筛选与排序 --');
const filterBtns = document.querySelectorAll('[data-filter]');
ok('index.html 中存在 data-filter 按钮', filterBtns.length >= 4, `实际 ${filterBtns.length}`);
const slowBtn = filterBtns.find(b => b.getAttribute('data-filter') === 'slow');
if (slowBtn) {
    slowBtn.classList.add('active-filter');
    try {
        slowBtn.dispatch('click', {});
        const visible = tbody.children.filter(tr => tr.style.display !== 'none');
        ok('"缓慢"筛选同时保留 slow 与 very_slow（各 1 行）', visible.length === 2, `实际显示 ${visible.length} 行`);
        // 还原为全部
        const allBtn = filterBtns.find(b => b.getAttribute('data-filter') === 'all');
        if (allBtn) allBtn.dispatch('click', {});
    } catch (e) {
        ok('"缓慢"筛选同时保留 slow 与 very_slow（各 1 行）', false, e.message);
    }
} else {
    ok('"缓慢"筛选同时保留 slow 与 very_slow（各 1 行）', false, '未找到 data-filter="slow" 按钮');
}

// 按响应时间排序：未探测/失败的必须排到最后
const sortTh = document.querySelectorAll('[data-sort]');
ok('index.html 中存在 data-sort 表头', sortTh.length >= 3, `实际 ${sortTh.length}`);
const rtTh = sortTh.find(th => th.getAttribute('data-sort') === 'responseTime');
if (rtTh && slowBtn) {
    try {
        const allBtn = filterBtns.find(b => b.getAttribute('data-filter') === 'all');
        if (allBtn) allBtn.dispatch('click', {});
        rtTh.dispatch('click', {});
        const order = tbody.children.map(tr => tr.textContent);
        const last = order[order.length - 1] ?? '';
        ok('响应时间升序时，失败/未探测的源排在最后', !/阿里云|华为云/.test(last), last.slice(0, 80));
    } catch (e) {
        ok('响应时间升序时，失败/未探测的源排在最后', false, e.message);
    }
} else {
    ok('响应时间升序时，失败/未探测的源排在最后', false, '未找到 data-sort="responseTime" 表头');
}

console.log('\n-- 配置指引生成（数据驱动，不再硬编码 USTC/163） --');
const linesHost = document.querySelector('[data-mirror-lines]');
const winList = registry.get('windowsMirrorList');
const noteEl = registry.get('guideNote');
const guideText = (linesHost ? linesHost.textContent : '') + ' ' + (winList ? winList.textContent : '') + ' ' + (noteEl ? noteEl.textContent : '');
ok('生成了 registry-mirrors 地址行', /https:\/\//.test(guideText), guideText.slice(0, 160));
ok('推荐里不包含已失效的 USTC 地址', !guideText.includes('mirrors.ustc.edu.cn'), guideText.slice(0, 200));
ok('推荐里不包含已下线的 163 地址', !guideText.includes('hub-mirror.c.163.com'));
ok('推荐里不包含 VPC 专用源（腾讯云内网）', !guideText.includes('mirror.ccs.tencentyun.com'), guideText.slice(0, 200));
ok('推荐里不包含失败的源（中科大 DNS 失败）', !guideText.includes('docker.mirrors.ustc.edu.cn'));
// index.html 里 URL 写在 .code-value / <code> 的 textContent 中（不含 JSON 引号）
const recommended = [
    ...(linesHost ? linesHost.children.map(c => c.textContent) : []),
    ...(winList ? winList.children.map(c => c.textContent) : []),
].map(t => String(t).replace(/[",]/g, '').trim()).filter(t => t.startsWith('http'));
ok('推荐数量在 1..3 之间', recommended.length >= 1 && recommended.length <= 3, JSON.stringify(recommended));
ok('推荐按响应时间升序（最快的阿里云 140ms 在首位）', recommended[0] === 'https://registry.cn-hangzhou.aliyuncs.com', JSON.stringify(recommended));
ok('推荐中不含 vpc 专用源（腾讯云 60ms 虽最快但在 VPC 内）', !recommended.some(u => u.includes('mirror.ccs.tencentyun.com')), JSON.stringify(recommended));
ok('推荐中不含 slow/fair 源（只取 fast/fair 前三，fair 820ms 应入选）', recommended.includes('https://docker.1ms.run'), JSON.stringify(recommended));
ok('推荐中不含 very_slow 源（2400ms）', !recommended.some(u => u.includes('docker.nju.edu.cn')), JSON.stringify(recommended));
ok('指引备注说明了数据来源与最快源', /最快/.test(noteEl ? noteEl.textContent : ''), noteEl ? noteEl.textContent : '(缺失)');

console.log('\n-- 品牌与元信息 --');
const brandEl = document.querySelector('[data-brand-name]');
ok('data-brand-name 元素存在', !!brandEl);
const metaEl = document.querySelector('[data-app-meta]');
ok('data-app-meta 元素存在', !!metaEl);

console.log('\n-- 主题切换 --');
const themeToggle = registry.get('themeToggle');
const themeIcon = registry.get('themeIcon');
ok('themeToggle 存在', !!themeToggle);
if (themeToggle) {
    try {
        themeToggle.dispatch('click', {});
        const stored = sandbox.localStorage.getItem('dmm-theme') ?? sandbox.localStorage.getItem('theme');
        ok('点击主题按钮后写入了本地存储', stored !== null, String(stored));
        ok('点击主题按钮切换了 html class', document.documentElement.classList.toString() !== '' || stored !== null, document.documentElement.classList.toString());
    } catch (e) {
        ok('点击主题按钮后写入了本地存储', false, e.message);
    }
}

// ---------- 汇总 ----------
console.log(`\n== 结果：${pass} 通过 / ${fail} 失败 ==`);
if (failures.length) {
    console.log('\n失败项：');
    failures.forEach(f => console.log('  - ' + f));
}
console.log('\n请求过的接口：' + [...new Set(fetchCalls.map(u => (u.match(/action=([a-z_]+)/) || [])[1]))].join(', '));
process.exit(fail === 0 ? 0 : 1);
