/* =====================================================================
   CouponFind — shared UI helpers
   toasts · modals · command palette · formatting · icons · skeletons
   ===================================================================== */
(function (global) {
  // ---- DOM helpers ----
  const el = (sel, root = document) => root.querySelector(sel);
  const els = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  function h(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') node.className = v;
      else if (k === 'html') node.innerHTML = v;
      else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
      else if (v !== null && v !== undefined) node.setAttribute(k, v);
    }
    (Array.isArray(children) ? children : [children]).forEach(c => {
      if (c == null) return;
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  // ---- Toasts ----
  function ensureToastRoot() {
    let r = el('#toasts');
    if (!r) { r = h('div', { id: 'toasts' }); document.body.appendChild(r); }
    return r;
  }
  function toast(message, type = 'info', timeout = 3500) {
    const root = ensureToastRoot();
    const t = h('div', { class: 'toast ' + type }, message);
    root.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; t.style.transition = 'all .25s'; setTimeout(() => t.remove(), 250); }, timeout);
  }

  // ---- Formatting ----
  const fmt = {
    money(cents, currency = 'USD') {
      const v = (Number(cents) || 0) / 100;
      try { return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(v); }
      catch (e) { return '$' + v.toFixed(2); }
    },
    moneyVal(v, currency = 'USD') {
      try { return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(Number(v) || 0); }
      catch (e) { return '$' + (Number(v) || 0).toFixed(2); }
    },
    num(n) { return new Intl.NumberFormat('en-US').format(Number(n) || 0); },
    date(s) { if (!s) return '—'; const d = new Date(s.replace(' ', 'T') + (s.includes('T') ? '' : 'Z')); return isNaN(d) ? s : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); },
    ago(s) {
      if (!s) return '—';
      const d = new Date(s.replace(' ', 'T') + (s.includes('T') ? '' : 'Z'));
      const diff = (Date.now() - d.getTime()) / 1000;
      if (diff < 60) return 'just now';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      return Math.floor(diff / 86400) + 'd ago';
    },
    discount(c) {
      if (c.discount_type === 'percent' && c.discount_value) return Math.round(c.discount_value) + '% OFF';
      if (c.discount_type === 'amount' && c.discount_value) return '$' + Math.round(c.discount_value) + ' OFF';
      if (c.discount_type === 'free_shipping' || c.type === 'free_shipping') return 'FREE SHIP';
      return 'DEAL';
    }
  };

  // ---- Icons (inline SVG, currentColor) ----
  const icon = (name) => ({
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>',
    dashboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>',
    bookmark: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 21-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>',
    eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
    bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
    bolt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h9l-1 8 10-12h-9z"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    card: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
    user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    gift: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M5 12v9h14v-9"/><path d="M12 8S9 2 6.5 4 12 8 12 8Zm0 0s3-6 5.5-4S12 8 12 8Z"/></svg>',
    settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 7.6 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0-1.17-2.82H1a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 7.6a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V1a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H23a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>',
    users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-4-6.3"/></svg>',
    tag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 7v5l8 8 7-7-8-8H4a2 2 0 0 0-2 2Z"/><circle cx="6.5" cy="9.5" r="1"/></svg>',
    store: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9 4 4h16l1 5M4 9v11h16V9M4 9h16"/></svg>',
    chart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/></svg>',
    cpu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"/></svg>',
    spider: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 9V3M5 5l3 4M19 5l-3 4M3 12h4M17 12h4M5 19l3-4M19 19l-3-4M12 15v6"/></svg>',
    shield: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/></svg>',
    box: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/></svg>',
    flag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22V4s2-1 5-1 5 2 8 2 3-1 3-1v10s-1 1-3 1-5-2-8-2-5 1-5 1"/></svg>',
    list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
    heart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 6 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>',
    copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    logout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
    activity: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
  }[name] || '');

  // ---- Modal ----
  function modal(title, contentNode, opts = {}) {
    const backdrop = h('div', { class: 'modal-backdrop open' });
    const box = h('div', { class: 'modal card', style: 'padding:1.4rem;' });
    box.appendChild(h('div', { class: 'flex items-center justify-between', style: 'margin-bottom:1rem;' }, [
      h('h3', { class: 'h-display', style: 'font-size:1.15rem;' }, title),
      h('button', { class: 'btn btn-ghost btn-sm', onclick: () => backdrop.remove() }, '✕'),
    ]));
    box.appendChild(contentNode);
    backdrop.appendChild(box);
    backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.remove(); });
    document.body.appendChild(backdrop);
    return { close: () => backdrop.remove(), node: box };
  }

  function confirmDialog(message, onConfirm) {
    const body = h('div', {}, [
      h('p', { class: 'text-muted', style: 'margin:0 0 1.2rem;' }, message),
      h('div', { class: 'flex justify-end gap-2' }, [
        h('button', { class: 'btn btn-ghost', onclick: () => m.close() }, 'Cancel'),
        h('button', { class: 'btn btn-danger', onclick: () => { m.close(); onConfirm(); } }, 'Confirm'),
      ]),
    ]);
    const m = modal('Are you sure?', body);
  }

  // ---- Command palette ----
  let cmdkItems = [];
  function setupCommandPalette(items) {
    cmdkItems = items;
    let root = el('#cmdk');
    if (!root) {
      root = h('div', { id: 'cmdk' }, [
        h('div', { class: 'panel card', style: 'padding:0.5rem;' }, [
          h('input', { id: 'cmdk-input', class: 'input', placeholder: 'Type a command or search…', style: 'border:none;background:transparent;font-size:1rem;' }),
          h('div', { id: 'cmdk-list', style: 'margin-top:0.4rem;max-height:50vh;overflow:auto;' }),
        ]),
      ]);
      document.body.appendChild(root);
      root.addEventListener('click', e => { if (e.target === root) closeCmdk(); });
      el('#cmdk-input', root).addEventListener('input', renderCmdk);
      el('#cmdk-input', root).addEventListener('keydown', cmdkKeys);
    }
    document.addEventListener('keydown', e => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); toggleCmdk(); }
      if (e.key === 'Escape') closeCmdk();
    });
  }
  let cmdkActive = 0;
  function renderCmdk() {
    const q = (el('#cmdk-input').value || '').toLowerCase();
    const list = el('#cmdk-list');
    const filtered = cmdkItems.filter(i => i.label.toLowerCase().includes(q));
    cmdkActive = 0;
    list.innerHTML = '';
    filtered.forEach((i, idx) => {
      list.appendChild(h('div', { class: 'cmdk-item' + (idx === 0 ? ' active' : ''), onclick: () => { closeCmdk(); i.action(); } }, [
        h('span', { style: 'width:18px;height:18px;display:inline-flex;', html: icon(i.icon || 'bolt') }),
        h('span', {}, i.label),
      ]));
    });
    if (!filtered.length) list.appendChild(h('div', { class: 'text-muted', style: 'padding:0.7rem 1rem;' }, 'No results'));
  }
  function cmdkKeys(e) {
    const items = els('.cmdk-item');
    if (e.key === 'ArrowDown') { e.preventDefault(); cmdkActive = Math.min(cmdkActive + 1, items.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cmdkActive = Math.max(cmdkActive - 1, 0); }
    else if (e.key === 'Enter') { items[cmdkActive]?.click(); return; }
    else return;
    items.forEach((it, i) => it.classList.toggle('active', i === cmdkActive));
  }
  function toggleCmdk() { const r = el('#cmdk'); r.classList.contains('open') ? closeCmdk() : openCmdk(); }
  function openCmdk() { const r = el('#cmdk'); r.classList.add('open'); el('#cmdk-input').value = ''; renderCmdk(); setTimeout(() => el('#cmdk-input').focus(), 30); }
  function closeCmdk() { el('#cmdk')?.classList.remove('open'); }

  // ---- Misc ----
  function copyToClipboard(text) {
    if (navigator.clipboard) return navigator.clipboard.writeText(text).then(() => toast('Copied to clipboard', 'ok'));
    const ta = h('textarea', { style: 'position:fixed;opacity:0;' }); ta.value = text; document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); ta.remove(); toast('Copied', 'ok');
  }
  function skeletonList(n = 5, height = '64px') {
    return h('div', { class: 'grid gap-2' }, Array.from({ length: n }, () => h('div', { class: 'skeleton', style: 'height:' + height })));
  }
  function requireAuthRedirect() {
    if (!API.isAuthed()) { location.href = '/login?next=' + encodeURIComponent(location.pathname); return false; }
    return true;
  }

  global.UI = { el, els, h, esc, toast, fmt, icon, modal, confirmDialog, setupCommandPalette, openCmdk, copyToClipboard, skeletonList, requireAuthRedirect };
})(window);
