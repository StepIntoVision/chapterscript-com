(function () {
  "use strict";
  const $ = (selector, root = document) => root.querySelector(selector);
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const form = $('#access-form');
  if (!form) return;
  form.noValidate = true;

  const endpoint = form.dataset.endpoint;
  const submitBtn = $('#submit');
  const submitLabel = $('.btn-label', submitBtn);
  const errorBox = $('#form-error');
  const sent = $('#sent');
  const story = $('#f-story');
  const count = $('#c-story');

  const rules = [
    [$('#f-name'), v => v.trim().length > 0, $('#e-name')],
    [$('#f-email'), v => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()), $('#e-email')],
    [story, v => v.trim().length >= 10, $('#e-story')],
  ];

  function validate() {
    let first = null;
    for (const [el, ok, err] of rules) {
      const good = ok(el.value);
      el.setAttribute('aria-invalid', String(!good));
      err.hidden = good;
      if (!good && !first) first = el;
    }
    if (first) first.focus();
    return !first;
  }
  rules.forEach(([el, ok, err]) => el.addEventListener('input', () => {
    if (el.getAttribute('aria-invalid') === 'true' && ok(el.value)) {
      el.setAttribute('aria-invalid', 'false');
      err.hidden = true;
    }
  }));

  story.addEventListener('input', () => { count.textContent = `${story.value.length} / 3000`; });

  function collect() {
    const out = { tools: [], making: [] };
    for (const [key, value] of new FormData(form).entries()) {
      const val = String(value).trim();
      if (key === 'tools[]' || key === 'making[]') out[key.slice(0, -2)].push(val);
      else out[key] = val;
    }
    return out;
  }

  function showSent(data) {
    const first = (data.name || '').split(/\s+/)[0];
    $('#sent-name').textContent = first ? `, ${first}` : '';
    $('#sent-email').textContent = data.email || 'your inbox';
    form.hidden = true;
    sent.hidden = false;
    sent.focus({ preventScroll: true });
    sent.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
  }

  let sending = false;
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    if (sending) return;
    errorBox.hidden = true;
    if (!validate()) return;

    const data = collect();

    sending = true;
    submitBtn.disabled = true;
    submitBtn.classList.add('is-sending');
    submitLabel.textContent = 'Sending';

    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 15000);
    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(data),
        credentials: 'same-origin',
        signal: ctl.signal,
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || json?.ok !== true) {
        const message = typeof json?.message === 'string' ? json.message
          : 'Your answers are still here. Please try again.';
        if (json?.errors && typeof json.errors === 'object') {
          for (const [key, error] of Object.entries(json.errors)) {
            const el = form.elements.namedItem(key);
            const err = document.getElementById(`e-${key}`);
            if (el instanceof HTMLElement) el.setAttribute('aria-invalid', 'true');
            if (err && typeof error === 'string') { err.textContent = error; err.hidden = false; }
          }
        }
        throw new Error(message);
      }
      showSent(data);
    } catch (err) {
      $('#form-error-message').textContent = err.name === 'AbortError' || err instanceof TypeError
        ? 'We could not confirm your request. Your answers are still here; retrying will not create a duplicate.'
        : err.message;
      errorBox.hidden = false;
      errorBox.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
    } finally {
      clearTimeout(timer);
      sending = false;
      submitBtn.disabled = false;
      submitBtn.classList.remove('is-sending');
      submitLabel.textContent = 'Tell us your story';
    }
  });
})();
