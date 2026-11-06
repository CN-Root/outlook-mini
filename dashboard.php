<?php require 'config.php'; check_auth(); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outlook Panel - 控制台</title>
    <link rel="icon" type="image/svg+xml" href="/static/image/favicon/OutlookPanel_Favicon.svg">
    <style>
        :root {
            --bg: #f5f5f7;
            --card-bg: rgba(255, 255, 255, 0.8);
            --border: rgba(0, 0, 0, 0.05);
            --main: #0071e3;
            --main-hover: #0077ed;
            --text: #1d1d1f;
            --text-secondary: #86868b;
            --danger: #ff3b30;
            --glass: blur(20px) saturate(180%);
            --nav-h: 64px;
            --col-left: 320px;
            --col-mid: 320px;
        }

        body { 
            margin: 0; 
            background: var(--bg); 
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", sans-serif;
            -webkit-font-smoothing: antialiased;
            height: 100vh;
            overflow: hidden;
        }

        /* 导航栏美化：毛玻璃效果 */
        nav { 
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: var(--glass);
            -webkit-backdrop-filter: var(--glass);
            padding: 0 24px; 
            height: var(--nav-h);
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px solid var(--border); 
            position: sticky; 
            top: 0; 
            z-index: 1000; 
            box-sizing: border-box;
        }

        .nav-logo { font-weight: 700; font-size: 20px; letter-spacing: -0.5px; }
        .nav-logo span { color: var(--main); }

        /* 搜索框：更现代的圆角与交互 */
        .search-bar { 
            background: rgba(0, 0, 0, 0.05);
            border: none; 
            padding: 8px 16px; 
            border-radius: 10px; 
            outline: none; 
            width: 240px; 
            font-size: 14px; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            margin-left: 20px;
        }
        .search-bar:focus { width: 320px; background: #fff; box-shadow: 0 0 0 4px rgba(0, 113, 227, 0.1); }

        .nav-actions { display: flex; gap: 8px; align-items: center; }

        /* 按钮样式美化 */
        .btn { 
            padding: 8px 14px; border-radius: 10px; cursor: pointer; font-size: 13.5px; 
            border: 1px solid var(--border); background: #fff; font-weight: 500; 
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
            color: var(--text);
        }
        .btn:hover { background: #f5f5f7; border-color: #d2d2d7; }
        .btn-black { background: var(--main); color: #FFF; border: none; }
        .btn-black:hover { background: var(--main-hover); transform: translateY(-1px); }
        .btn-danger { color: var(--danger); border-color: rgba(255, 59, 48, 0.1); }
        .btn-danger:hover { background: rgba(255, 59, 48, 0.05); border-color: var(--danger); }

        /* ================= 三栏主布局 =================
           左：分组横滑 + 账户列表 ｜ 中：邮件标题 ｜ 右：邮件内容
           三列各自独立滚动，页面本身不滚（body overflow:hidden） */
        .app {
            display: grid;
            grid-template-columns: var(--col-left) var(--col-mid) 1fr;
            height: calc(100vh - var(--nav-h));
        }

        .col { display: flex; flex-direction: column; min-width: 0; overflow: hidden; }

        .col-left { background: var(--card-bg); border-right: 1px solid var(--border); }
        .col-mid  { background: #fbfbfd; border-right: 1px solid var(--border); }
        .col-right{ background: #fff; }

        /* ---------- 分组横滑条 ---------- */
        .group-bar {
            display: flex; gap: 8px; align-items: center;
            padding: 14px 12px 12px 16px;
            overflow-x: auto; overflow-y: hidden;
            border-bottom: 1px solid var(--border);
            scrollbar-width: none;             /* Firefox 隐藏滚动条 */
            -ms-overflow-style: none;
        }
        .group-bar::-webkit-scrollbar { display: none; }

        .chip {
            flex: 0 0 auto; display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 12px; border-radius: 999px; background: #fff;
            border: 1px solid var(--border); font-size: 13px; font-weight: 500;
            cursor: pointer; white-space: nowrap; transition: all .2s; color: var(--text);
            max-width: 190px;
        }
        .chip:hover { border-color: #d2d2d7; }
        .chip.active { background: var(--main); border-color: var(--main); color: #fff; }
        .chip-name { overflow: hidden; text-overflow: ellipsis; }
        .chip-count { font-size: 11px; opacity: .65; font-variant-numeric: tabular-nums; }
        .chip-op {
            border: none; background: transparent; cursor: pointer; font-size: 11px;
            padding: 0 1px; line-height: 1; color: inherit; opacity: .75;
        }
        .chip-op:hover { opacity: 1; }
        .chip-add { background: transparent; border-style: dashed; color: var(--text-secondary); }

        /* ---------- 账户列表 ---------- */
        .list-head {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 16px 6px; font-size: 11.5px; font-weight: 600;
            color: var(--text-secondary); letter-spacing: .3px;
        }
        .acc-list { flex: 1; overflow-y: auto; padding: 0 8px 16px; }
        .acc-item {
            position: relative; display: flex; gap: 8px; padding: 11px 12px;
            border-radius: 12px; cursor: pointer; transition: background .15s;
        }
        .acc-item:hover { background: #fff; }
        .acc-item.active { background: #fff; box-shadow: 0 4px 14px rgba(0,0,0,.06); }
        .acc-main { min-width: 0; flex: 1; padding-right: 46px; }
        .acc-email { font-size: 13.5px; font-weight: 600; word-break: break-all; line-height: 1.35; }
        .acc-remark { font-size: 12px; color: var(--text-secondary); margin-top: 3px; word-break: break-all; }
        .acc-ops {
            position: absolute; right: 8px; top: 9px; display: flex; gap: 4px;
            opacity: 0; transition: opacity .15s;
        }
        .acc-item:hover .acc-ops { opacity: 1; }
        .acc-op {
            width: 24px; height: 24px; display: inline-flex; align-items: center;
            justify-content: center; border-radius: 7px; border: 1px solid var(--border);
            background: #fff; cursor: pointer; font-size: 11px; line-height: 1;
            color: var(--text-secondary); padding: 0;
        }
        .acc-op:hover { background: #f5f5f7; color: var(--text); }
        .acc-op.danger:hover { color: var(--danger); border-color: rgba(255,59,48,.3); }

        /* ---------- 中栏：邮件标题 ---------- */
        .mid-head { padding: 14px 16px; border-bottom: 1px solid var(--border); }
        .mid-title { font-size: 13.5px; font-weight: 600; word-break: break-all; line-height: 1.4; }
        .mid-sub { font-size: 11.5px; color: var(--text-secondary); margin-top: 3px; }

        .mail-list { flex: 1; overflow-y: auto; }
        .mail-item {
            padding: 13px 16px; border-bottom: 1px solid var(--border);
            cursor: pointer; border-left: 3px solid transparent; transition: background .15s;
        }
        .mail-item:hover { background: #fff; }
        .mail-item.active { background: #fff; border-left-color: var(--main); }
        .mail-subject { font-size: 13px; font-weight: 600; word-break: break-word; line-height: 1.4; }
        .mail-from { font-size: 11.5px; color: var(--text-secondary); margin-top: 4px; word-break: break-all; }

        /* ---------- 右栏：邮件内容 ---------- */
        .mail-view { flex: 1; position: relative; overflow: hidden; }
        .mail-view iframe { width: 100%; height: 100%; border: none; display: block; background: #fff; }

        /* 通用占位/空状态 */
        .hint {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; color: #c7c7cc; font-size: 13.5px; text-align: center; padding: 24px;
            box-sizing: border-box; gap: 10px;
        }
        .hint-icon { font-size: 34px; opacity: .55; }
        .list-empty { padding: 32px 16px; text-align: center; color: #b0b0b7; font-size: 13px; }
        .list-err { padding: 16px; color: var(--danger); font-size: 13px; line-height: 1.5; word-break: break-all; }

        /* ---------- 模态框 ---------- */
        .modal { 
            display:none; position:fixed; inset:0; 
            background: rgba(0, 0, 0, 0.2); 
            z-index: 2000; align-items: center; justify-content: center; 
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal-body { 
            background: #fff; border-radius: 24px; width: 95%; max-width: 420px;
            display: flex; flex-direction: column; overflow: hidden; position: relative; 
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
            padding: 28px; max-height: 88vh; overflow-y: auto; box-sizing: border-box;
        }

        /* 设置表单微调 */
        input, textarea, select { 
            width: 100%; padding: 12px 14px; border: 1px solid #d2d2d7; 
            border-radius: 10px; margin-top: 6px; margin-bottom: 16px; 
            box-sizing: border-box; font-size: 14.5px; transition: 0.2s;
            background: #fff; color: var(--text); font-family: inherit;
        }
        input:focus, select:focus, textarea:focus { border-color: var(--main); outline: none; box-shadow: 0 0 0 4px rgba(0, 113, 227, 0.1); }
        label { font-size: 12px; color: var(--text-secondary); font-weight: 600; margin-left: 2px; }

        /* 轻提示 */
        #toast {
            position: fixed; left: 50%; bottom: 34px; transform: translate(-50%, 16px);
            background: #1d1d1f; color: #fff; padding: 10px 18px; border-radius: 12px;
            font-size: 13px; opacity: 0; pointer-events: none; transition: all .25s;
            z-index: 3000; max-width: 70vw; box-shadow: 0 10px 30px rgba(0,0,0,.18);
        }
        #toast.show { opacity: 1; transform: translate(-50%, 0); }
        #toast.err { background: var(--danger); }

        @media (max-width: 1180px) {
            :root { --col-left: 280px; --col-mid: 280px; }
            .search-bar { width: 170px; }
            .search-bar:focus { width: 220px; }
        }
    </style>
</head>
<body>

<nav>
    <div style="display:flex; align-items:center;">
        <div class="nav-logo">Outlook<span>Panel</span></div>
        <input type="text" id="searchInput" class="search-bar" placeholder="搜索邮箱或备注...">
    </div>
    <div class="nav-actions">
        <button class="btn btn-black" data-act="add">+ 添加账号</button>
        <button class="btn" data-act="import">导入</button>
        <input type="file" id="importFile" hidden accept=".json,.txt">
        <button class="btn" data-act="export">导出</button>
        <button class="btn" data-act="tg-backup">TG备份</button>
        <button class="btn" data-act="settings">⚙️ 设置</button>
        <button class="btn" data-act="token">🔑 获取密钥</button>
        <button class="btn btn-danger" data-act="logout">退出</button>
    </div>
</nav>

<div class="app">
    <!-- 左一栏：上半分组横滑，下半账户列表 -->
    <section class="col col-left">
        <div class="group-bar" id="groupBar"></div>
        <div class="list-head">
            <span id="accListTitle">全部账户</span>
            <span id="accListCount"></span>
        </div>
        <div class="acc-list" id="accList"></div>
    </section>

    <!-- 中栏：邮件标题 -->
    <section class="col col-mid">
        <div class="mid-head">
            <div class="mid-title" id="midTitle">未选择账户</div>
            <div class="mid-sub" id="midSub">从左栏点一个邮箱</div>
        </div>
        <div class="mail-list" id="mailList">
            <div class="hint"><span class="hint-icon">📬</span><span>选择账户后同步邮件</span></div>
        </div>
    </section>

    <!-- 右栏：邮件内容（本步仍用 document.write，sandbox 留给 E-4） -->
    <section class="col col-right">
        <div class="mail-view" id="mailView">
            <div class="hint"><span class="hint-icon">✉️</span><span>选择中栏标题查看正文</span></div>
        </div>
    </section>
</div>

<!-- 统一模态框：表单 -->
<div id="formModal" class="modal">
    <div class="modal-body">
        <h2 id="modalTitle" style="margin:0 0 20px 0; font-size:20px; font-weight:700;"></h2>
        <div id="modalForm"></div>
        <div style="display:flex; gap:12px; margin-top:16px;">
            <button class="btn" data-act="close-modal" style="flex:1; justify-content:center; padding:11px;">取消</button>
            <button class="btn btn-black" id="submitBtn" style="flex:2; justify-content:center; padding:11px;">确认提交</button>
        </div>
    </div>
</div>

<!-- 确认对话框 -->
<div id="confirmModal" class="modal">
    <div class="modal-body" style="width:340px; text-align:center; align-items:center;">
        <div style="font-size:38px; margin-bottom:14px;">⚠️</div>
        <p id="confirmMsg" style="font-weight:600; font-size:15px; margin:0; line-height:1.5; word-break:break-all;"></p>
        <div style="display:flex; gap:12px; margin-top:22px; width:100%;">
            <button class="btn" style="flex:1; justify-content:center;" data-act="close-modal">取消</button>
            <button class="btn btn-black" style="flex:1; justify-content:center; background:var(--danger);" id="confirmBtn">确定</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
// ============================================================================
// 状态（唯一数据源；DOM 里只放 id / gid / idx，不放整条记录）
// ============================================================================
const state = {
    accounts: [],      // 当前分组（或默认分组）下的账户
    groups: [],        // 分组列表，含 account_count
    selectedGid: 0,    // 0 = 默认分组（虚拟：未加入任何组的账号）
    selectedAccId: 0,  // 0 = 未选中账户
    mails: [],         // 当前账户的邮件（正文留在 JS 里，DOM 只存下标）
    selectedMailIdx: -1,
    keyword: ''
};

// ============================================================================
// H-3：所有用户可控字符串（email / remark / 分组名 / subject / from）
// 进 DOM 前必须过 escapeHtml。属性值与文本节点共用同一个函数——
// 属性上下文里引号同样必须转义，否则 data-x="..." 会被闭合。
// ============================================================================
function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toast(msg, isErr = false) {
    const el = document.getElementById('toast');
    el.textContent = String(msg);
    el.className = isErr ? 'show err' : 'show';
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { el.className = ''; }, 2600);
}

function formData(obj) {
    const fd = new FormData();
    Object.entries(obj).forEach(([k, v]) => fd.set(k, v === null || v === undefined ? '' : String(v)));
    return fd;
}

// 统一 API 调用。query 里 null / undefined / '' 一律不拼进 URL——
// server 端 group_accounts 也把空串当默认分组，两侧口径一致（E-2 偏差 3）。
async function callApi(action, options = {}) {
    const { method = 'GET', body = null, query = {} } = options;
    const params = new URLSearchParams({ action });
    Object.entries(query).forEach(([k, v]) => {
        if (v === null || v === undefined || v === '') return;
        params.set(k, v);
    });
    const init = { method, headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (method === 'POST') init.body = body || new FormData();
    let res;
    try {
        res = await fetch('api.php?' + params.toString(), init);
    } catch (err) {
        return { ok: false, status: 0, data: null, message: '网络错误，请稍后重试' };
    }
    let data = null;
    try { data = await res.json(); } catch (err) { data = null; }
    const message = (data && data.message) ? data.message : ('请求失败（HTTP ' + res.status + '）');
    return { ok: res.ok, status: res.status, data, message };
}

function groupName(gid) {
    const g = state.groups.find(x => Number(x.id) === Number(gid));
    return g ? g.name : ('#' + gid);
}

function findAccount(id) {
    return state.accounts.find(a => Number(a.id) === Number(id));
}

// ============================================================================
// 分组横滑条：默认分组常驻第一个，末尾是"+ 新建"
// ============================================================================
async function loadGroups() {
    const r = await callApi('list_groups');
    if (!r.ok || !Array.isArray(r.data)) {
        toast('分组加载失败：' + r.message, true);
        return;
    }
    state.groups = r.data;
    // 当前选中的组被删掉了 → 自动退回默认分组（UI 上也是这么提示的）
    if (state.selectedGid > 0 && !state.groups.some(g => Number(g.id) === state.selectedGid)) {
        state.selectedGid = 0;
    }
    renderGroups();
}

function renderGroups() {
    const bar = document.getElementById('groupBar');
    const defChip = `
        <div class="chip${state.selectedGid === 0 ? ' active' : ''}" data-act="group-select" data-gid="0" title="未加入任何分组的账号">
            <span class="chip-name">默认分组</span>
        </div>`;

    const chips = state.groups.map(g => {
        const gid = Number(g.id);
        const active = state.selectedGid === gid;
        return `
        <div class="chip${active ? ' active' : ''}" data-act="group-select" data-gid="${gid}">
            <span class="chip-name">${escapeHtml(g.name)}</span>
            <span class="chip-count">${Number(g.account_count) || 0}</span>
            ${active ? `<button class="chip-op" data-act="group-rename" data-gid="${gid}" title="重命名">✎</button>
            <button class="chip-op" data-act="group-delete" data-gid="${gid}" title="删除分组">✕</button>` : ''}
        </div>`;
    }).join('');

    bar.innerHTML = defChip + chips + `<div class="chip chip-add" data-act="group-new">＋ 新建</div>`;

    // 横滑条里把当前选中的 chip 滚进可视区（组多的时候才不会"选中了却看不见"）
    const activeEl = bar.querySelector('.chip.active');
    if (activeEl && typeof activeEl.scrollIntoView === 'function') {
        activeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    }
}

async function selectGroup(gid) {
    state.selectedGid = Number(gid) || 0;
    state.selectedAccId = 0;
    state.mails = [];
    state.selectedMailIdx = -1;
    renderGroups();
    resetMailPane('未选择账户', '从左栏点一个邮箱');
    await loadAccounts();
}

// ============================================================================
// 账户列表（按当前分组过滤，搜索词在本地再过一遍）
// ============================================================================
async function loadAccounts() {
    const query = {};
    // 默认分组不传 group_id（也就不存在传空串的可能）
    if (state.selectedGid > 0) query.group_id = state.selectedGid;

    const r = await callApi('group_accounts', { query });
    if (!r.ok || !Array.isArray(r.data)) {
        document.getElementById('accList').innerHTML = `<div class="list-err">${escapeHtml(r.message)}</div>`;
        return;
    }
    state.accounts = r.data;
    // 选中的账户可能已经不在当前分组里了（被移出 / 被删除）
    if (state.selectedAccId > 0 && !state.accounts.some(a => Number(a.id) === state.selectedAccId)) {
        state.selectedAccId = 0;
        resetMailPane('未选择账户', '从左栏点一个邮箱');
    }
    renderAccounts();
}

function renderAccounts() {
    const list = document.getElementById('accList');
    const kw = state.keyword;
    const rows = state.accounts.filter(a => {
        if (!kw) return true;
        const email = String(a.email || '').toLowerCase();
        const remark = String(a.remark || '').toLowerCase();
        return email.indexOf(kw) !== -1 || remark.indexOf(kw) !== -1;
    });

    document.getElementById('accListTitle').textContent = state.selectedGid > 0 ? groupName(state.selectedGid) : '默认分组';
    document.getElementById('accListCount').textContent = rows.length + ' 个';

    if (!rows.length) {
        list.innerHTML = `<div class="list-empty">${kw ? '没有匹配的账户' : '这个分组还没有账户'}</div>`;
        return;
    }

    list.innerHTML = rows.map(a => {
        const id = Number(a.id);
        const active = state.selectedAccId === id ? ' active' : '';
        const remark = a.remark
            ? escapeHtml(a.remark)
            : '<span style="opacity:.55">无备注</span>';
        const removeBtn = state.selectedGid > 0
            ? `<button class="acc-op" data-act="acc-remove-from-group" data-id="${id}" title="移出本组（不删账户）">⤺</button>`
            : '';
        return `
        <div class="acc-item${active}" data-act="acc-open" data-id="${id}">
            <div class="acc-main">
                <div class="acc-email">${escapeHtml(a.email)}</div>
                <div class="acc-remark">${remark}</div>
            </div>
            <div class="acc-ops">
                <button class="acc-op" data-act="acc-edit" data-id="${id}" title="编辑">✎</button>
                ${removeBtn}
                <button class="acc-op" data-act="acc-add-to-group" data-id="${id}" title="加入分组">⊞</button>
                <button class="acc-op danger" data-act="acc-delete" data-id="${id}" title="删除账户">🗑</button>
            </div>
        </div>`;
    }).join('');
}

// ============================================================================
// 中栏：邮件标题 / 右栏：邮件正文
// ============================================================================
function resetMailPane(title, sub) {
    document.getElementById('midTitle').textContent = title;
    document.getElementById('midSub').textContent = sub;
    document.getElementById('mailList').innerHTML = `<div class="hint"><span class="hint-icon">📬</span><span>选择账户后同步邮件</span></div>`;
    document.getElementById('mailView').innerHTML = `<div class="hint"><span class="hint-icon">✉️</span><span>选择中栏标题查看正文</span></div>`;
}

async function openAccount(id) {
    const acc = findAccount(id);
    state.selectedAccId = Number(id);
    state.mails = [];
    state.selectedMailIdx = -1;
    renderAccounts();

    // 标题用 textContent 写，email 是用户可控字段
    document.getElementById('midTitle').textContent = acc ? acc.email : ('#' + id);
    document.getElementById('midSub').textContent = '正在同步邮件...';
    document.getElementById('mailList').innerHTML = `<div class="hint"><span class="hint-icon">⏳</span><span>正在同步邮件...</span></div>`;
    document.getElementById('mailView').innerHTML = `<div class="hint"><span class="hint-icon">✉️</span><span>选择中栏标题查看正文</span></div>`;

    const r = await callApi('get_mails', { query: { id: id } });
    if (!r.ok) {
        document.getElementById('mailList').innerHTML = `<div class="list-err">${escapeHtml(r.message)}</div>`;
        document.getElementById('midSub').textContent = '同步失败';
        return;
    }
    if (r.data && r.data.error) {
        document.getElementById('mailList').innerHTML = `<div class="list-err">${escapeHtml(r.data.error)}</div>`;
        document.getElementById('midSub').textContent = '同步失败';
        return;
    }

    const messages = (r.data && Array.isArray(r.data.value)) ? r.data.value : [];
    state.mails = messages;
    document.getElementById('midSub').textContent = messages.length + ' 封邮件';

    if (!messages.length) {
        document.getElementById('mailList').innerHTML = `<div class="list-empty">收件箱为空</div>`;
        return;
    }

    document.getElementById('mailList').innerHTML = messages.map((m, idx) => {
        // G-1：Junk 来源的邮件在标题前加小标记，随 subject 一起过 escapeHtml（标记本身是固定文本，不含用户输入）
        const junk    = m.folder === 'junk' ? '[垃圾邮件] ' : '';
        const subject = m.subject ? m.subject : '(无主题)';
        return `
        <div class="mail-item" data-act="mail-open" data-idx="${idx}">
            <div class="mail-subject">${escapeHtml(junk + subject)}</div>
            <div class="mail-from">${escapeHtml(mailFrom(m))}</div>
        </div>`;
    }).join('');
}

function mailFrom(m) {
    const addr = (m && m.from && m.from.emailAddress) ? m.from.emailAddress : null;
    if (!addr) return '未知发件人';
    return addr.name || addr.address || '未知发件人';
}

function openMail(idx) {
    const m = state.mails[idx];
    if (!m) return;
    state.selectedMailIdx = idx;
    document.querySelectorAll('.mail-item').forEach(el => {
        el.classList.toggle('active', Number(el.dataset.idx) === idx);
    });
    const content = (m.body && m.body.content) ? String(m.body.content) : '';
    renderMailBody(content);
}

// 正文渲染：仍然 document.write（E-4 在 iframe 上加 sandbox，本步保持现有行为）
// 正文不再经过 base64 → data 属性 → 解码的绕行，直接从 state.mails 取，DOM 上只有下标。
function renderMailBody(html) {
    const view = document.getElementById('mailView');
    // H-2（E-4）核心：sandbox 只放行弹窗。
    // 刻意不加 allow-same-origin（否则 iframe 与父页同源，能读父页 state 里的全部账号凭据，
    // sandbox 就形同虚设）；也不加 allow-scripts（正文不需要脚本，顺带把 <img onerror> 这类
    // 事件属性一并掐死）。服务端 HtmlSanitizer 是第一道防线，这里是第二道，缺一不可。
    //
    // 正文注入方式从 document.write 换成 srcdoc：
    // 不加 allow-same-origin 后 iframe 是 opaque origin（跨源），父页访问 contentWindow.document
    // 会抛 SecurityError，document.write 根本写不进去（实测 SecurityError）。srcdoc 是属性注入、
    // 不走跨源访问，正文照常渲染。这是 E-4 相对 E-3 唯一必要的调整（见交付说明偏差 1）。
    view.innerHTML = `<iframe id="mailIframe" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>`;
    let decoded = html;
    decoded = decoded
        .replace(/href="\//gi, 'href="#')
        .replace(/src="\//gi, 'src="#')
        .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
    document.getElementById('mailIframe').srcdoc = decoded;
}

// ============================================================================
// 分组写操作：建组 / 改名 / 删组 / 加账号 / 移出
// 400 / 404 / 409 的 message 由 Service 给，直接 toast 展示，前端不复述文案
// ============================================================================
function openGroupForm(mode, gid) {
    const isRename = mode === 'rename';
    const current = isRename ? groupName(gid) : '';
    showForm(isRename ? '重命名分组' : '新建分组', `
        <label>分组名称</label>
        <input id="g_name" value="${escapeHtml(current)}" placeholder="例如：工作 / 客户" maxlength="40">
    `, async () => {
        const name = document.getElementById('g_name').value;
        const r = isRename
            ? await callApi('rename_group', { method: 'POST', body: formData({ id: gid, name }) })
            : await callApi('create_group', { method: 'POST', body: formData({ name }) });
        if (!r.ok) {
            toast(r.message, true);
            return false;   // 保持弹窗，让用户改了再提交
        }
        toast(isRename ? '分组已重命名' : '分组已创建');
        return true;
    });
}

async function deleteGroup(gid) {
    const r = await callApi('delete_group', { method: 'POST', body: formData({ id: gid }) });
    if (!r.ok) {
        toast(r.message, true);
        return;
    }
    // 删掉的正好是当前分组 → 自动退回默认分组并刷新账户列表
    if (state.selectedGid === Number(gid)) {
        state.selectedGid = 0;
        state.selectedAccId = 0;
        resetMailPane('未选择账户', '从左栏点一个邮箱');
    }
    toast('分组已删除，组内账户已回到默认分组');
    await loadGroups();
    await loadAccounts();
}

async function openAddToGroup(accId) {
    await loadGroups();
    if (!state.groups.length) {
        toast('还没有分组，先新建一个');
        openGroupForm('create', 0);
        return;
    }
    const acc = findAccount(accId);
    const picker = state.groups.map(g => {
        const gid = Number(g.id);
        return `<button class="btn" style="width:100%; justify-content:space-between; margin-bottom:8px;"
                    data-act="acc-add-to-group-ok" data-id="${accId}" data-gid="${gid}">
                    <span>${escapeHtml(g.name)}</span>
                    <span style="font-size:11px; color:var(--text-secondary);">${Number(g.account_count) || 0} 个账户</span>
                </button>`;
    }).join('');
    showForm('加入分组：' + (acc ? acc.email : '#' + accId), picker, async () => false, { hideSubmit: true });
}

async function addToGroup(accId, gid) {
    const r = await callApi('group_add_account', {
        method: 'POST', body: formData({ group_id: gid, account_id: accId })
    });
    if (!r.ok) {
        toast(r.message, true);
        return;
    }
    toast('已加入「' + groupName(gid) + '」');
    closeModals();
    await loadGroups();
    await loadAccounts();
}

async function removeFromGroup(accId) {
    const gid = state.selectedGid;
    if (!gid) return;
    const r = await callApi('group_remove_account', {
        method: 'POST', body: formData({ group_id: gid, account_id: accId })
    });
    if (!r.ok) {
        toast(r.message, true);
        return;
    }
    toast('已移出「' + groupName(gid) + '」');
    await loadGroups();
    await loadAccounts();
}

// ============================================================================
// 账户 / 设置（沿用 E-2 之前的逻辑，只把插值点包上 escapeHtml）
// ============================================================================
async function refreshAll() {
    await loadGroups();
    await loadAccounts();
}

function openAdd() {
    showForm('添加新账号', `
        <label>原始数据</label>
        <textarea id="raw_data" rows="4" placeholder="Email----Pass----ID----RefreshToken"></textarea>
        <label>备注</label>
        <input id="remark" placeholder="例如：自用主号">
    `, async () => {
        const r = await callApi('add', {
            method: 'POST',
            body: formData({
                raw_data: document.getElementById('raw_data').value,
                remark: document.getElementById('remark').value
            })
        });
        if (!r.ok) { toast(r.message, true); return false; }
        toast('已添加');
        return true;
    });
}

async function openEdit(id) {
    const r = await callApi('detail', { query: { id: id } });
    if (!r.ok || !r.data) {
        toast('读取账号详情失败，请刷新页面后重试', true);
        return;
    }
    const d = r.data;
    // password_decrypted === false：库里不是本系统密文（旧明文或损坏），密码框留空并提示
    const pwOk = d.password_decrypted !== false;
    const remark = (d.remark === null || d.remark === undefined) ? '' : d.remark;

    showForm('编辑信息', `
        <label>邮箱</label><input id="e_mail" value="${escapeHtml(d.email)}">
        <label>密码</label><input id="e_pass" value="${pwOk ? escapeHtml(d.password) : ''}" placeholder="${pwOk ? '' : '请重新录入密码'}">
        ${pwOk ? '' : '<div style="font-size:12px; color:var(--danger); margin-top:6px;">此账号密码未加密或已损坏，请重新录入；留空则保持原值不变。</div>'}
        <label>Client ID</label><input id="e_cid" value="${escapeHtml(d.client_id)}">
        <label>Refresh Token</label><textarea id="e_token" rows="3">${escapeHtml(d.refresh_token)}</textarea>
        <label>备注</label><input id="e_rem" value="${escapeHtml(remark)}">
    `, async () => {
        const r2 = await callApi('update_account', {
            method: 'POST',
            body: formData({
                id: id,
                email: document.getElementById('e_mail').value,
                password: document.getElementById('e_pass').value,
                client_id: document.getElementById('e_cid').value,
                refresh_token: document.getElementById('e_token').value,
                remark: document.getElementById('e_rem').value
            })
        });
        if (!r2.ok) { toast(r2.message, true); return false; }
        toast('已保存');
        return true;
    });
}

async function openSettings() {
    const r = await callApi('get_settings');
    if (!r.ok || !r.data) {
        toast('读取设置失败', true);
        return;
    }
    const data = r.data;
    // D1-1：mail_mode 下拉框。库里若没有该行，get_settings 会回退 'auto'
    const mailMode = data.mail_mode || 'auto';
    const modeOption = (v, label) => `<option value="${v}"${mailMode === v ? ' selected' : ''}>${label}</option>`;

    showForm('系统设置', `
        <label>管理员用户名</label><input id="s_user" value="${escapeHtml(data.admin_user)}">
        <label>设置新密码 (不改留空)</label><input type="password" id="s_pass">
        <hr style="border:0; border-top:1px solid #eee; margin:10px 0;">
        <label>邮件获取方式</label>
        <select id="s_mode">
            ${modeOption('auto', '自动（Graph 优先，失败回退 IMAP）')}
            ${modeOption('graph', '仅 Graph')}
            ${modeOption('imap', '仅 IMAP')}
        </select>
        <hr style="border:0; border-top:1px solid #eee; margin:10px 0;">
        <label>TG Bot Token</label><input id="s_token" value="${escapeHtml(data.tg_token)}">
        <label>TG Chat ID</label><input id="s_chat" value="${escapeHtml(data.tg_chatid)}">
    `, async () => {
        const r2 = await callApi('save_settings', {
            method: 'POST',
            body: formData({
                admin_user: document.getElementById('s_user').value,
                admin_pass: document.getElementById('s_pass').value,
                mail_mode: document.getElementById('s_mode').value,
                tg_token: document.getElementById('s_token').value,
                tg_chatid: document.getElementById('s_chat').value
            })
        });
        if (!r2.ok) { toast(r2.message, true); return false; }
        toast('设置已保存');
        return true;
    });
}

async function delAcc(id) {
    const r = await callApi('delete', { method: 'POST', body: formData({ id: id }) });
    if (!r.ok) { toast(r.message, true); return; }
    if (state.selectedAccId === Number(id)) {
        state.selectedAccId = 0;
        resetMailPane('未选择账户', '从左栏点一个邮箱');
    }
    toast('账户已删除');
    await refreshAll();
}

// ============================================================================
// 模态框
// ============================================================================
function showForm(title, html, submitCb, opts = {}) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalForm').innerHTML = html;
    document.getElementById('formModal').style.display = 'flex';

    const btn = document.getElementById('submitBtn');
    btn.hidden = !!opts.hideSubmit;
    btn.onclick = async () => {
        btn.disabled = true;
        const label = btn.textContent;
        btn.textContent = '提交中...';
        let keepOpen = false;
        try {
            const ret = await submitCb();
            keepOpen = (ret === false);
        } finally {
            btn.disabled = false;
            btn.textContent = label;
        }
        if (keepOpen) return;
        closeModals();
        await refreshAll();
    };
}

function askConfirm(msg, cb) {
    document.getElementById('confirmMsg').textContent = msg;
    document.getElementById('confirmModal').style.display = 'flex';
    document.getElementById('confirmBtn').onclick = async () => {
        closeModals();
        await cb();
    };
}

function closeModals() {
    document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
}

// ============================================================================
// C-4：统一事件委托。所有交互只带 data-act + data-id / data-gid / data-idx，
// 不拼内联 onclick，也不把整条记录塞进 DOM。
// ============================================================================
document.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-act]');
    if (!el) return;

    const act = el.dataset.act;
    const id = Number(el.dataset.id || 0);
    const gid = Number(el.dataset.gid || 0);

    switch (act) {
        case 'add': openAdd(); break;
        case 'import': document.getElementById('importFile').click(); break;
        case 'export': location.href = 'api.php?action=export'; break;
        case 'token': window.open('/get_token.php', '_blank'); break;
        case 'logout': location.href = 'api.php?action=logout'; break;
        case 'settings': openSettings(); break;
        case 'tg-backup':
            askConfirm('同步当前所有数据到 TG 机器人？', async () => {
                const r = await callApi('tg_backup');
                toast(r.ok ? '备份任务已提交' : r.message, !r.ok);
            });
            break;
        case 'close-modal': closeModals(); break;

        case 'group-select': await selectGroup(gid); break;
        case 'group-new': openGroupForm('create', 0); break;
        case 'group-rename': openGroupForm('rename', gid); break;
        case 'group-delete':
            askConfirm('删除分组「' + groupName(gid) + '」？组内账户会回到默认分组，账户本身不会被删除。',
                () => deleteGroup(gid));
            break;

        case 'acc-open': await openAccount(id); break;
        case 'acc-edit': openEdit(id); break;
        case 'acc-delete': {
            const acc = findAccount(id);
            askConfirm('确定删除 ' + (acc ? acc.email : id) + '？', () => delAcc(id));
            break;
        }
        case 'acc-remove-from-group': await removeFromGroup(id); break;
        case 'acc-add-to-group': await openAddToGroup(id); break;
        case 'acc-add-to-group-ok': await addToGroup(id, gid); break;

        case 'mail-open': openMail(Number(el.dataset.idx)); break;
    }
});

// 搜索只在本地过滤，不重新请求
document.getElementById('searchInput').addEventListener('input', (e) => {
    state.keyword = e.target.value.trim().toLowerCase();
    renderAccounts();
});

document.getElementById('importFile').addEventListener('change', async (e) => {
    const input = e.target;
    if (!input.files || !input.files.length) return;
    const fd = new FormData();
    fd.append('file', input.files[0]);
    const r = await callApi('import', { method: 'POST', body: fd });
    input.value = '';
    toast(r.ok ? '导入完成' : r.message, !r.ok);
    await refreshAll();
});

// 初始化：先拉分组（横滑条要立刻有内容），再拉当前分组下的账户
(async function init() {
    await loadGroups();
    await loadAccounts();
})();
</script>
</body>
</html>
