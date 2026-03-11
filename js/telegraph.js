(function () {
  const flags = (window.AdminMapStateLoader && typeof window.AdminMapStateLoader.getFlags === 'function')
    ? window.AdminMapStateLoader.getFlags()
    : (window.ADMINMAP_FLAGS || {});
  if (flags.TELEGRAPH_V1 === false) return;

  const page = (location.pathname.split('/').pop() || '').toLowerCase();
  const isAdmin = ['admin.html', 'admin_ui_alt.html', 'admin_v2.html', 'vk_admin.html', 'admin_orders_ui_alt.html'].includes(page);
  const isPlayer = ['player_admin.html', 'player_admin_ui_alt.html', 'entity_cabinet.html', 'entity_cabinet_ui_alt.html'].includes(page);
  const isPublic = ['index.html', 'index_ui_alt.html'].includes(page);
  const canCompose = isAdmin || isPlayer;
  const adminToken = localStorage.getItem('admin_token') || 'dev-admin-token';

  const tabs = isAdmin
    ? [
      { key: 'all', label: 'Все' },
      { key: 'public', label: 'Публичные' },
      { key: 'private', label: 'Приватные' },
      { key: 'diplomatic', label: 'Дипломатия' },
      { key: 'system', label: 'Системные' },
      { key: 'pending', label: 'Модерация' },
      { key: 'mine', label: 'Мои' },
      { key: 'unread', label: 'Непрочитанные' },
    ]
    : isPlayer
      ? [
        { key: 'public', label: 'Публичные' },
        { key: 'inbox', label: 'Входящие' },
        { key: 'outbox', label: 'Исходящие' },
        { key: 'private', label: 'Приватные' },
        { key: 'diplomatic', label: 'Дипломатия' },
        { key: 'system', label: 'Системные' },
        { key: 'unread', label: 'Непрочитанные' },
      ]
      : [
        { key: 'public', label: 'Публичные' },
        { key: 'system', label: 'Системные' },
      ];

  let actor = null;
  let currentRows = [];
  let activeTab = tabs[0].key;
  let forcedFilters = {};
  let entityOptions = [];

  const qs = (s, r) => (r || document).querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch));

  async function api(path, opts) {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, (opts && opts.headers) || {});
    const res = await fetch(path, Object.assign({}, opts || {}, { headers }));
    return res.json();
  }

  function apiWithAdmin(path, opts) {
    const headers = Object.assign({ 'X-Admin-Token': adminToken }, (opts && opts.headers) || {});
    return api(path, Object.assign({}, opts || {}, { headers }));
  }

  function statusClass(status) {
    if (status === 'approved' || status === 'delivered') return 'ok';
    if (status === 'pending') return 'pending';
    if (status === 'rejected') return 'danger';
    if (status === 'needs_clarification') return 'warn';
    return '';
  }

  function mount() {
    if (qs('#telegraphFab')) return;

    const fab = document.createElement('button');
    fab.id = 'telegraphFab';
    fab.type = 'button';
    fab.className = 'telegraph-fab';
    fab.textContent = 'Телеграф';

    const rail = document.createElement('aside');
    rail.id = 'telegraphPublicRail';
    rail.className = 'telegraph-rail' + (isPublic ? ' telegraph-rail--visible' : '');
    rail.innerHTML = '<div class="telegraph-rail__head"><b>Публичная лента</b><button id="telegraphRailOpen" type="button">Открыть</button></div><div id="telegraphRailFeed" class="telegraph-rail__feed"></div>';

    const modal = document.createElement('div');
    modal.id = 'telegraphModal';
    modal.className = 'telegraph-modal';
    modal.innerHTML = `
      <div class="telegraph-window">
        <div class="telegraph-head">
          <b>Телеграф</b>
          <div><span id="telegraphUnread" class="telegraph-badge">0</span><button id="telegraphClose" type="button">×</button></div>
        </div>
        <div class="telegraph-tabs">${tabs.map(t => `<button data-tab="${t.key}" type="button">${t.label}</button>`).join('')}</div>
        <div class="telegraph-tools">
          <input id="telegraphSearch" placeholder="Поиск" />
          <select id="telegraphStatusFilter"><option value="">Статус: все</option><option value="pending">pending</option><option value="approved">approved</option><option value="rejected">rejected</option><option value="needs_clarification">needs_clarification</option></select>
          <select id="telegraphSourceFilter"><option value="">Источник: все</option><option value="web">web</option><option value="vk_private">vk_private</option><option value="vk_chat">vk_chat</option><option value="system">system</option></select>
          ${canCompose ? '<button id="telegraphComposeOpen" type="button">Новая телеграмма</button>' : '<span></span>'}
        </div>
        <div id="telegraphCounters" class="telegraph-counters"></div>
        <div id="telegraphFeed" class="telegraph-feed"></div>
        ${canCompose ? `
        <div id="telegraphCompose" class="telegraph-compose" style="display:none">
          <select id="tgScope"><option value="public">Публичная</option><option value="private">Приватная</option><option value="diplomatic">Дипломатическая</option>${isAdmin ? '<option value="admin">Служебная</option><option value="system">Системная</option>' : ''}</select>
          <select id="tgTarget"><option value="">Получатель: выберите сущность</option></select>
          ${isAdmin ? '<select id="tgSender"><option value="">Отправитель: Администратор</option></select>' : ''}
          <input id="tgTitle" placeholder="Заголовок" />
          <textarea id="tgBody" placeholder="Текст"></textarea>
          <input id="tgTags" placeholder="Теги через запятую" />
          <label><input id="tgRelayVk" type="checkbox" checked /> Ретрансляция в VK (если публичная)</label>
          <button id="tgSend" type="button">Отправить</button>
        </div>` : ''}
      </div>
      <div id="telegraphThreadModal" class="telegraph-thread-modal"><div class="telegraph-thread-window"><div class="telegraph-thread-head"><b>Тред</b><button id="telegraphThreadClose" type="button">×</button></div><div id="telegraphThreadBody"></div></div></div>
    `;

    document.body.appendChild(fab);
    document.body.appendChild(rail);
    document.body.appendChild(modal);

    fab.onclick = () => { modal.classList.add('open'); loadFeed(); };
    qs('#telegraphClose', modal).onclick = () => modal.classList.remove('open');
    qs('#telegraphThreadClose', modal).onclick = () => qs('#telegraphThreadModal', modal).classList.remove('open');
    qs('#telegraphSearch', modal).addEventListener('input', debounce(loadFeed, 250));
    qs('#telegraphStatusFilter', modal).onchange = loadFeed;
    qs('#telegraphSourceFilter', modal).onchange = loadFeed;
    qs('#telegraphRailOpen', rail).onclick = () => { modal.classList.add('open'); forcedFilters = { scope: 'public' }; loadFeed(); };

    modal.querySelectorAll('.telegraph-tabs button').forEach(btn => {
      btn.onclick = () => {
        activeTab = btn.dataset.tab;
        forcedFilters = {};
        modal.querySelectorAll('.telegraph-tabs button').forEach(b => b.classList.toggle('active', b === btn));
        loadFeed();
      };
    });
    modal.querySelector('.telegraph-tabs button')?.classList.add('active');

    if (canCompose) {
      qs('#telegraphComposeOpen', modal).onclick = () => {
        const el = qs('#telegraphCompose', modal);
        el.style.display = el.style.display === 'none' ? '' : 'none';
      };
      qs('#tgScope', modal).onchange = onScopeChange;
      qs('#tgSend', modal).onclick = sendMsg;
      loadEntityOptions();
    }

    refreshUnread();
    loadRail();
    setInterval(refreshUnread, 20000);
    setInterval(loadRail, 30000);
  }


  function normalizeEntityOptions(state) {
    const buckets = ['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'];
    const out = [];
    const seen = new Set();

    const pushEntity = (bucket, id, row, suffix) => {
      const entityId = String(id || '').trim();
      if (!entityId) return;
      const value = `${bucket}:${entityId}`;
      if (seen.has(value)) return;
      seen.add(value);
      const name = String((row && row.name) || entityId).trim();
      const tail = suffix ? ` · ${suffix}` : '';
      out.push({ value, label: `${name} (${bucket}:${entityId}${tail})` });
    };

    buckets.forEach((bucket) => {
      const rows = state && typeof state === 'object' ? (state[bucket] || {}) : {};
      Object.entries(rows).forEach(([id, row]) => pushEntity(bucket, id, row, ''));
    });

    ['great_houses', 'special_territories'].forEach((parentType) => {
      const parents = state && typeof state === 'object' ? (state[parentType] || {}) : {};
      Object.entries(parents).forEach(([parentId, parent]) => {
        const layer = parent && typeof parent === 'object' ? (parent.layer || {}) : {};
        const vassals = Array.isArray(layer.vassals) ? layer.vassals : [];
        vassals.forEach((v) => {
          const vassalId = String((v && v.id) || '').trim();
          if (!vassalId) return;
          pushEntity('minor_houses', vassalId, v, `вассал ${parentType}:${parentId}`);
        });
      });
    });

    out.sort((a, b) => a.label.localeCompare(b.label, 'ru'));
    return out;
  }

  function renderEntitySelect(selectId, placeholder, includeEmpty) {
    const modal = qs('#telegraphModal');
    const el = qs('#' + selectId, modal);
    if (!el) return;
    const opts = [];
    if (includeEmpty) opts.push(`<option value="">${esc(placeholder)}</option>`);
    opts.push(...entityOptions.map(o => `<option value="${esc(o.value)}">${esc(o.label)}</option>`));
    el.innerHTML = opts.join('');
  }

  function onScopeChange() {
    const modal = qs('#telegraphModal');
    if (!modal) return;
    const scope = qs('#tgScope', modal)?.value || 'public';
    const targetEl = qs('#tgTarget', modal);
    if (!targetEl) return;
    const needsEntityTarget = !(scope === 'public' || scope === 'system');
    targetEl.disabled = !needsEntityTarget;
    if (!needsEntityTarget) targetEl.value = '';
  }

  async function loadEntityOptions() {
    if (!canCompose) return;
    if (entityOptions.length) return;
    const bootstrap = await api('/api/map/bootstrap/').catch(() => null);
    const state = bootstrap && bootstrap.state && typeof bootstrap.state === 'object' ? bootstrap.state : (bootstrap && typeof bootstrap === 'object' ? bootstrap : null);
    entityOptions = state ? normalizeEntityOptions(state) : [];
    renderEntitySelect('tgTarget', 'Получатель: выберите сущность', true);
    if (isAdmin) renderEntitySelect('tgSender', 'Отправитель: Администратор', true);
    onScopeChange();
  }


  function buildQuery() {
    const modal = qs('#telegraphModal');
    const params = new URLSearchParams();
    const q = (qs('#telegraphSearch', modal)?.value || '').trim();
    if (q) params.set('q', q);
    const status = qs('#telegraphStatusFilter', modal)?.value || '';
    const source = qs('#telegraphSourceFilter', modal)?.value || '';
    if (status) params.set('status', status);
    if (source) params.set('source_type', source);

    if (activeTab === 'pending') params.set('status', 'pending');
    else if (['public', 'private', 'diplomatic', 'system', 'admin'].includes(activeTab)) params.set('scope', activeTab);
    if (activeTab === 'unread') params.set('unread_only', '1');

    if (forcedFilters.linked_order_id) params.set('linked_order_id', String(forcedFilters.linked_order_id));
    if (forcedFilters.linked_verdict_id) params.set('linked_verdict_id', String(forcedFilters.linked_verdict_id));
    if (forcedFilters.scope) params.set('scope', String(forcedFilters.scope));

    params.set('per_page', '100');
    return '?' + params.toString();
  }

  async function loadFeed() {
    const modal = qs('#telegraphModal');
    if (!modal) return;
    const data = await api('/api/telegraph/list/' + buildQuery()).catch(() => ({ rows: [] }));
    actor = data.actor || actor;
    currentRows = Array.isArray(data.rows) ? data.rows : [];

    let rows = currentRows.slice();
    if (activeTab === 'inbox' && actor) rows = rows.filter(r => (r.target || {}).target_entity_type === actor.entity_type && (r.target || {}).target_entity_id === actor.entity_id);
    if (activeTab === 'outbox' && actor) rows = rows.filter(r => (r.sender || {}).sender_entity_type === actor.entity_type && (r.sender || {}).sender_entity_id === actor.entity_id);
    if (activeTab === 'mine' && actor) rows = rows.filter(r => ((r.sender || {}).sender_entity_type === actor.entity_type && (r.sender || {}).sender_entity_id === actor.entity_id) || ((r.target || {}).target_entity_type === actor.entity_type && (r.target || {}).target_entity_id === actor.entity_id));

    renderCounters(rows);
    const feed = qs('#telegraphFeed', modal);
    feed.innerHTML = rows.map(renderItem).join('') || '<div class="telegraph-empty">Нет сообщений</div>';
    feed.querySelectorAll('[data-open-thread]').forEach(btn => btn.onclick = () => openThread(btn.dataset.openThread));
    if (isAdmin) feed.querySelectorAll('[data-moderate]').forEach(btn => btn.onclick = () => moderate(btn.dataset.id, btn.dataset.moderate));
  }

  function renderCounters(rows) {
    const counters = { public: 0, private: 0, diplomatic: 0, system: 0, admin: 0, pending: 0, rejected: 0 };
    rows.forEach(r => {
      const scope = r.scope || 'public';
      if (counters[scope] !== undefined) counters[scope]++;
      const st = ((r.moderation || {}).status || '');
      if (st === 'pending') counters.pending++;
      if (st === 'rejected') counters.rejected++;
    });
    qs('#telegraphCounters').innerHTML = Object.entries(counters).map(([k, v]) => `<span class="telegraph-pill">${k}: ${v}</span>`).join('');
  }

  function renderItem(row) {
    const status = ((row.moderation || {}).status || 'draft');
    const sender = esc(((row.sender || {}).sender_display_name || '—'));
    const title = esc(((row.content || {}).title || '(без заголовка)'));
    const preview = esc(((row.content || {}).short_preview || ''));
    const linkedOrder = esc((((row.game_hooks || {}).linked_order_id) || ''));
    const linkedVerdict = esc((((row.game_hooks || {}).linked_verdict_id) || ''));
    const linkedThread = esc((((row.game_hooks || {}).linked_diplomacy_thread_id) || ''));
    const links = [];
    if (linkedOrder) links.push(`<a href="/admin_orders_ui_alt.html#${linkedOrder}" target="_blank" rel="noreferrer">приказ ${linkedOrder}</a>`);
    if (linkedVerdict) links.push(`<a href="/admin_orders_ui_alt.html#${linkedVerdict}" target="_blank" rel="noreferrer">вердикт ${linkedVerdict}</a>`);
    if (linkedThread) links.push(`<button type="button" data-open-thread="${esc(row.id)}">тред ${linkedThread}</button>`);
    const moderation = isAdmin && status === 'pending'
      ? `<div class="telegraph-mod"><button data-id="${esc(row.id)}" data-moderate="approve" type="button">approve</button><button data-id="${esc(row.id)}" data-moderate="reject" type="button">reject</button><button data-id="${esc(row.id)}" data-moderate="needs_clarification" type="button">needs_clarification</button></div>`
      : '';
    return `<article class="telegraph-item">
      <header><span class="scope">${esc(row.scope || '')}</span><span class="status ${statusClass(status)}">${esc(status)}</span></header>
      <h4>${title}</h4>
      <p>${preview}</p>
      <div class="telegraph-links">${links.join(' · ')}</div>
      ${moderation}
      <footer>${sender} · ${esc(row.created_at || '')}</footer>
    </article>`;
  }

  async function sendMsg() {
    const modal = qs('#telegraphModal');
    const scope = qs('#tgScope', modal).value;
    const targetRaw = (qs('#tgTarget', modal).value || '').trim();
    const target = { target_type: scope === 'public' || scope === 'system' ? 'none' : 'entity' };

    if (target.target_type === 'entity') {
      if (!targetRaw || targetRaw.indexOf(':') < 1) return alert('Выберите получателя из списка');
      const [targetType, targetId] = targetRaw.split(':');
      target.target_entity_type = targetType;
      target.target_entity_id = targetId;
    }

    const payload = {
      scope,
      target,
      title: qs('#tgTitle', modal).value,
      body: qs('#tgBody', modal).value,
      tags: (qs('#tgTags', modal).value || '').split(',').map(v => v.trim()).filter(Boolean),
      relay_to_vk_public_chat: !!qs('#tgRelayVk', modal).checked,
      idempotency_key: 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2),
    };

    if (isAdmin) {
      const senderRaw = (qs('#tgSender', modal)?.value || '').trim();
      if (senderRaw) {
        if (senderRaw.indexOf(':') < 1) return alert('Выберите отправителя из списка');
        const [senderType, senderId] = senderRaw.split(':');
        payload.sender_override = {
          sender_entity_type: senderType,
          sender_entity_id: senderId,
        };
      }
    }

    const sender = isAdmin ? apiWithAdmin : api;
    const data = await sender('/api/telegraph/send/', { method: 'POST', body: JSON.stringify(payload) });
    if (data && data.ok) {
      qs('#tgBody', modal).value = '';
      qs('#tgTitle', modal).value = '';
      loadFeed();
      loadRail();
      refreshUnread();
    } else {
      alert('Ошибка отправки телеграммы');
    }
  }

  async function moderate(id, action) {
    const note = window.prompt('Комментарий модерации', '') || '';
    const data = await apiWithAdmin('/api/telegraph/moderate/', { method: 'POST', body: JSON.stringify({ id, action, moderation_note: note }) }).catch(() => null);
    if (!data || !data.ok) return alert('Не удалось выполнить модерацию');
    loadFeed();
    loadRail();
  }

  function openThread(messageId) {
    const row = currentRows.find(r => String(r.id) === String(messageId));
    if (!row) return;
    const threadId = ((row.game_hooks || {}).linked_diplomacy_thread_id || row.id || '').toString();
    const body = qs('#telegraphThreadBody');
    const rows = currentRows.filter(r => String(((r.game_hooks || {}).linked_diplomacy_thread_id || r.id || '')) === threadId);
    body.innerHTML = rows.map(r => `<article class="telegraph-item"><header><span>${esc(r.scope)}</span><span class="status ${statusClass((r.moderation || {}).status || '')}">${esc((r.moderation || {}).status || '')}</span></header><h4>${esc((r.content || {}).title || '(без заголовка)')}</h4><p>${esc((r.content || {}).body || '')}</p><footer>${esc((r.sender || {}).sender_display_name || '—')} · ${esc(r.created_at || '')}</footer></article>`).join('');
    qs('#telegraphThreadModal').classList.add('open');
  }

  async function loadRail() {
    const feed = qs('#telegraphRailFeed');
    if (!feed) return;
    const data = await api('/api/telegraph/list/?scope=public&per_page=8').catch(() => ({ rows: [] }));
    const rows = Array.isArray(data.rows) ? data.rows : [];
    feed.innerHTML = rows.map(r => {
      const sender = (r.sender || {}).sender_display_name || '';
      const fallbackTitle = sender ? ('Телеграмма от ' + sender) : 'Публичная телеграмма';
      return `<div class="telegraph-rail__item"><b>${esc((r.content || {}).title || fallbackTitle)}</b><div>${esc((r.content || {}).short_preview || '')}</div></div>`;
    }).join('') || '<div class="telegraph-empty">Нет сообщений</div>';
  }

  async function refreshUnread() {
    const data = await api('/api/telegraph/unread/').catch(() => ({ counts: { total: 0 } }));
    const count = ((data || {}).counts || {}).total || 0;
    const badge = qs('#telegraphUnread');
    if (badge) badge.textContent = String(count);
  }


  function applyRequestedTab(tabKey) {
    if (!tabKey) return;
    const modal = qs('#telegraphModal');
    if (!modal) return;
    const valid = tabs.some(t => t.key === tabKey);
    if (!valid) return;
    activeTab = tabKey;
    modal.querySelectorAll('.telegraph-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === tabKey));
  }

  function debounce(fn, delay) {
    let t = 0;
    return function () { clearTimeout(t); t = setTimeout(fn, delay); };
  }

  window.addEventListener('adminmap:telegraph-open', function (e) {
    const d = (e && e.detail) || {};
    applyRequestedTab((d.tab || '').toString());
    const nextFilters = Object.assign({}, d || {});
    delete nextFilters.tab;
    forcedFilters = nextFilters;
    const modal = qs('#telegraphModal');
    if (modal) {
      modal.classList.add('open');
      loadFeed();
    }
  });

  document.addEventListener('DOMContentLoaded', mount);
})();
