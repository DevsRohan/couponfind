/* Auth pages: login + register */
(function (global) {
  const { el, toast } = UI;

  function redirectAfterAuth(user) {
    const params = new URLSearchParams(location.search);
    const next = params.get('next');
    if (next && next.startsWith('/')) { location.href = next; return; }
    location.href = user && user.is_admin ? '/admin' : '/app';
  }

  function showError(msg, errors) {
    const box = el('#err');
    let text = msg || 'Something went wrong';
    if (errors) {
      const first = Object.values(errors)[0];
      if (Array.isArray(first)) text = first[0];
    }
    box.textContent = text;
    box.classList.remove('hide');
  }

  function busy(on) {
    const b = el('#submit');
    b.disabled = on;
    b.innerHTML = on ? '<span class="spinner"></span> Please wait…' : (b.dataset.label || b.textContent);
  }

  function initLogin() {
    if (API.isAuthed()) { redirectAfterAuth(API.store.user); return; }
    const b = el('#submit'); b.dataset.label = b.textContent;
    el('#form').addEventListener('submit', async (e) => {
      e.preventDefault();
      el('#err').classList.add('hide');
      busy(true);
      try {
        const data = await API.login(el('#email').value.trim(), el('#password').value);
        toast('Welcome back!', 'ok');
        redirectAfterAuth(data.user);
      } catch (err) {
        showError(err.message, err.errors);
        busy(false);
      }
    });
  }

  function initRegister() {
    if (API.isAuthed()) { redirectAfterAuth(API.store.user); return; }
    const b = el('#submit'); b.dataset.label = b.textContent;
    const ref = new URLSearchParams(location.search).get('ref');
    el('#form').addEventListener('submit', async (e) => {
      e.preventDefault();
      el('#err').classList.add('hide');
      busy(true);
      try {
        const payload = {
          name: el('#name').value.trim(),
          email: el('#email').value.trim(),
          password: el('#password').value,
        };
        if (ref) payload.referral_code = ref;
        const data = await API.register(payload);
        toast('Account created!', 'ok');
        redirectAfterAuth(data.user);
      } catch (err) {
        showError(err.message, err.errors);
        busy(false);
      }
    });
  }

  global.CFAuth = { initLogin, initRegister };
})(window);
