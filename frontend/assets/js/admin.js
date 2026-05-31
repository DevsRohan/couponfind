/* =====================================================================
   CouponFind — Super Admin Mission Control (hash-routed SPA)
   ===================================================================== */
(function () {
  const { el, els, h, esc, toast, fmt, icon, modal, confirmDialog } = UI;

  if (!UI.requireAuthRedirect()) return;

  const NAV = [
    { id: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
    { id: 'users', label: 'Users', icon: 'users' },
    { id: 'plans', label: 'Plans', icon: 'card' },
    { id: 'subscriptions', label: 'Subscriptions', icon: 'list' },
    { id: 'revenue', label: 'Revenue', icon: 'chart' },
    { id: 'coupons', label: 'Coupons', icon: 'tag' },
    { id: 'merchants', label: 'Merchants', icon: 'store' },
    { id: 'sources', label: 'Coupon Sources', icon: 'spider' },
    { id: 'search', label: 'Search Analytics', icon: 'search' },
    { id: 'ai', label: 'AI Control Center', icon: 'cpu' },
    { id: 'engine', label: 'Engine Control', icon: 'spider' },
    { id: 'flags', label: 'Feature Flags', icon: 'flag' },
    { id: 'logs', label: 'Logs & Audit', icon: 'activity' },
    { id: 'health', label: 'System Health', icon: 'shield' },
    { id: 'settings', label: 'Settings', icon: 'settings' },
  ];
  const nav = el('#nav');
  NAV.forEach(n => nav.appendChild(h('a', { class: 'nav-item', 'data-route': n.id, href: '#' + n.id }, [h('span', { html: icon(n.icon) }), h('span', {}, n.label)])));
  const setActive = (r) => els('.nav-item', nav).forEach(a => a.classList.toggle('active', a.getAttribute('data-route') === r));

  el('#logout').addEventListener('click', async () => { await API.logout(); location.href = '/login'; });

  UI.setupCommandPalette([
    ...NAV.map(n => ({ label: n.label, icon: n.icon, action: () => location.hash = n.id })),
    { label: 'Reindex Meilisearch', icon: 'box', action: () => API.post('/admin/engine/reindex', {}).then(() => toast('Reindex queued', 'ok')).catch(e => toast(e.message, 'err')) },
    { label: 'Force discovery crawl', icon: 'spider', action: () => API.post('/admin/engine/dispatch', { type: 'discover' }).then(() => toast('Discovery queued', 'ok')).catch(e => toast(e.message, 'err')) },
  ]);

  const view = el('#view');
  const setView = (n) => { view.innerHTML = ''; view.appendChild(n); };
  const loading = () => setView(UI.skeletonList(6, '64px'));
  const title = (t, s, a) => h('div', { class: 'flex items-end justify-between mb-6 fade-up' }, [h('div', {}, [h('h1', { class: 'h-display', style: 'font-size:1.6rem;' }, t), s ? h('p', { class: 'text-muted text-sm mt-1' }, s) : null]), a || h('div')]);
  const stat = (label, val, sub, ic) => h('div', { class: 'card p-5' }, [
    h('div', { class: 'flex items-center justify-between' }, [h('span', { class: 'text-muted text-xs uppercase tracking-wide' }, label), h('span', { class: 'feature-ico', style: 'width:30px;height:30px;', html: icon(ic) })]),
    h('div', { class: 'h-display mt-3', style: 'font-size:1.7rem;' }, val), sub ? h('div', { class: 'text-muted text-xs mt-1' }, sub) : null,
  ]);

  // ---- Tiny SVG bar chart ----
  function barChart(data, xKey, yKey, opts = {}) {
    const w = 640, hgt = 180, pad = 24;
    if (!data || !data.length) return h('div', { class: 'text-muted text-sm p-4' }, 'No data');
    const max = Math.max(...data.map(d => Number(d[yKey]) || 0), 1);
    const bw = (w - pad * 2) / data.length;
    const bars = data.map((d, i) => {
      const bh = ((Number(d[yKey]) || 0) / max) * (hgt - pad * 2);
      const x = pad + i * bw, y = hgt - pad - bh;
      return `<rect x="${x + 2}" y="${y}" width="${Math.max(2, bw - 6)}" height="${bh}" rx="3" fill="var(--accent)" opacity="0.85"><title>${esc(d[xKey])}: ${esc(d[yKey])}</title></rect>`;
    }).join('');
    return h('div', { class: 'card p-4', style: 'overflow:auto;' }, h('div', { html: `<svg viewBox="0 0 ${w} ${hgt}" style="width:100%;height:${hgt}px;">${bars}</svg>` }));
  }

  const Views = {
    async dashboard() {
      loading();
      const d = await API.get('/admin/dashboard');
      setView(h('div', {}, [
        title('Dashboard', 'Real-time platform overview'),
        h('div', { class: 'grid md:grid-cols-4 gap-4 stagger' }, [
          stat('Total users', fmt.num(d.users_total), fmt.num(d.users_active_24h) + ' active 24h', 'users'),
          stat('MRR', fmt.moneyVal(d.mrr), fmt.num(d.subscriptions) + ' active subs', 'chart'),
          stat('Active coupons', fmt.num(d.coupons_active), 'of ' + fmt.num(d.coupons_total) + ' total', 'tag'),
          stat('Searches 24h', fmt.num(d.searches_24h), d.avg_latency_ms + 'ms avg', 'search'),
        ]),
        h('div', { class: 'grid lg:grid-cols-2 gap-5 mt-6' }, [
          h('div', {}, [h('h3', { class: 'font-bold mb-3' }, 'Search volume (14d)'), barChart(d.search_volume, 'day', 'hits')]),
          (function () {
            const box = h('div', {}, [h('h3', { class: 'font-bold mb-3' }, 'Top queries (30d)')]);
            const c = h('div', { class: 'card', style: 'overflow:hidden;' });
            (d.top_queries || []).forEach(q => c.appendChild(h('div', { class: 'flex items-center justify-between p-3 border-b hairline', style: 'border-bottom-width:1px;' }, [h('span', { class: 'text-sm' }, q.term || '—'), h('span', { class: 'badge badge-accent' }, fmt.num(q.hits))])));
            if (!d.top_queries || !d.top_queries.length) c.appendChild(h('div', { class: 'p-4 text-muted text-sm' }, 'No searches yet.'));
            box.appendChild(c); return box;
          })(),
        ]),
      ]));
    },

    async users() {
      loading();
      const d = await API.get('/admin/users');
      const wrap = h('div', {}, [title('Users', fmt.num(d.total) + ' total')]);
      const table = h('table', { class: 'table' }, [
        h('thead', {}, h('tr', {}, ['User', 'Role', 'Status', 'Joined', 'Actions'].map(x => h('th', {}, x)))),
        h('tbody', {}, d.data.map(u => h('tr', {}, [
          h('td', {}, [h('div', { class: 'font-semibold' }, u.name), h('div', { class: 'text-muted text-xs' }, u.email)]),
          h('td', {}, h('span', { class: 'badge badge-blue' }, u.role_name)),
          h('td', {}, h('span', { class: 'badge ' + (u.status === 'active' ? 'badge-green' : 'badge-red') }, u.status)),
          h('td', { class: 'text-muted' }, fmt.date(u.created_at)),
          h('td', {}, h('div', { class: 'flex gap-2' }, [
            h('button', { class: 'btn btn-ghost btn-sm', onclick: () => setStatus(u) }, u.status === 'active' ? 'Suspend' : 'Activate'),
            h('button', { class: 'btn btn-soft btn-sm', onclick: () => assignPlan(u) }, 'Assign plan'),
          ])),
        ]))),
      ]);
      wrap.appendChild(h('div', { class: 'card', style: 'overflow:auto;' }, table));
      setView(wrap);

      async function setStatus(u) {
        const status = u.status === 'active' ? 'suspended' : 'active';
        try { await API.post('/admin/users/' + u.id + '/status', { status }); toast('Updated', 'ok'); route(); } catch (e) { toast(e.message, 'err'); }
      }
      async function assignPlan(u) {
        const plans = (await API.get('/admin/plans')).plans;
        const sel = h('select', { class: 'input' }, plans.map(p => h('option', { value: p.id }, p.name)));
        const life = h('input', { type: 'checkbox' });
        const lim = h('input', { class: 'input', type: 'number', placeholder: 'override limit (optional)' });
        const win = h('select', { class: 'input' }, [h('option', { value: 'day' }, 'per day'), h('option', { value: 'month' }, 'per month')]);
        const body = h('div', { class: 'grid gap-3' }, [
          h('div', {}, [h('label', { class: 'label' }, 'Plan'), sel]),
          h('div', { class: 'grid grid-cols-2 gap-3' }, [h('div', {}, [h('label', { class: 'label' }, 'Override limit'), lim]), h('div', {}, [h('label', { class: 'label' }, 'Window'), win])]),
          h('label', { class: 'flex items-center gap-2 text-sm text-muted' }, [life, ' Lifetime access']),
          h('button', { class: 'btn btn-primary', onclick: async () => { try { await API.post('/admin/subscriptions/assign', { user_id: u.id, plan_id: sel.value, lifetime: life.checked, override_search_limit: lim.value || null, override_search_window: win.value }); toast('Plan assigned', 'ok'); m.close(); } catch (e) { toast(e.message, 'err'); } } }, 'Assign'),
        ]);
        const m = modal('Assign plan to ' + u.name, body);
      }
    },

    async plans() {
      loading();
      const d = await API.get('/admin/plans');
      const wrap = h('div', {}, [title('Plans', 'Create, edit, and delete subscription plans', h('button', { class: 'btn btn-primary btn-sm', onclick: () => editPlan() }, '+ New plan'))]);
      const grid = h('div', { class: 'grid md:grid-cols-3 gap-4' });
      d.plans.forEach(p => grid.appendChild(h('div', { class: 'card p-5' }, [
        h('div', { class: 'flex items-center justify-between' }, [h('h3', { class: 'font-bold' }, p.name), h('span', { class: 'badge ' + (p.is_active ? 'badge-green' : 'badge-muted') }, p.is_active ? 'active' : 'off')]),
        h('div', { class: 'h-display mt-1', style: 'font-size:1.4rem;' }, p.price_cents === 0 ? 'Free' : fmt.money(p.price_cents, p.currency)),
        h('div', { class: 'text-muted text-xs' }, (p.search_limit === null ? '∞' : p.search_limit) + ' / ' + p.search_window + ' · ' + p.interval),
        h('div', { class: 'flex gap-2 mt-4' }, [
          h('button', { class: 'btn btn-ghost btn-sm', onclick: () => editPlan(p) }, 'Edit'),
          h('button', { class: 'btn btn-danger btn-sm', onclick: () => confirmDialog('Delete ' + p.name + '?', async () => { await API.del('/admin/plans/' + p.id); toast('Deleted', 'ok'); route(); }) }, 'Delete'),
        ]),
      ])));
      wrap.appendChild(grid);
      setView(wrap);

      function editPlan(p) {
        const f = {};
        const field = (k, label, val, type = 'text') => { const i = h('input', { class: 'input', type, value: val ?? '' }); f[k] = i; return h('div', {}, [h('label', { class: 'label' }, label), i]); };
        const interval = h('select', { class: 'input' }, ['day', 'month', 'year', 'lifetime'].map(x => h('option', { value: x, selected: p && p.interval === x ? 'selected' : null }, x))); f.interval = interval;
        const win = h('select', { class: 'input' }, ['day', 'month'].map(x => h('option', { value: x, selected: p && p.search_window === x ? 'selected' : null }, x))); f.search_window = win;
        const body = h('div', { class: 'grid gap-3' }, [
          field('slug', 'Slug', p?.slug), field('name', 'Name', p?.name),
          h('div', { class: 'grid grid-cols-2 gap-3' }, [field('price_cents', 'Price (cents)', p?.price_cents ?? 0, 'number'), h('div', {}, [h('label', { class: 'label' }, 'Interval'), interval])]),
          h('div', { class: 'grid grid-cols-2 gap-3' }, [field('search_limit', 'Search limit', p?.search_limit ?? ''), h('div', {}, [h('label', { class: 'label' }, 'Window'), win])]),
          field('description', 'Description', p?.description),
          field('stripe_price_id', 'Stripe price ID', p?.stripe_price_id),
          field('razorpay_plan_id', 'Razorpay plan ID', p?.razorpay_plan_id),
          h('button', { class: 'btn btn-primary', onclick: save }, p ? 'Save plan' : 'Create plan'),
        ]);
        const m = modal(p ? 'Edit ' + p.name : 'New plan', body);
        async function save() {
          const payload = { slug: f.slug.value, name: f.name.value, price_cents: Number(f.price_cents.value) || 0, interval: f.interval.value, search_limit: f.search_limit.value === '' ? null : Number(f.search_limit.value), search_window: f.search_window.value, description: f.description.value, stripe_price_id: f.stripe_price_id.value, razorpay_plan_id: f.razorpay_plan_id.value };
          try { p ? await API.put('/admin/plans/' + p.id, payload) : await API.post('/admin/plans', payload); toast('Saved', 'ok'); m.close(); route(); } catch (e) { toast(e.message, 'err'); }
        }
      }
    },

    async subscriptions() {
      loading();
      const d = await API.get('/admin/subscriptions');
      setView(h('div', {}, [title('Subscriptions', d.subscriptions.length + ' recent'),
        h('div', { class: 'card', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
          h('thead', {}, h('tr', {}, ['User', 'Plan', 'Gateway', 'Status', 'Renews', 'Override'].map(x => h('th', {}, x)))),
          h('tbody', {}, d.subscriptions.map(s => h('tr', {}, [
            h('td', {}, [h('div', { class: 'font-semibold' }, s.name), h('div', { class: 'text-muted text-xs' }, s.email)]),
            h('td', {}, s.plan_name), h('td', {}, h('span', { class: 'badge badge-muted' }, s.gateway)),
            h('td', {}, h('span', { class: 'badge ' + (s.status === 'active' ? 'badge-green' : 'badge-red') }, s.status)),
            h('td', { class: 'text-muted' }, s.is_lifetime ? 'Lifetime' : fmt.date(s.current_period_end)),
            h('td', {}, s.override_search_limit ? (s.override_search_limit + '/' + (s.override_search_window || 'day')) : '—'),
          ]))),
        ])),
      ]));
    },

    async revenue() {
      loading();
      const d = await API.get('/admin/analytics/revenue');
      setView(h('div', {}, [
        title('Revenue', 'Subscriptions & payments'),
        h('div', { class: 'grid md:grid-cols-3 gap-4 mb-6' }, [
          stat('MRR', fmt.moneyVal(d.mrr), 'monthly recurring', 'chart'),
          stat('Failed (30d)', fmt.num(d.failed_30d), 'payment failures', 'card'),
          stat('Plans', fmt.num((d.by_plan || []).length), 'with subscribers', 'list'),
        ]),
        h('h3', { class: 'font-bold mb-3' }, 'Daily revenue (30d)'),
        barChart((d.by_day || []).map(x => ({ day: x.day, revenue: x.revenue })), 'day', 'revenue'),
        h('h3', { class: 'font-bold mb-3 mt-6' }, 'Subscribers by plan'),
        h('div', { class: 'card', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
          h('thead', {}, h('tr', {}, [h('th', {}, 'Plan'), h('th', {}, 'Subscribers')])),
          h('tbody', {}, (d.by_plan || []).map(p => h('tr', {}, [h('td', {}, p.name), h('td', {}, fmt.num(p.subscribers))]))),
        ])),
      ]));
    },

    async coupons() {
      loading();
      const d = await API.get('/admin/coupons');
      const wrap = h('div', {}, [title('Coupons', fmt.num(d.total) + ' total')]);
      wrap.appendChild(h('div', { class: 'card', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
        h('thead', {}, h('tr', {}, ['Title', 'Merchant', 'Code', 'Discount', 'Status', 'Actions'].map(x => h('th', {}, x)))),
        h('tbody', {}, d.data.map(c => h('tr', {}, [
          h('td', { style: 'max-width:260px;' }, c.title),
          h('td', {}, c.merchant_name),
          h('td', { class: 'mono' }, c.code || '—'),
          h('td', {}, h('span', { class: 'badge badge-accent' }, fmt.discount(c))),
          h('td', {}, h('span', { class: 'badge ' + (c.status === 'active' ? 'badge-green' : 'badge-muted') }, c.status)),
          h('td', {}, h('div', { class: 'flex gap-2' }, [
            h('button', { class: 'btn btn-ghost btn-sm', onclick: async () => { await API.post('/admin/coupons/' + c.id + '/status', { status: c.status === 'active' ? 'unverified' : 'active' }); toast('Updated', 'ok'); route(); } }, c.status === 'active' ? 'Unverify' : 'Activate'),
            h('button', { class: 'btn btn-danger btn-sm', onclick: async () => { await API.post('/admin/coupons/' + c.id + '/expire', {}); toast('Expired', 'ok'); route(); } }, 'Expire'),
          ])),
        ]))),
      ])));
      setView(wrap);
    },

    async merchants() {
      loading();
      const d = await API.get('/admin/merchants');
      const wrap = h('div', {}, [title('Merchants', d.merchants.length + ' total', h('button', { class: 'btn btn-primary btn-sm', onclick: () => editMerchant() }, '+ New merchant'))]);
      const grid = h('div', { class: 'grid md:grid-cols-3 gap-4' });
      d.merchants.forEach(m => grid.appendChild(h('div', { class: 'card p-5' }, [
        h('div', { class: 'flex items-center justify-between' }, [h('h3', { class: 'font-bold' }, m.name), h('span', { class: 'badge ' + (m.is_active ? 'badge-green' : 'badge-muted') }, m.is_active ? 'active' : 'off')]),
        h('div', { class: 'text-muted text-xs mt-1' }, m.domain || m.website_url || ''),
        h('div', { class: 'text-muted text-xs mt-1' }, (m.category || 'uncategorized') + ' · pop ' + fmt.num(m.popularity)),
        h('div', { class: 'flex gap-2 mt-4' }, [
          h('button', { class: 'btn btn-ghost btn-sm', onclick: () => editMerchant(m) }, 'Edit'),
          h('button', { class: 'btn btn-soft btn-sm', onclick: () => API.post('/admin/engine/dispatch', { type: 'crawl', payload: { merchant_id: m.id } }).then(() => toast('Crawl queued', 'ok')).catch(e => toast(e.message, 'err')) }, 'Force crawl'),
          h('button', { class: 'btn btn-danger btn-sm', onclick: () => confirmDialog('Delete ' + m.name + '?', async () => { await API.del('/admin/merchants/' + m.id); toast('Deleted', 'ok'); route(); }) }, 'Delete'),
        ]),
      ])));
      wrap.appendChild(grid);
      setView(wrap);

      function editMerchant(m) {
        const f = {};
        const field = (k, label, val) => { const i = h('input', { class: 'input', value: val ?? '' }); f[k] = i; return h('div', {}, [h('label', { class: 'label' }, label), i]); };
        const body = h('div', { class: 'grid gap-3' }, [
          field('slug', 'Slug', m?.slug), field('name', 'Name', m?.name),
          field('domain', 'Domain', m?.domain), field('website_url', 'Website URL', m?.website_url),
          field('category', 'Category', m?.category),
          h('button', { class: 'btn btn-primary', onclick: async () => { const payload = { slug: f.slug.value, name: f.name.value, domain: f.domain.value, website_url: f.website_url.value, category: f.category.value, is_active: 1 }; try { m ? await API.put('/admin/merchants/' + m.id, payload) : await API.post('/admin/merchants', payload); toast('Saved', 'ok'); mm.close(); route(); } catch (e) { toast(e.message, 'err'); } } }, 'Save'),
        ]);
        const mm = modal(m ? 'Edit ' + m.name : 'New merchant', body);
      }
    },

    async sources() {
      loading();
      const d = await API.get('/admin/sources');
      const wrap = h('div', {}, [title('Coupon Sources', 'Where the engine discovers coupons', h('button', { class: 'btn btn-primary btn-sm', onclick: addSource }, '+ Add source'))]);
      wrap.appendChild(h('div', { class: 'card', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
        h('thead', {}, h('tr', {}, ['Type', 'URL', 'Merchant', 'Last crawl', 'Status', ''].map(x => h('th', {}, x)))),
        h('tbody', {}, d.sources.map(s => h('tr', {}, [
          h('td', {}, h('span', { class: 'badge badge-blue' }, s.type)),
          h('td', { style: 'max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, s.url),
          h('td', {}, s.merchant_name || '—'),
          h('td', { class: 'text-muted' }, s.last_crawled_at ? fmt.ago(s.last_crawled_at) : 'never'),
          h('td', {}, h('span', { class: 'badge ' + (s.is_active ? 'badge-green' : 'badge-muted') }, s.is_active ? 'active' : 'off')),
          h('td', {}, h('button', { class: 'btn btn-danger btn-sm', onclick: async () => { await API.del('/admin/sources/' + s.id); toast('Removed', 'ok'); route(); } }, 'Delete')),
        ]))),
      ])));
      setView(wrap);

      function addSource() {
        const type = h('select', { class: 'input' }, ['offer_page', 'promo_page', 'rss', 'sitemap', 'newsletter', 'user_submission'].map(x => h('option', { value: x }, x)));
        const url = h('input', { class: 'input', placeholder: 'https://merchant.com/deals' });
        const body = h('div', { class: 'grid gap-3' }, [
          h('div', {}, [h('label', { class: 'label' }, 'Type'), type]),
          h('div', {}, [h('label', { class: 'label' }, 'URL'), url]),
          h('button', { class: 'btn btn-primary', onclick: async () => { try { await API.post('/admin/sources', { type: type.value, url: url.value }); toast('Added', 'ok'); m.close(); route(); } catch (e) { toast(e.message, 'err'); } } }, 'Add source'),
        ]);
        const m = modal('Add coupon source', body);
      }
    },

    async search() {
      loading();
      const d = await API.get('/admin/analytics/search');
      setView(h('div', {}, [
        title('Search Analytics', 'Query volume & quality'),
        h('div', { class: 'grid md:grid-cols-3 gap-4 mb-6' }, [
          stat('Avg latency', d.avg_latency + 'ms', 'last 30 days', 'activity'),
          stat('Zero-result', fmt.num(d.zero_result), 'searches (30d)', 'search'),
          stat('Top terms', fmt.num((d.top_queries || []).length), 'tracked', 'tag'),
        ]),
        h('h3', { class: 'font-bold mb-3' }, 'Daily volume (30d)'),
        barChart(d.daily_volume, 'day', 'hits'),
        h('h3', { class: 'font-bold mb-3 mt-6' }, 'Top queries'),
        h('div', { class: 'card', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
          h('thead', {}, h('tr', {}, [h('th', {}, 'Term'), h('th', {}, 'Hits')])),
          h('tbody', {}, (d.top_queries || []).map(q => h('tr', {}, [h('td', {}, q.term || '—'), h('td', {}, fmt.num(q.hits))]))),
        ])),
      ]));
    },

    async ai() {
      loading();
      const d = await API.get('/admin/ai/providers');
      const wrap = h('div', {}, [title('AI Control Center', 'Provider fallback chain: Groq → Gemini → OpenAI')]);
      const grid = h('div', { class: 'grid md:grid-cols-3 gap-4' });
      d.providers.forEach(p => grid.appendChild(h('div', { class: 'card p-5' }, [
        h('div', { class: 'flex items-center justify-between' }, [h('h3', { class: 'font-bold' }, p.name), h('span', { class: 'badge ' + (p.is_enabled ? 'badge-green' : 'badge-muted') }, p.is_enabled ? 'enabled' : 'disabled')]),
        h('div', { class: 'text-muted text-xs mt-1' }, 'priority ' + p.priority + ' · ' + (p.model || 'default model')),
        p.last_error ? h('div', { class: 'badge badge-red mt-2' }, 'err: ' + p.last_error.slice(0, 40)) : (p.last_ok_at ? h('div', { class: 'text-muted text-xs mt-2' }, 'last ok ' + fmt.ago(p.last_ok_at)) : null),
        h('div', { class: 'flex gap-2 mt-4' }, [
          h('button', { class: 'btn btn-ghost btn-sm', onclick: async () => { await API.put('/admin/ai/providers/' + p.id, { is_enabled: !p.is_enabled }); toast('Updated', 'ok'); route(); } }, p.is_enabled ? 'Disable' : 'Enable'),
          h('button', { class: 'btn btn-soft btn-sm', onclick: () => editPriority(p) }, 'Set priority'),
        ]),
      ])));
      wrap.appendChild(grid);
      setView(wrap);
      function editPriority(p) {
        const pr = h('input', { class: 'input', type: 'number', value: p.priority });
        const md = h('input', { class: 'input', value: p.model || '' });
        const body = h('div', { class: 'grid gap-3' }, [h('div', {}, [h('label', { class: 'label' }, 'Priority (lower = first)'), pr]), h('div', {}, [h('label', { class: 'label' }, 'Model'), md]), h('button', { class: 'btn btn-primary', onclick: async () => { await API.put('/admin/ai/providers/' + p.id, { is_enabled: p.is_enabled, priority: Number(pr.value), model: md.value }); toast('Saved', 'ok'); m.close(); route(); } }, 'Save')]);
        const m = modal('Configure ' + p.name, body);
      }
    },

    async engine() {
      loading();
      const d = await API.get('/admin/engine/jobs');
      const actions = h('div', { class: 'flex flex-wrap gap-2' }, [
        ['discover', 'Discover'], ['crawl', 'Crawl'], ['validate', 'Validate'], ['score', 'Score'], ['sync', 'Sync index'],
      ].map(([t, label]) => h('button', { class: 'btn btn-soft btn-sm', onclick: () => API.post('/admin/engine/dispatch', { type: t }).then(() => { toast(label + ' queued', 'ok'); route(); }).catch(e => toast(e.message, 'err')) }, label)));
      const wrap = h('div', {}, [
        title('Engine Control', 'Crawler · Validation · Indexer', h('button', { class: 'btn btn-primary btn-sm', onclick: () => API.post('/admin/engine/reindex', {}).then(() => toast('Reindex queued', 'ok')).catch(e => toast(e.message, 'err')) }, 'Reindex Meilisearch')),
        h('div', { class: 'card p-5 mb-5' }, [h('h3', { class: 'font-bold mb-3' }, 'Dispatch jobs'), actions]),
        h('h3', { class: 'font-bold mb-3' }, 'Recent jobs'),
        h('div', { class: 'card', style: 'overflow:auto;' }, h('table', { class: 'table' }, [
          h('thead', {}, h('tr', {}, ['#', 'Type', 'Status', 'Attempts', 'Created'].map(x => h('th', {}, x)))),
          h('tbody', {}, d.jobs.map(j => h('tr', {}, [
            h('td', { class: 'mono' }, '#' + j.id), h('td', {}, h('span', { class: 'badge badge-blue' }, j.type)),
            h('td', {}, h('span', { class: 'badge ' + ({ done: 'badge-green', failed: 'badge-red', running: 'badge-accent' }[j.status] || 'badge-muted') }, j.status)),
            h('td', {}, j.attempts), h('td', { class: 'text-muted' }, fmt.ago(j.created_at)),
          ]))),
        ])),
      ]);
      setView(wrap);
    },

    async flags() {
      loading();
      const d = await API.get('/admin/flags');
      const wrap = h('div', {}, [title('Feature Flags', 'Toggle platform features')]);
      const list = h('div', { class: 'card', style: 'overflow:hidden;' });
      d.flags.forEach(f => list.appendChild(h('div', { class: 'flex items-center justify-between p-4 border-b hairline', style: 'border-bottom-width:1px;' }, [
        h('div', {}, [h('div', { class: 'font-semibold' }, f.name), h('div', { class: 'text-muted text-xs mt-1' }, f.description || f.key)]),
        h('button', { class: 'btn ' + (f.is_enabled ? 'btn-primary' : 'btn-ghost') + ' btn-sm', onclick: async () => { await API.put('/admin/flags/' + f.key, { is_enabled: !f.is_enabled }); toast('Updated', 'ok'); route(); } }, f.is_enabled ? 'On' : 'Off'),
      ])));
      wrap.appendChild(list);
      setView(wrap);
    },

    async logs() {
      loading();
      const [audit, api] = await Promise.all([API.get('/admin/logs/audit'), API.get('/admin/logs/api')]);
      setView(h('div', {}, [
        title('Logs & Audit', 'Security and request trails'),
        h('h3', { class: 'font-bold mb-3' }, 'Audit log'),
        h('div', { class: 'card mb-6', style: 'overflow:auto;max-height:340px;' }, h('table', { class: 'table' }, [
          h('thead', {}, h('tr', {}, ['Action', 'Actor', 'Entity', 'When'].map(x => h('th', {}, x)))),
          h('tbody', {}, audit.logs.map(l => h('tr', {}, [h('td', {}, h('span', { class: 'badge badge-muted' }, l.action)), h('td', {}, l.actor_email || 'system'), h('td', { class: 'text-muted' }, (l.entity_type || '') + ' ' + (l.entity_id || '')), h('td', { class: 'text-muted' }, fmt.ago(l.created_at))]))),
        ])),
        h('h3', { class: 'font-bold mb-3' }, 'API log'),
        h('div', { class: 'card', style: 'overflow:auto;max-height:340px;' }, h('table', { class: 'table' }, [
          h('thead', {}, h('tr', {}, ['Method', 'Path', 'Status', 'Time', 'When'].map(x => h('th', {}, x)))),
          h('tbody', {}, api.logs.map(l => h('tr', {}, [h('td', {}, h('span', { class: 'badge badge-blue' }, l.method)), h('td', { class: 'mono', style: 'font-size:0.8rem;' }, l.path), h('td', {}, h('span', { class: 'badge ' + (l.status_code < 400 ? 'badge-green' : 'badge-red') }, l.status_code)), h('td', {}, l.took_ms + 'ms'), h('td', { class: 'text-muted' }, fmt.ago(l.created_at))]))),
        ])),
      ]));
    },

    async health() {
      loading();
      const d = await API.get('/admin/health');
      const svc = (label, ok) => h('div', { class: 'card p-5 flex items-center justify-between' }, [h('span', { class: 'font-semibold' }, label), h('span', { class: 'flex items-center gap-2' }, [h('span', { class: 'dot ' + (ok ? 'ok' : 'bad') }), h('span', { class: 'text-sm ' + (ok ? 'text-accent' : ''), style: ok ? '' : 'color:var(--red);' }, ok ? 'healthy' : 'down')])]);
      setView(h('div', {}, [
        title('System Health', 'Live infrastructure status'),
        h('div', { class: 'grid md:grid-cols-3 gap-4' }, [svc('MySQL', d.database), svc('Redis', d.redis), svc('Meilisearch', d.meilisearch)]),
        h('div', { class: 'grid md:grid-cols-3 gap-4 mt-4' }, [
          stat('Queued jobs', fmt.num(d.queued_jobs), 'engine queue', 'list'),
          stat('Failed jobs', fmt.num(d.failed_jobs), 'need attention', 'flag'),
          stat('PHP', d.php_version, 'runtime', 'cpu'),
        ]),
      ]));
    },

    async settings() {
      loading();
      const d = await API.get('/admin/settings');
      const wrap = h('div', {}, [title('Settings', 'Platform configuration')]);
      const list = h('div', { class: 'card p-5 grid gap-4' });
      d.settings.forEach(s => {
        const input = h('input', { class: 'input', value: s.value ?? '' });
        list.appendChild(h('div', { class: 'flex items-end gap-3' }, [
          h('div', { style: 'flex:1;' }, [h('label', { class: 'label' }, s.key), input]),
          h('button', { class: 'btn btn-soft btn-sm', onclick: async () => { await API.put('/admin/settings/' + encodeURIComponent(s.key), { value: input.value }); toast('Saved', 'ok'); } }, 'Save'),
        ]));
      });
      wrap.appendChild(list);
      setView(wrap);
    },
  };

  function parseHash() { const raw = (location.hash || '#dashboard').slice(1); const [r, qs] = raw.split('?'); return { route: r || 'dashboard', params: new URLSearchParams(qs || '') }; }
  async function route() {
    const { route: r, params } = parseHash();
    setActive(r);
    try { await (Views[r] || Views.dashboard)(params); }
    catch (e) {
      if (e.status === 401) { await API.logout(); location.href = '/login'; return; }
      if (e.status === 403) { setView(h('div', { class: 'card p-10 text-center' }, [h('h2', { class: 'h-display' }, 'Admin access required'), h('p', { class: 'text-muted mt-2' }, 'Your account is not an administrator.'), h('a', { href: '/app', class: 'btn btn-primary mt-4', style: 'display:inline-flex;' }, 'Go to app')])); return; }
      setView(h('div', { class: 'card p-8 text-center' }, [h('p', { class: 'font-semibold' }, 'Failed to load'), h('p', { class: 'text-muted text-sm mt-1' }, e.message)]));
    }
  }
  window.addEventListener('hashchange', route);

  (async function boot() {
    let me;
    try { me = await API.me(); } catch (e) { await API.logout(); location.href = '/login'; return; }
    if (!me.is_admin) { location.href = '/app'; return; }
    el('#user-name').textContent = me.name;
    el('#avatar').textContent = (me.name || 'A')[0].toUpperCase();
    // Health indicator
    try { const hd = await API.get('/admin/health'); const ok = hd.database && hd.redis; el('#health-dot').className = 'dot ' + (ok ? 'ok' : 'bad'); el('#health-text').textContent = ok ? 'All systems operational' : 'Degraded'; }
    catch (e) { el('#health-dot').className = 'dot bad'; el('#health-text').textContent = 'Unknown'; }
    if (!location.hash) location.hash = 'dashboard';
    route();
  })();
})();
