(function () {
  function onlyDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function formatRuPhone(value) {
    let digits = onlyDigits(value);
    if (!digits) return '';
    if (digits[0] === '8') digits = '7' + digits.slice(1);
    if (digits[0] !== '7') digits = '7' + digits;
    digits = digits.slice(0, 11);
    const p = digits.slice(1);
    let out = '+7';
    if (p.length) out += ' (' + p.slice(0, 3);
    if (p.length >= 3) out += ')';
    if (p.length > 3) out += ' ' + p.slice(3, 6);
    if (p.length > 6) out += '-' + p.slice(6, 8);
    if (p.length > 8) out += '-' + p.slice(8, 10);
    return out;
  }

  function bindPhoneMasks(scope) {
    (scope || document).querySelectorAll('[data-phone-mask]').forEach(function (input) {
      if (input.dataset.phoneMaskBound === '1') return;
      input.dataset.phoneMaskBound = '1';
      var apply = function () { input.value = formatRuPhone(input.value); };
      input.addEventListener('input', apply);
      input.addEventListener('blur', apply);
      apply();
    });
  }

  function initCookieBanner() {
    var banner = document.getElementById('cookieBanner');
    var btn = document.getElementById('cookieBannerAccept');
    if (!banner || !btn) return;
    var accepted = false;
    try { accepted = localStorage.getItem('flora_cookie_ok') === '1'; } catch (e) {}
    if (!accepted) banner.hidden = false;
    btn.addEventListener('click', function () {
      try { localStorage.setItem('flora_cookie_ok', '1'); } catch (e) {}
      document.cookie = 'flora_cookie_ok=1; path=/; max-age=' + (60 * 60 * 24 * 365);
      banner.hidden = true;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindPhoneMasks(document);
    initCookieBanner();
  });

  window.FloraUi = window.FloraUi || {};
  window.FloraUi.bindPhoneMasks = bindPhoneMasks;
  window.FloraUi.formatRuPhone = formatRuPhone;
})();
