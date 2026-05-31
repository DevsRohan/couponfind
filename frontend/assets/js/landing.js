/* Landing page logic: live search demo, featured fallback, pricing, examples */
(function () {
  const { h, el, fmt, icon, toast, esc, copyToClipboard } = UI;

  el('#year').textContent = new Date().getFullYear();
  el('#search-ico').innerHTML = icon('search');

  const examples = ['best amazon coupon today', 'hostinger discount', 'nike offer', 'best vpn deal', 'bst niek coupn'];
  const exWrap = el('#examples');
  examples.forEach(e => exWrap.appendChild(h('span', { class: 'chip', onclick: () => { el('#q').value = e; runSearch(); } }, e)));

  const features = [
    { icon: 'bolt', title: 'Typo-proof', desc: 'Misspell anything — "niek", "hostingr", "amazn". We resolve the brand with fuzzy matching + AI.' },
    { icon: 'cpu', title: 'Intent-aware', desc: 'Detects merchant, discount type, and time intent ("today", "20% off") to rank precisely.' },
    { icon: 'activity', title: 'Blazing fast', desc: 'Meilisearch + Redis cache deliver ranked results in well under 200ms.' },
    { icon: 'shield', title: 'Validated', desc: 'Coupons are auto-validated, deduped, and scored by reliability before you ever see them.' },
    { icon: 'tag', title: 'Best-first ranking', desc: 'A composite score blends freshness, success rate, popularity and value.' },
    { icon: 'store', title: 'Always fresh', desc: 'A background engine continuously discovers new deals from official sources.' },
  ];
  const fg = el('#features-grid');
  features.forEach(f => fg.appendChild(h('div', { class: 'card p-6' }, [
    h('div', { class: 'feature-ico', html: icon(f.icon) }),
    h('h3', { class: 'font-bold mt-4', style: 'font-size:1.05rem;' }, f.title),
    h('p', { class: 'text-muted text-sm mt-2' }, f.desc),
  ])));

  el('#go').addEventListener('click', runSearch);
  el('#q').addEventListener('keydown', e => { if (e.key === 'Enter') runSearch(); });

  let timer;
  async function runSearch() {
    const q = el('#q').value.trim();
    const box = el('#results');
    if (!q) { box.innerHTML = ''; return; }
    box.innerHTML = '';
    box.appendChild(UI.skeletonList(4, '88px'));
    clearTimeout(timer);
    try {
      const data = await API.post('/search', { q });
      renderResults(data);
    } catch (e) {
      if (e.status === 402) {
        box.innerHTML = '';
        box.appendChild(h('div', { class: 'card p-6 text-center' }, [
          h('p', { class: 'font-semibold' }, 'Free guest limit reached'),
          h('p', { class: 'text-muted text-sm mt-1' }, e.message),
          h('a', { href: '/register', class: 'btn btn-primary mt-4', style: 'display:inline-flex;' }, 'Sign up free'),
        ]));
      } else {
        toast(e.message || 'Search failed', 'err');
        box.innerHTML = '';
      }
    }
  }

  function renderResults(data) {
    const box = el('#results');
    box.innerHTML = '';
    const meta = h('div', { class: 'flex items-center justify-between text-sm text-muted mb-3 fade-up' }, [
      h('span', {}, `${fmt.num(data.count)} results · ${data.took_ms}ms · via ${data.source}${data.cache_hit ? ' (cached)' : ''}`),
      h('span', {}, data.intent && data.intent.confidence ? `intent ${Math.round(data.intent.confidence * 100)}%${data.intent.ai_assisted ? ' · AI' : ''}` : ''),
    ]);
    box.appendChild(meta);

    if (!data.results.length) {
      box.appendChild(h('div', { class: 'card p-8 text-center text-muted' }, 'No coupons found. Try another search.'));
      return;
    }
    const grid = h('div', { class: 'grid md:grid-cols-2 gap-3 stagger' });
    data.results.forEach(c => grid.appendChild(couponCard(c)));
    box.appendChild(grid);
  }

  function couponCard(c) {
    const codeBtn = c.code
      ? h('button', { class: 'code-pill btn-soft', style: 'cursor:pointer;', onclick: () => { copyToClipboard(c.code); API.post('/coupons/' + c.id + '/use', {}).catch(() => {}); } }, [c.code + '  ', h('span', { style: 'width:13px;height:13px;display:inline-block;vertical-align:-2px;', html: icon('copy') })])
      : h('a', { class: 'btn btn-soft btn-sm', href: c.landing_url || '#', target: '_blank' }, 'View deal');

    return h('div', { class: 'card coupon-card p-5 flex flex-col gap-3' }, [
      h('div', { class: 'flex items-start justify-between gap-3' }, [
        h('div', {}, [
          h('div', { class: 'flex items-center gap-2' }, [
            h('span', { class: 'badge badge-accent' }, fmt.discount(c)),
            h('span', { class: 'text-muted text-xs' }, c.merchant_name),
          ]),
          h('h4', { class: 'font-bold mt-2', style: 'font-size:1rem;line-height:1.3;' }, c.title),
        ]),
      ]),
      c.description ? h('p', { class: 'text-muted text-sm', style: 'margin:0;' }, c.description) : null,
      h('div', { class: 'flex items-center justify-between mt-1' }, [
        codeBtn,
        c.valid_until ? h('span', { class: 'text-xs text-muted' }, 'Ends ' + fmt.date(c.valid_until)) : h('span', {}),
      ]),
    ]);
  }

  // Pricing
  (async function loadPlans() {
    const grid = el('#pricing-grid');
    try {
      const data = await API.get('/plans', { noAuth: true });
      grid.innerHTML = '';
      const highlight = 'pro';
      data.plans.forEach(p => {
        const featured = p.slug === highlight;
        const card = h('div', { class: 'card p-6 flex flex-col', style: featured ? 'border-color:var(--accent);box-shadow:0 0 0 1px var(--accent),var(--shadow);' : '' }, [
          featured ? h('div', { class: 'badge badge-accent mb-2', style: 'align-self:flex-start;' }, 'Most popular') : null,
          h('h3', { class: 'font-bold', style: 'font-size:1.1rem;' }, p.name),
          h('div', { class: 'mt-2 flex items-end gap-1' }, [
            h('span', { class: 'h-display', style: 'font-size:2rem;' }, p.price_cents === 0 ? 'Free' : fmt.money(p.price_cents, p.currency)),
            p.price_cents ? h('span', { class: 'text-muted text-sm', style: 'margin-bottom:6px;' }, '/' + p.interval) : null,
          ]),
          h('p', { class: 'text-muted text-sm mt-1' }, p.description || ''),
          h('ul', { class: 'mt-4 grid gap-2 text-sm', style: 'list-style:none;padding:0;flex:1;' },
            (p.features || []).map(f => h('li', { class: 'flex items-center gap-2 text-muted' }, [h('span', { class: 'text-accent' }, '✓'), f]))),
          h('a', { href: '/register', class: 'btn ' + (featured ? 'btn-primary' : 'btn-ghost') + ' mt-5' }, p.price_cents === 0 ? 'Start free' : 'Choose ' + p.name),
        ]);
        grid.appendChild(card);
      });
    } catch (e) {
      grid.innerHTML = '<div class="card p-6 text-muted">Pricing unavailable. Start the backend to load plans.</div>';
    }
  })();
})();
