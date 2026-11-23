(function () {
  var MOBILE_LEN = 11;
  var LETTER_OK = /[^\p{L} .'\-]/gu;

  function mobilePrefixOk(v) {
    if (v.length >= 1 && v.charAt(0) !== '0') return false;
    if (v.length >= 2 && v.charAt(1) !== '9') return false;
    return true;
  }
  function mobileClean(v) {
    v = v.replace(/\D+/g, '');
    if (v.length >= 12 && v.slice(0, 2) === '63') v = '0' + v.slice(2);
    if (v.length === 10 && v.charAt(0) === '9') v = '0' + v;
    while (v.length && !mobilePrefixOk(v)) v = v.slice(0, -1);
    return v.slice(0, MOBILE_LEN);
  }

  function mobileMsg(v) {
    if (v === '') return 'Please enter a mobile number.';
    if (v.length < MOBILE_LEN) return 'Mobile number is too short. It must be exactly ' + MOBILE_LEN + ' digits (e.g. 09171234567).';
    if (v.length > MOBILE_LEN) return 'Mobile number is too long. It must be exactly ' + MOBILE_LEN + ' digits.';
    if (v.slice(0, 2) !== '09') return 'Mobile number must start with 09 (e.g. 09171234567).';
    return '';
  }

  function errBox(el) {
    var wrap = el.closest('.input-icon') || el;
    var box = wrap.nextElementSibling;
    if (!box || !box.classList.contains('vfield-err')) {
      box = document.createElement('span');
      box.className = 'vfield-err';
      box.setAttribute('role', 'alert');
      wrap.insertAdjacentElement('afterend', box);
    }
    return box;
  }
  function setErr(el, msg) {
    if (el.closest('[data-no-vcheck]')) return;
    var box = errBox(el);
    box.textContent = msg;
    el.classList.toggle('vfield-bad', !!msg);
    el.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }

  function strip(el, re) {
    var before = el.value, a = el.selectionStart;
    var after = before.replace(re, '');
    if (after !== before) {
      el.value = after;
      try { var pos = Math.max(0, (a || 0) - (before.length - after.length)); el.setSelectionRange(pos, pos); } catch (e) {}
      return true;
    }
    return false;
  }

  function setup(root) {
    root.querySelectorAll('input[data-mobile]').forEach(function (el) {
      if (el.dataset.vReady) return; el.dataset.vReady = '1';
      el.setAttribute('inputmode', 'numeric');
      el.setAttribute('maxlength', String(MOBILE_LEN));
      el.setAttribute('autocomplete', 'tel-national');
      if (!el.placeholder) el.placeholder = '09171234567';
      el.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
        if (!/[0-9]/.test(e.key)) { e.preventDefault(); return; }
        var a = el.selectionStart, b = el.selectionEnd;
        if (a === null) return;
        var next = el.value.slice(0, a) + e.key + el.value.slice(b);
        if (!mobilePrefixOk(next)) { e.preventDefault(); setErr(el, 'Mobile number must start with 09 (e.g. 09171234567).'); }
      });
      el.addEventListener('input', function () {
        var raw = el.value, cleaned = mobileClean(raw);
        var hadBad = /\D/.test(raw);
        if (cleaned !== raw) { el.value = cleaned; if (!hadBad) { setErr(el, 'Mobile number must start with 09 (e.g. 09171234567).'); return; } }
        if (hadBad) setErr(el, 'Mobile number can only contain numbers.');
        else if (el.classList.contains('vfield-bad')) { var m = mobileMsg(el.value); setErr(el, m === 'Please enter a mobile number.' ? '' : m); }
      });
      el.addEventListener('blur', function () { if (el.value !== '') setErr(el, mobileMsg(el.value)); });
    });

    root.querySelectorAll('input[data-digits]').forEach(function (el) {
      if (el.dataset.vReady) return; el.dataset.vReady = '1';
      el.setAttribute('inputmode', 'numeric');
      el.setAttribute('maxlength', '15');
      el.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
        if (!/[0-9]/.test(e.key)) e.preventDefault();
      });
      el.addEventListener('input', function () {
        if (strip(el, /\D+/g)) setErr(el, 'Numbers only - letters and symbols are not allowed here.');
        else if (el.classList.contains('vfield-bad')) setErr(el, '');
      });
    });

    root.querySelectorAll('input[data-letters]').forEach(function (el) {
      if (el.dataset.vReady) return; el.dataset.vReady = '1';
      el.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
        if (/[^\p{L} .'\-]/u.test(e.key)) e.preventDefault();
      });
      el.addEventListener('input', function () {
        if (strip(el, LETTER_OK)) setErr(el, 'Letters only - numbers and symbols are not allowed here.');
        else if (el.classList.contains('vfield-bad')) setErr(el, '');
      });
    });
  }

  function validateForm(form) {
    var first = null;
    form.querySelectorAll('input[data-mobile]').forEach(function (el) {
      if (el.offsetParent === null) return;
      var m = (el.required || el.value !== '') ? mobileMsg(el.value) : '';
      setErr(el, m);
      if (m && !first) first = el;
    });
    form.querySelectorAll('input[data-digits]').forEach(function (el) {
      if (el.offsetParent === null) return;
      var m = el.value !== '' && /\D/.test(el.value) ? 'Numbers only - letters and symbols are not allowed here.' : '';
      setErr(el, m);
      if (m && !first) first = el;
    });
    form.querySelectorAll('input[data-letters]').forEach(function (el) {
      if (el.offsetParent === null) return;
      var v = el.value.trim(), m = '';
      if (v === '' && el.required) m = 'This field is required.';
      else if (v !== '' && /[^\p{L} .'\-]/u.test(v)) m = 'Letters only - numbers and symbols are not allowed here.';
      setErr(el, m);
      if (m && !first) first = el;
    });
    return first;
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM' || form.hasAttribute('data-no-vcheck')) return;
    var bad = validateForm(form);
    if (bad) { e.preventDefault(); e.stopPropagation(); bad.focus(); }
  }, true);

  var css = document.createElement('style');
  css.textContent = '.vfield-err{display:block;color:#c0392b;font-size:.8rem;line-height:1.3;margin-top:4px;min-height:0}' +
                    '.vfield-err:empty{display:none}.vfield-bad{border-color:#c0392b!important}';
  document.head.appendChild(css);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setup(document); });
  else setup(document);
  window.MSCValidate = { setup: setup, mobileMsg: mobileMsg };
})();
