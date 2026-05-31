/* =====================================================================
   CouponFind — user dashboard application (hash-routed SPA)
   ===================================================================== */
(function () {
  const { el, els, h, esc, toast, fmt, icon, modal, confirmDialog, copyToClipboard, skeletonList } = UI;

  if (!UI.requireAuthRedirect()) return;

  let me = API.store.user || {};
  if (me && me.is_admin) { /* admins can still use the user app */ }

  // ---- Static UI bits ----
  el('#cmd-ico').innerHTML = icon('bolt');
  el('#hdr-search-ico').innerHTML = icon('search');
  el('#bell-ico').innerHTML = icon('bell');
  el('#logout-ico').innerHTML = icon('logout');

  const NAV = [
    { id: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
    { id: 'search', label: 'AI Search', icon: 'search' },
    { id: 'saved', label: 'Saved Coupons', icon: 'bookmark' },
    { id: 'watchlist', label: 'Watchlist', icon: 'eye' },
    { id: 'alerts', label: 'Deal Alerts', icon: 'bolt' },
    { id: 'notifications', label: 'Notifications', icon: 'bell' },
    { id: 'history', label: 'Search History', icon: 'clock' },
    { id: 'billing', label: 'Billing & Plans', icon: 'card' },
    { id: 'invoices', label: 'Invoices', icon: 'list' },
    { id: 'referrals', label: 'Referrals', icon: 'gift' },
    { id: 'profile', label: 'Profile & Settings', icon: 'settings' },
  ];

  const nav = el('#nav');
  NAV.forEach(n => nav.appendChild(h('a', { class: 'nav-item', 'data-route': n.id, href: '#' + n.id }, [
    h('span', { html: icon(n.icon) }), h('span', {}, n.label),
  ])));

  function setActive(route) {
    els('.nav-item', nav).forEach(a => a.classList.toggle('active', a.getAttribute('data-route') === route));
  }

  // ---- Header user ----
  function paintUser() {
    el('#user-name').textContent = me.name || 'User';
    el('#avatar').textContent = (me.name || 'U').trim()[0].toUpperCase();
  }
  paintUser();

  el('#logout').addEventListener('click', async () => { await API.logout(); location.href = '/login'; });
  el('#global-search').addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.value.trim()) { location.hash = 'search?q=' + encodeURIComponent(e.target.value.trim()); }
  });
  el('#bell').addEventListener('click', () => { location.hash = 'notifications'; });

  // ---- Command palette ----
  UI.setupCommandPalette([
    ...NAV.map(n => ({ label: 'Go to ' + n.label, icon: n.icon, action: () => location.hash = n.id })),
    { label: 'Log out', icon: 'logout', action: async () => { await API.logout(); location.href = '/login'; } },
  ]);

  // ---- View container ----
  const view = el('#view');
  function setView(node) { view.innerHTML = ''; view.appendChild(node); }
  function loading() { setView(skeletonList(6, '72px')); }
  function pageTitle(title, subtitle, actions) {
    return h('div', { class: 'flex items-end justify-between mb-6 fade-up' }, [
      h('div', {}, [h('h1', { class: 'h-display', style: 'font-size:1.6rem;' }, title), subtitle ? h('p', { class: 'text-muted text-sm mt-1' }, subtitle) : null]),
      actions || h('div'),
    ]);
  }

  function statCard(label, value, sub, ic) {
    return h('div', { class: 'card p-5' }, [
      h('div', { class: 'flex items-center justify-between' }, [
        h('span', { class: 'text-muted text-xs uppercase tracking-wide' }, label),
        h('span', { class: 'feature-ico', style: 'width:30px;height:30px;', html: icon(ic) }),
      ]),
      h('div', { class: 'h-display mt-3', style: 'font-size:1.8rem;' }, value),
      sub ? h('div', { class: 'text-muted text-xs mt-1' }, sub) : null,
    ]);
  }

  // ---- Coupon card (reused) ----
  function couponCard(c, opts = {}) {
    const actions = [];
    if (c.code) {
      actions.push(h('button', { class: 'code-pill btn-soft', style: 'cursor:pointer;', onclick: () => { copyToClipboard(c.code); API.post('/coupons/' + (c.id || c.coupon_id) + '/use', {}).catch(() => {}); } },
        [c.code + '  ', h('span', { style: 'width:13px;height:13px;display:inline-block;vertical-align:-2px;', html: icon('copy') })]));
    } else {
      actions.push(h('a', { class: 'btn btn-soft btn-sm', href: c.landing_url || '#', target: '_blank' }, 'View deal'));
    }
    const right = opts.saved
      ? h('button', { class: 'btn btn-ghost btn-sm', onclick: () => opts.onRemove() }, 'Remove')
      : h('button', { class: 'btn btn-ghost btn-sm', title: 'Save', onclick: () => save(c.id) }, [h('span', { style: 'width:15px;height:15px;display:inline-flex;', html: icon('bookmark') })]);

    return h('div', { class: 'card coupon-card p-5 flex flex-col gap-3' }, [
      h('div', { class: 'flex items-center gap-2' }, [
        h('span', { class: 'badge badge-accent' }, fmt.discount(c)),
        h('span', { class: 'text-muted text-xs' }, c.merchant_name),
      ]),
      h('h4', { class: 'font-bold', style: 'font-size:1rem;line-height:1.3;' }, c.title),
      c.description ? h('p', { class: 'text-muted text-sm', style: 'margin:0;' }, c.description) : null,
      h('div', { class: 'flex items-center justify-between mt-1' }, [actions[0], right]),
    ]);
  }

  async function save(couponId) {
    try { await API.post('/me/saved', { coupon_id: couponId }); toast('Saved', 'ok'); } catch (e) { toast(e.message, 'err'); }
  }

  // =================== VIEWS ===================
  const Views = {
    async dashboard() {
      loading();
      const d = await API.get('/me/dashboard');
      const q = d.quota || {};
      const wrap = h('div', {}, [
        pageTitle('Dashboard', 'Welcome back, ' + (me.name || '') + ' 👋'),
        h('div', { class: 'grid md:grid-cols-4 gap-4 stagger' }, [
          statCard('Searches used', q.unlimited ? '∞' : (q.used + ' / ' + q.limit), 'per ' + (q.window || 'day') + ' · ' + (q.plan || 'free'), 'search'),
          statCard('Saved coupons', fmt.num(d.saved_count), 'in your library', 'bookmark'),
          statCard('Watching', fmt.num(d.watch_count), 'merchants & keywords', 'eye'),
          statCard('Unread', fmt.num(d.unread), 'notifications', 'bell'),
        ]),
        h('div', { class: 'grid md:grid-cols-2 gap-5 mt-6' }, [
          (function () {
            const box = h('div', { class: 'card p-5' }, [h('h3', { class: 'font-bold mb-3' }, 'Recent searches')]);
            if (!d.recent_search || !d.recent_search.length) box.appendChild(h('p', { class: 'text-muted text-sm' }, 'No searches yet. Try the AI search!'));
            (d.recent_search || []).forEach(s => box.appendChild(h('div', { class: 'flex items-center justify-between py-2 border-b hairline', style: 'border-bottom-width:1px;' }, [
              h('a', { href: '#search?q=' + encodeURIComponent(s.query_raw), style: 'color:var(--text);text-decoration:none;' }, s.query_raw),
              h('span', { class: 'text-muted text-xs' }, fmt.num(s.result_count) + ' results · ' + fmt.ago(s.created_at)),
            ])));
            return box;
          })(),
          (function () {
            const box = h('div', { class: 'card p-5' }, [h('h3', { class: 'font-bold mb-3' }, 'Saved highlights')]);
            if (!d.saved || !d.saved.length) box.appendChild(h('p', { class: 'text-muted text-sm' }, 'Nothing saved yet.'));
            (d.saved || []).slice(0, 4).forEach(c => box.appendChild(h('div', { class: 'flex items-center justify-between py-2' }, [
              h('span', { class: 'text-sm' }, c.title), h('span', { class: 'badge badge-accent' }, fmt.discount(c)),
            ])));
            return box;
          })(),
        ]),
      ]);
      setView(wrap);
    },

    async search(params) {
      const wrap = h('div', {}, [
        pageTitle('AI Search', 'Type naturally — typos welcome.'),
        h('div', { class: 'card p-2 flex items-center gap-2 mb-5' }, [
          h('span', { style: 'padding-left:0.5rem;width:20px;color:var(--muted);', html: icon('search') }),
          h('input', { id: 'sq', class: 'input', style: 'border:none;background:transparent;font-size:1.05rem;', placeholder: 'best amazon coupon today…' }),
          h('button', { class: 'btn btn-primary', onclick: doSearch }, 'Search'),
        ]),
        h('div', { id: 'sresults' }),
      ]);
      setView(wrap);
      const input = el('#sq');
      input.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });
      const q = params.get('q');
      if (q) { input.value = q; doSearch(); } else input.focus();

      async function doSearch() {
        const query = input.value.trim();
        const box = el('#sresults');
        if (!query) return;
        box.innerHTML = ''; box.appendChild(skeletonList(4, '96px'));
        try {
          const data = await API.post('/search', { q: query });
          box.innerHTML = '';
          box.appendChild(h('div', { class: 'flex items-center justify-between text-sm text-muted mb-3' }, [
            h('span', {}, fmt.num(data.count) + ' results · ' + data.took_ms + 'ms · via ' + data.source + (data.cache_hit ? ' (cached)' : '')),
            data.quota ? h('span', {}, data.quota.unlimited ? '∞ searches' : (data.quota.remaining + ' left today')) : h('span'),
          ]));
          if (!data.results.length) { box.appendChild(h('div', { class: 'card p-8 text-center text-muted' }, 'No results. Try another query.')); return; }
          const grid = h('div', { class: 'grid md:grid-cols-2 gap-3 stagger' });
          data.results.forEach(c => grid.appendChild(couponCard(c)));
          box.appendChild(grid);
        } catch (e) {
          box.innerHTML = '';
          box.appendChild(h('div', { class: 'card p-6 text-center' }, [
            h('p', { class: 'font-semibold' }, e.status === 402 ? 'Search limit reached' : 'Search failed'),
            h('p', { class: 'text-muted text-sm mt-1' }, e.message),
            e.status === 402 ? h('a', { href: '#billing', class: 'btn btn-primary mt-3', style: 'display:inline-flex;' }, 'Upgrade plan') : null,
          ]));
        }
      }
    },

    async saved() {
      loading();
      const d = await API.get('/me/saved');
      const wrap = h('div', {}, [pageTitle('Saved Coupons', d.coupons.length + ' saved')]);
      if (!d.coupons.length) { wrap.appendChild(h('div', { class: 'card p-10 text-center text-muted' }, 'No saved coupons yet.')); setView(wrap); return; }
      const grid = h('div', { class: 'grid md:grid-cols-2 lg:grid-cols-3 gap-3 stagger' });
      d.coupons.forEach(c => grid.appendChild(couponCard(c, { saved: true, onRemove: async () => { await API.del('/me/saved/' + c.id); toast('Removed', 'ok'); route(); } })));
      wrap.appendChild(grid);
      setView(wrap);
    },

    async watchlist() {
      loading();
      const [d, m] = await Promise.all([API.get('/me/watchlist'), API.get('/merchants', { noAuth: true })]);
      const wrap = h('div', {}, [
        pageTitle('Watchlist', 'Get notified when new deals match.', h('button', { class: 'btn btn-primary btn-sm', onclick: () => addWatch(m.merchants) }, '+ Add watch')),
      ]);
      if (!d.watchlist.length) wrap.appendChild(h('div', { class: 'card p-10 text-center text-muted' }, 'Watch a merchant or keyword to track deals.'));
      else {
        const list = h('div', { class: 'card', style: 'overflow:hidden;' });
        d.watchlist.forEach(w => list.appendChild(h('div', { class: 'flex items-center justify-between p-4 border-b hairline', style: 'border-bottom-width:1px;' }, [
          h('div', { class: 'flex items-center gap-2' }, [h('span', { class: 'feature-ico', style: 'width:30px;height:30px;', html: icon(w.merchant_name ? 'store' : 'tag') }), h('span', { class: 'font-semibold' }, w.merchant_name || w.keyword)]),
          h('button', { class: 'btn btn-ghost btn-sm', onclick: async () => { await API.del('/me/watchlist/' + w.id); toast('Removed', 'ok'); route(); } }, 'Remove'),
        ])));
        wrap.appendChild(list);
      }
      setView(wrap);

      function addWatch(merchants) {
        const sel = h('select', { class: 'input' }, [h('option', { value: '' }, '— Select merchant —'), ...merchants.map(m => h('option', { value: m.id }, m.name))]);
        const kw = h('input', { class: 'input', placeholder: 'or a keyword (e.g. running shoes)' });
        const body = h('div', { class: 'grid gap-4' }, [
          h('div', {}, [h('label', { class: 'label' }, 'Merchant'), sel]),
          h('div', {}, [h('label', { class: 'label' }, 'Keyword'), kw]),
          h('button', { class: 'btn btn-primary', onclick: async () => { try { await API.post('/me/watchlist', { merchant_id: sel.value || null, keyword: kw.value || null }); toast('Added', 'ok'); mdl.close(); route(); } catch (e) { toast(e.message, 'err'); } } }, 'Add to watchlist'),
        ]);
        const mdl = modal('Add to watchlist', body);
      }
    },

    async alerts() {
      loading();
      const [d, m] = await Promise.all([API.get('/me/alerts'), API.get('/merchants', { noAuth: true })]);
      const wrap = h('div', {}, [
        pageTitle('Deal Alerts', 'Alerts trigger when matching deals appear.', h('button', { class: 'btn btn-primary btn-sm', onclick: () => addAlert(m.merchants) }, '+ New alert')),
      ]);
      if (!d.alerts.length) wrap.appendChild(h('div', { class: 'card p-10 text-center text-muted' }, 'No alerts configured.'));
      else {
        const list = h('div', { class: 'card', style: 'overflow:hidden;' });
        d.alerts.forEach(a => list.appendChild(h('div', { class: 'flex items-center justify-between p-4 border-b hairline', style: 'border-bottom-width:1px;' }, [
          h('div', {}, [h('div', { class: 'font-semibold' }, (a.merchant_name || a.keyword || 'Any')), h('div', { class: 'text-muted text-xs mt-1' }, (a.min_discount ? 'min ' + a.min_discount + '% · ' : '') + a.channel)]),
          h('button', { class: 'btn btn-ghost btn-sm', onclick: async () => { await API.del('/me/alerts/' + a.id); toast('Removed', 'ok'); route(); } }, 'Remove'),
        ])));
        wrap.appendChild(list);
      }
      setView(wrap);

      function addAlert(merchants) {
        const sel = h('select', { class: 'input' }, [h('option', { value: '' }, 'Any merchant'), ...merchants.map(m => h('option', { value: m.id }, m.name))]);
        const kw = h('input', { class: 'input', placeholder: 'keyword (optional)' });
        const min = h('input', { class: 'input', type: 'number', placeholder: 'min discount %' });
        const ch = h('select', { class: 'input' }, [h('option', { value: 'in_app' }, 'In-app'), h('option', { value: 'email' }, 'Email')]);
        const body = h('div', { class: 'grid gap-3' }, [
          h('div', {}, [h('label', { class: 'label' }, 'Merchant'), sel]),
          h('div', {}, [h('label', { class: 'label' }, 'Keyword'), kw]),
          h('div', { class: 'grid grid-cols-2 gap-3' }, [h('div', {}, [h('label', { class: 'label' }, 'Min discount'), min]), h('div', {}, [h('label', { class: 'label' }, 'Channel'), ch])]),
          h('button', { class: 'btn btn-primary', onclick: async () => { try { await API.post('/me/alerts', { merchant_id: sel.value || null, keyword: kw.value || null, min_discount: min.value || null, channel: ch.value }); toast('Alert created', 'ok'); mdl.close(); route(); } catch (e) { toast(e.message, 'err'); } } }, 'Create alert'),
        ]);
        const mdl = modal('New deal alert', body);
      }
    },

    async notifications() {
      loading();
      const d = await API.get('/me/notifications');
      const wrap = h('div', {}, [
        pageTitle('Notifications', d.unread + ' unread', d.notifications.length ? h('button', { class: 'btn btn-ghost btn-sm', onclick: async () => { await API.post('/me/notifications/read-all', {}); route(); refreshBell(); } }, 'Mark all read') : null),
      ]);
      if (!d.notifications.length) wrap.appendChild(h('div', { class: 'card p-10 text-center text-muted' }, 'You\'re all caught up.'));
      else {
        const list = h('div', { class: 'card', style: 'overflow:hidden;' });
        d.notifications.forEach(n => list.appendChild(h('div', { class: 'flex items-start gap-3 p-4 border-b hairline', style: 'border-bottom-width:1px;' + (n.read_at ? '' : 'background:var(--accent-soft);') }, [
          h('span', { class: 'feature-ico', style: 'width:32px;height:32px;', html: icon('bell') }),
          h('div', { style: 'flex:1;' }, [h('div', { class: 'font-semibold text-sm' }, n.title), n.body ? h('div', { class: 'text-muted text-sm mt-1' }, n.body) : null, h('div', { class: 'text-muted text-xs mt-1' }, fmt.ago(n.created_at))]),
          n.read_at ? null : h('button', { class: 'btn btn-ghost btn-sm', onclick: async () => { await API.post('/me/notifications/' + n.id + '/read', {}); route(); refreshBell(); } }, 'Read'),
        ])));
        wrap.appendChild(list);
      }
      setView(wrap);
    },

    async history() {
      loading();
      const d = await API.get('/me/search-history');
      const wrap = h('div', {}, [pageTitle('Search History', d.history.length + ' searches')]);
      const table = h('table', { class: 'table' }, [
        h('thead', {}, h('tr', {}, [h('th', {}, 'Query'), h('th', {}, 'Results'), h('th', {}, 'Speed'), h('th', {}, 'When')])),
        h('tbody', {}, d.history.map(s => h('tr', {}, [
          h('td', {}, h('a', { href: '#search?q=' + encodeURIComponent(s.query_raw), style: 'color:var(--accent);text-decoration:none;' }, s.query_raw)),
          h('td', {}, fmt.num(s.result_count)), h('td', {}, s.took_ms + 'ms'), h('td', { class: 'text-muted' }, fmt.ago(s.created_at)),
        ]))),
      ]);
      wrap.appendChild(d.history.length ? h('div', { class: 'card', style: 'overflow:auto;' }, table) : h('div', { class: 'card p-10 text-center text-muted' }, 'No history yet.'));
      setView(wrap);
    },

    async billing() {
      loading();
      const [sub, plans] = await Promise.all([API.get('/subscription'), API.get('/plans', { noAuth: true })]);
      const q = sub.quota || {};
      const cur = sub.subscription;
      const wrap = h('div', {}, [
        pageTitle('Billing & Plans', 'Manage your subscription.'),
        h('div', { class: 'card p-5 mb-6 flex items-center justify-between' }, [
          h('div', {}, [
            h('div', { class: 'text-muted text-xs uppercase' }, 'Current plan'),
            h('div', { class: 'h-display', style: 'font-size:1.5rem;' }, cur ? cur.plan_name : 'Free'),
            h('div', { class: 'text-muted text-sm mt-1' }, q.unlimited ? 'Unlimited searches' : (q.used + ' / ' + q.limit + ' searches per ' + q.window)),
          ]),
          cur && cur.gateway !== 'manual' ? h('button', { class: 'btn btn-danger', onclick: () => confirmDialog('Cancel at period end?', async () => { await API.post('/subscription/cancel', {}); toast('Will cancel at period end', 'ok'); route(); }) }, 'Cancel plan') : null,
        ]),
        h('div', { class: 'grid md:grid-cols-3 lg:grid-cols-5 gap-3' }, plans.plans.map(p => planCard(p, cur))),
      ]);
      setView(wrap);
    },

    async invoices() {
      loading();
      const d = await API.get('/me/invoices');
      const wrap = h('div', {}, [pageTitle('Invoices', 'Your billing history.')]);
      if (!d.invoices.length) wrap.appendChild(h('div', { class: 'card p-10 text-center text-muted' }, 'No invoices yet.'));
      else wrap.appendChild(h('div', { class: 'card', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
        h('thead', {}, h('tr', {}, [h('th', {}, 'Invoice'), h('th', {}, 'Amount'), h('th', {}, 'Status'), h('th', {}, 'Date'), h('th', {}, '')])),
        h('tbody', {}, d.invoices.map(i => h('tr', {}, [
          h('td', { class: 'mono' }, i.number),
          h('td', {}, fmt.money(i.amount_cents, i.currency)),
          h('td', {}, h('span', { class: 'badge ' + (i.status === 'paid' ? 'badge-green' : 'badge-muted') }, i.status)),
          h('td', { class: 'text-muted' }, fmt.date(i.issued_at)),
          h('td', {}, i.hosted_url ? h('a', { href: i.hosted_url, target: '_blank', class: 'text-accent', style: 'text-decoration:none;' }, 'View') : ''),
        ]))),
      ])));
      setView(wrap);
    },

    async referrals() {
      loading();
      const d = await API.get('/me/referrals');
      const wrap = h('div', {}, [
        pageTitle('Referral Center', 'Invite friends, grow together.'),
        h('div', { class: 'grid md:grid-cols-3 gap-4' }, [
          statCard('Your code', d.code, 'share & earn', 'gift'),
          statCard('Referred', fmt.num(d.count), 'friends joined', 'users'),
          h('div', { class: 'card p-5 md:col-span-1' }, [h('div', { class: 'text-muted text-xs uppercase' }, 'Your link'), h('div', { class: 'flex items-center gap-2 mt-2' }, [h('input', { class: 'input mono', style: 'font-size:0.8rem;', value: d.link, readonly: true }), h('button', { class: 'btn btn-soft btn-sm', onclick: () => copyToClipboard(d.link) }, 'Copy')])]),
        ]),
        d.referred.length ? h('div', { class: 'card mt-6', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
          h('thead', {}, h('tr', {}, [h('th', {}, 'Friend'), h('th', {}, 'Joined')])),
          h('tbody', {}, d.referred.map(r => h('tr', {}, [h('td', {}, r.name), h('td', { class: 'text-muted' }, fmt.date(r.created_at))]))),
        ])) : h('div', { class: 'card p-8 text-center text-muted mt-6' }, 'No referrals yet — share your link!'),
      ]);
      setView(wrap);
    },

    async profile() {
      loading();
      const d = await API.get('/me/profile');
      const p = d.profile;
      const name = h('input', { class: 'input', value: p.name });
      const cur = h('input', { class: 'input', type: 'password', placeholder: 'Current password' });
      const npw = h('input', { class: 'input', type: 'password', placeholder: 'New password (min 8)' });
      const wrap = h('div', {}, [
        pageTitle('Profile & Settings', 'Manage your account.'),
        h('div', { class: 'grid md:grid-cols-2 gap-5' }, [
          h('div', { class: 'card p-6' }, [
            h('h3', { class: 'font-bold mb-4' }, 'Profile'),
            h('label', { class: 'label' }, 'Name'), name,
            h('label', { class: 'label', style: 'margin-top:1rem;' }, 'Email'), h('input', { class: 'input', value: p.email, disabled: true }),
            h('button', { class: 'btn btn-primary mt-5', onclick: async () => { try { await API.put('/me/profile', { name: name.value }); me.name = name.value; API.store.user = me; paintUser(); toast('Saved', 'ok'); } catch (e) { toast(e.message, 'err'); } } }, 'Save profile'),
          ]),
          h('div', { class: 'card p-6' }, [
            h('h3', { class: 'font-bold mb-4' }, 'Change password'),
            h('label', { class: 'label' }, 'Current password'), cur,
            h('label', { class: 'label', style: 'margin-top:1rem;' }, 'New password'), npw,
            h('button', { class: 'btn btn-primary mt-5', onclick: async () => { try { await API.post('/me/change-password', { current_password: cur.value, password: npw.value }); toast('Password changed', 'ok'); cur.value = npw.value = ''; } catch (e) { toast(e.message, 'err'); } } }, 'Update password'),
          ]),
        ]),
      ]);
      setView(wrap);
    },
  };

  function planCard(p, cur) {
    const isCurrent = cur && cur.plan_id === p.id;
    return h('div', { class: 'card p-5 flex flex-col', style: isCurrent ? 'border-color:var(--accent);' : '' }, [
      h('h3', { class: 'font-bold' }, p.name),
      h('div', { class: 'h-display mt-1', style: 'font-size:1.5rem;' }, p.price_cents === 0 ? 'Free' : fmt.money(p.price_cents, p.currency)),
      h('div', { class: 'text-muted text-xs' }, p.price_cents ? 'per ' + p.interval : 'forever'),
      h('p', { class: 'text-muted text-sm mt-2', style: 'flex:1;' }, p.description || ''),
      isCurrent
        ? h('button', { class: 'btn btn-ghost mt-4', disabled: true }, 'Current plan')
        : h('div', { class: 'grid grid-cols-2 gap-2 mt-4' }, [
            h('button', { class: 'btn btn-primary btn-sm', onclick: () => checkout(p.id, 'stripe') }, 'Stripe'),
            h('button', { class: 'btn btn-ghost btn-sm', onclick: () => checkout(p.id, 'razorpay') }, 'Razorpay'),
          ]),
    ]);
  }

  async function checkout(planId, gateway) {
    try {
      const r = await API.post('/subscription/checkout', { plan_id: planId, gateway });
      if (r.redirect_url && r.redirect_url !== location.href) { toast('Redirecting to ' + gateway + '…', 'info'); location.href = r.redirect_url; }
      else { toast('Plan updated', 'ok'); route(); }
    } catch (e) { toast(e.message, 'err'); }
  }

  async function refreshBell() {
    try { const d = await API.get('/me/notifications'); el('#bell-dot').classList.toggle('hide', !d.unread); } catch (e) {}
  }

  // ---- Router ----
  function parseHash() {
    const raw = (location.hash || '#dashboard').slice(1);
    const [route, qs] = raw.split('?');
    return { route: route || 'dashboard', params: new URLSearchParams(qs || '') };
  }
  async function route() {
    const { route, params } = parseHash();
    setActive(route);
    const fn = Views[route] || Views.dashboard;
    try { await fn(params); } catch (e) {
      if (e.status === 401) { await API.logout(); location.href = '/login'; return; }
      setView(h('div', { class: 'card p-8 text-center' }, [h('p', { class: 'font-semibold' }, 'Failed to load'), h('p', { class: 'text-muted text-sm mt-1' }, e.message)]));
    }
  }
  window.addEventListener('hashchange', route);

  // ---- Boot ----
  (async function boot() {
    try { me = await API.me(); API.store.user = me; paintUser(); el('#user-plan').textContent = (me.role_name || 'Member'); }
    catch (e) { await API.logout(); location.href = '/login'; return; }
    if (!location.hash) location.hash = 'dashboard';
    route();
    refreshBell();
  })();
})();
