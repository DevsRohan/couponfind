/* =====================================================================
   CouponFind API client (vanilla JS)
   - JSON fetch wrapper with bearer token + CSRF handling
   - transparent access-token refresh on 401
   ===================================================================== */
(function (global) {
  const TOKEN_KEY = 'cf_access';
  const REFRESH_KEY = 'cf_refresh';
  const CSRF_KEY = 'cf_csrf';
  const USER_KEY = 'cf_user';

  const store = {
    get access() { return localStorage.getItem(TOKEN_KEY); },
    set access(v) { v ? localStorage.setItem(TOKEN_KEY, v) : localStorage.removeItem(TOKEN_KEY); },
    get refresh() { return localStorage.getItem(REFRESH_KEY); },
    set refresh(v) { v ? localStorage.setItem(REFRESH_KEY, v) : localStorage.removeItem(REFRESH_KEY); },
    get csrf() { return localStorage.getItem(CSRF_KEY); },
    set csrf(v) { v ? localStorage.setItem(CSRF_KEY, v) : localStorage.removeItem(CSRF_KEY); },
    get user() { try { return JSON.parse(localStorage.getItem(USER_KEY)); } catch (e) { return null; } },
    set user(v) { v ? localStorage.setItem(USER_KEY, JSON.stringify(v)) : localStorage.removeItem(USER_KEY); },
    clear() { [TOKEN_KEY, REFRESH_KEY, CSRF_KEY, USER_KEY].forEach(k => localStorage.removeItem(k)); }
  };

  async function raw(method, path, body, opts = {}) {
    const headers = { 'Accept': 'application/json' };
    if (body !== undefined && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    if (store.access && !opts.noAuth) headers['Authorization'] = 'Bearer ' + store.access;
    if (store.csrf) headers['X-CSRF-Token'] = store.csrf;

    const res = await fetch('/api' + path, {
      method,
      headers,
      credentials: 'same-origin',
      body: body !== undefined ? (body instanceof FormData ? body : JSON.stringify(body)) : undefined,
    });

    let json = null;
    const text = await res.text();
    if (text) { try { json = JSON.parse(text); } catch (e) { json = { success: false, message: text }; } }
    return { res, json };
  }

  async function request(method, path, body, opts = {}) {
    let { res, json } = await raw(method, path, body, opts);

    // Auto-refresh once on 401.
    if (res.status === 401 && store.refresh && !opts._retried) {
      const ok = await tryRefresh();
      if (ok) return request(method, path, body, { ...opts, _retried: true });
    }

    if (!res.ok) {
      const err = new Error((json && json.message) || ('Request failed (' + res.status + ')'));
      err.status = res.status;
      err.errors = (json && json.errors) || null;
      err.payload = json;
      throw err;
    }
    return json ? json.data ?? json : null;
  }

  async function tryRefresh() {
    try {
      const { res, json } = await raw('POST', '/auth/refresh', { refresh_token: store.refresh }, { noAuth: true });
      if (res.ok && json && json.data) {
        applyAuth(json.data);
        return true;
      }
    } catch (e) { /* ignore */ }
    store.clear();
    return false;
  }

  function applyAuth(data) {
    if (data.access_token) store.access = data.access_token;
    if (data.refresh_token) store.refresh = data.refresh_token;
    if (data.csrf_token) store.csrf = data.csrf_token;
    if (data.user) store.user = data.user;
  }

  const API = {
    store,
    applyAuth,
    isAuthed: () => !!store.access,
    get: (p, o) => request('GET', p, undefined, o),
    post: (p, b, o) => request('POST', p, b, o),
    put: (p, b, o) => request('PUT', p, b, o),
    patch: (p, b, o) => request('PATCH', p, b, o),
    del: (p, o) => request('DELETE', p, undefined, o),

    async login(email, password) {
      const { res, json } = await raw('POST', '/auth/login', { email, password }, { noAuth: true });
      if (!res.ok) throw Object.assign(new Error(json?.message || 'Login failed'), { errors: json?.errors });
      applyAuth(json.data);
      return json.data;
    },
    async register(payload) {
      const { res, json } = await raw('POST', '/auth/register', payload, { noAuth: true });
      if (!res.ok) throw Object.assign(new Error(json?.message || 'Registration failed'), { errors: json?.errors });
      applyAuth(json.data);
      return json.data;
    },
    async logout() {
      try { await request('POST', '/auth/logout', { refresh_token: store.refresh }); } catch (e) {}
      store.clear();
    },
    async me() { return request('GET', '/auth/me'); },
  };

  global.API = API;
})(window);
