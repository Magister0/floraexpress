<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_config.php';
flora_start_session();
?>
<footer class="site-footer">
  <div class="container">
    <div class="foot-grid">
      <div class="foot-col">
        <div class="foot-title">FloraExpress</div>
        <p class="mute" style="margin:0">Цветы с доставкой по Екатеринбургу. Собираем в день заказа, фото перед выездом и помогаем подобрать подходящий вариант.</p>
      </div>
      <div class="foot-col">
        <div class="foot-title">Навигация</div>
        <ul class="foot-links">
          <li><a href="catalog.php">Каталог</a></li>
          <li><a href="delivery.php">Доставка</a></li>
          <li><a href="payment.php">Оплата</a></li>
          <li><a href="contacts.php">Контакты</a></li>
          <li><a href="care.php">Уход за цветами</a></li>
        </ul>
      </div>
      <div class="foot-col">
        <div class="foot-title">Документы</div>
        <ul class="foot-links">
          <li><a href="privacy.php">Политика конфиденциальности</a></li>
          <li><a href="offer.php">Публичная оферта</a></li>
          <li><a href="privacy.php#cookies">Политика cookie</a></li>
          <li><a href="contacts.php">Связаться с магазином</a></li>
        </ul>
      </div>
      <div class="foot-col">
        <div class="foot-title">Контакты</div>
        <ul class="foot-contacts">
          <li>Екатеринбург</li>
          <li>Ежедневно 10:00–21:00</li>
          <li><a href="contacts.php">Связаться с магазином</a></li>
        </ul>
      </div>
    </div>

    <div class="foot-bottom">
      <div>© <?= date('Y') ?> FloraExpress</div>
      <div>С любовью к цветам</div>
    </div>
  </div>
</footer>

<div class="cookie-banner" id="cookieBanner" hidden>
  <div class="cookie-banner__text">
    Мы используем cookie-файлы, чтобы сайт работал корректно, сохранял корзину и настройки. Продолжая пользоваться сайтом, вы соглашаетесь с
    <a href="privacy.php">политикой конфиденциальности</a>
    и
    <a href="privacy.php#cookies">политикой cookie</a>.
  </div>
  <button class="btn btn--sm cookie-banner__btn" type="button" id="cookieAcceptBtn">Понятно</button>
</div>

<script>
(function(){
  const STORAGE_KEY = 'flora_cookie_ok_v1';
  const COOKIE_NAME = 'flora_cookie_ok';
  const banner = document.getElementById('cookieBanner');
  const btn = document.getElementById('cookieAcceptBtn');
  if (!banner || !btn) return;

  function hasConsent(){
    try {
      if (window.localStorage && localStorage.getItem(STORAGE_KEY) === '1') return true;
    } catch (e) {}
    return document.cookie.split(';').some(function(part){
      return part.trim().indexOf(COOKIE_NAME + '=1') === 0;
    });
  }

  function saveConsent(){
    try {
      if (window.localStorage) localStorage.setItem(STORAGE_KEY, '1');
    } catch (e) {}
    var expires = new Date();
    expires.setFullYear(expires.getFullYear() + 1);
    document.cookie = COOKIE_NAME + '=1; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
  }

  function hideBanner(){
    banner.classList.remove('is-visible');
    banner.setAttribute('hidden', 'hidden');
  }

  function showBanner(){
    banner.removeAttribute('hidden');
    requestAnimationFrame(function(){
      banner.classList.add('is-visible');
    });
  }

  if (!hasConsent()) {
    showBanner();
  }

  btn.addEventListener('click', function(){
    saveConsent();
    hideBanner();
  });
})();
</script>

<?php if (defined('AI_ADVISOR_ENABLED') && AI_ADVISOR_ENABLED): ?>
<!-- AI Advisor Widget -->
<style>
  .ai-fab{
    position:fixed;
    right:22px;bottom:22px;
    z-index:9000;
    width:62px;height:62px;border-radius:50%;
    background:linear-gradient(135deg,#175a37,#0f6b45);
    color:#fff;display:grid;place-items:center;
    box-shadow:0 12px 30px rgba(15,107,69,.35);
    cursor:pointer;border:none;
    transition:transform .2s ease, box-shadow .2s ease;
  }
  .ai-fab:hover{transform:translateY(-2px) scale(1.04);box-shadow:0 16px 36px rgba(15,107,69,.45)}
  .ai-fab svg{width:30px;height:30px}
  .ai-fab__badge{
    position:absolute;top:-4px;right:-4px;
    background:#e11d48;color:#fff;font-size:10px;font-weight:800;
    padding:3px 6px;border-radius:999px;border:2px solid #fff;line-height:1;
  }
  .ai-panel{
    position:fixed;right:22px;bottom:96px;z-index:9001;
    width:380px;max-width:calc(100vw - 44px);
    height:560px;max-height:calc(100vh - 130px);
    background:#fff;border:1px solid #e5e7eb;border-radius:20px;
    box-shadow:0 20px 60px rgba(0,0,0,.18);
    display:none;flex-direction:column;overflow:hidden;
    animation:ai-pop .22s ease;
  }
  .ai-panel.is-open{display:flex}
  @keyframes ai-pop{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
  .ai-head{
    padding:14px 16px;background:linear-gradient(135deg,#175a37,#0f6b45);color:#fff;
    display:flex;align-items:center;gap:10px;flex-shrink:0;
  }
  .ai-head__avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.18);display:grid;place-items:center;font-size:18px}
  .ai-head__title{font-weight:800;font-size:15px;line-height:1.1}
  .ai-head__sub{font-size:11.5px;opacity:.85;margin-top:2px}
  .ai-head__actions{margin-left:auto;display:flex;align-items:center;gap:2px}
  .ai-head__btn{background:none;border:none;color:#fff;cursor:pointer;line-height:1;padding:6px 8px;opacity:.75;border-radius:8px;display:grid;place-items:center;transition:.15s}
  .ai-head__btn:hover{opacity:1;background:rgba(255,255,255,.12)}
  .ai-head__btn svg{width:18px;height:18px}
  .ai-head__close{background:none;border:none;color:#fff;cursor:pointer;font-size:24px;line-height:1;padding:4px 8px;opacity:.8;border-radius:8px}
  .ai-head__close:hover{opacity:1;background:rgba(255,255,255,.12)}
  .ai-body{flex:1;overflow-y:auto;padding:14px;background:#fafbfa;display:flex;flex-direction:column;gap:10px}
  .ai-msg{max-width:85%;padding:10px 13px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;word-break:break-word}
  .ai-msg--user{align-self:flex-end;background:#175a37;color:#fff;border-bottom-right-radius:5px}
  .ai-msg--masked{box-shadow:inset 3px 0 0 #fbbf24}
  .ai-msg--bot{align-self:flex-start;background:#fff;border:1px solid #e7ece8;color:#111827;border-bottom-left-radius:5px}
  .ai-products{align-self:flex-start;width:100%;display:grid;gap:6px;margin-top:-4px}
  .ai-product{
    display:grid;grid-template-columns:46px 1fr auto;gap:10px;align-items:center;
    background:#fff;border:1px solid #e7ece8;border-radius:12px;padding:8px 10px;
    text-decoration:none;color:#111827;transition:.15s;
  }
  .ai-product:hover{border-color:#175a37;background:#f6faf7}
  .ai-product__img{width:46px;height:46px;border-radius:10px;background:#f3f4f6;overflow:hidden}
  .ai-product__img img{width:100%;height:100%;object-fit:cover;display:block}
  .ai-product__name{font-size:13px;font-weight:700;line-height:1.25}
  .ai-product__price{font-size:13px;font-weight:800;color:#175a37;white-space:nowrap}
  .ai-typing{align-self:flex-start;display:flex;gap:4px;padding:8px 12px;background:#fff;border:1px solid #e7ece8;border-radius:14px}
  .ai-typing span{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:ai-dot 1.2s infinite ease-in-out}
  .ai-typing span:nth-child(2){animation-delay:.2s}
  .ai-typing span:nth-child(3){animation-delay:.4s}
  @keyframes ai-dot{0%,80%,100%{opacity:.3;transform:scale(.7)}40%{opacity:1;transform:scale(1)}}
  .ai-hints{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 6px}
  .ai-hint{font-size:12px;background:#fff;border:1px solid #e7ece8;border-radius:999px;padding:5px 10px;cursor:pointer;color:#374151}
  .ai-hint:hover{border-color:#175a37;color:#175a37;background:#f6faf7}
  .ai-input-row{display:flex;gap:8px;padding:12px;background:#fff;border-top:1px solid #e7ece8;flex-shrink:0}
  .ai-input{flex:1;border:1px solid #e7ece8;border-radius:12px;padding:10px 12px;font-size:14px;font-family:inherit;resize:none;outline:none;max-height:120px;min-height:42px}
  .ai-input:focus{border-color:#175a37;box-shadow:0 0 0 3px rgba(23,90,55,.12)}
  .ai-send{background:#175a37;color:#fff;border:none;border-radius:12px;padding:0 16px;font-weight:700;font-size:14px;cursor:pointer;transition:.15s}
  .ai-send:hover{background:#0f6b45}
  .ai-send:disabled{background:#cbd5e1;cursor:not-allowed}
  .ai-foot-note{font-size:10.5px;color:#94a3b8;text-align:center;padding:4px 12px 6px;background:#fff}
  .ai-mode-badge{display:inline-block;padding:1px 6px;border-radius:999px;font-size:10px;font-weight:700;margin-left:4px}
  .ai-mode-badge--llm{background:rgba(255,255,255,.2);color:#fff}
  .ai-mode-badge--rules{background:rgba(255,255,255,.25);color:#fff}
  @media (max-width:520px){
    .ai-panel{right:10px;left:10px;width:auto;bottom:86px;height:calc(100vh - 110px)}
    .ai-fab{right:14px;bottom:14px}
  }
</style>

<button class="ai-fab" type="button" id="aiFab" aria-label="Открыть AI-консультант" title="AI-подбор букета">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M12 2L13.5 8.5L20 10L13.5 11.5L12 18L10.5 11.5L4 10L10.5 8.5L12 2Z"/>
    <path d="M19 17L19.5 19L21 19.5L19.5 20L19 22L18.5 20L17 19.5L18.5 19L19 17Z"/>
  </svg>
  <span class="ai-fab__badge">AI</span>
</button>

<div class="ai-panel" id="aiPanel" role="dialog" aria-labelledby="aiTitle" aria-hidden="true">
  <div class="ai-head">
    <div class="ai-head__avatar">🌸</div>
    <div>
      <div class="ai-head__title" id="aiTitle">Алиса
        <?php if (ai_advisor_yandex_available()): ?>
          <span class="ai-mode-badge ai-mode-badge--llm" title="Работает через YandexGPT">YandexGPT</span>
        <?php else: ?>
          <span class="ai-mode-badge ai-mode-badge--rules" title="Работает через справочник цветов и правила">Rules</span>
        <?php endif; ?>
      </div>
      <div class="ai-head__sub">Помогу подобрать букет под повод</div>
    </div>
    <div class="ai-head__actions">
      <button class="ai-head__btn" type="button" id="aiClear" aria-label="Очистить диалог" title="Очистить диалог">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
          <line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>
        </svg>
      </button>
      <button class="ai-head__close" type="button" id="aiClose" aria-label="Закрыть">×</button>
    </div>
  </div>
  <div class="ai-body" id="aiBody">
    <div class="ai-msg ai-msg--bot">
      Здравствуйте! Расскажите, для кого букет — повод, настроение, бюджет — и я подберу несколько вариантов из нашего каталога.
    </div>
  </div>
  <div class="ai-hints" id="aiHints">
    <button class="ai-hint" type="button" data-hint="Нужен букет маме на день рождения, бюджет 3000 ₽">Маме на ДР, 3000 ₽</button>
    <button class="ai-hint" type="button" data-hint="Хочу подарить девушке на годовщину что-то романтичное">Романтика на годовщину</button>
    <button class="ai-hint" type="button" data-hint="Что подарить учительнице на 1 сентября?">Учительнице</button>
  </div>
  <form class="ai-input-row" id="aiForm" autocomplete="off">
    <textarea class="ai-input" id="aiInput" name="message" placeholder="Опишите, что нужно..." rows="1" maxlength="600"></textarea>
    <button class="ai-send" type="submit" id="aiSend">Спросить</button>
  </form>
  <div class="ai-foot-note">AI может ошибаться. Уточняйте у менеджера.</div>
</div>

<script>
(function(){
  const fab = document.getElementById('aiFab');
  const panel = document.getElementById('aiPanel');
  const closeBtn = document.getElementById('aiClose');
  const clearBtn = document.getElementById('aiClear');
  const body = document.getElementById('aiBody');
  const form = document.getElementById('aiForm');
  const input = document.getElementById('aiInput');
  const send = document.getElementById('aiSend');
  const hints = document.getElementById('aiHints');
  if (!fab || !panel) return;

  const GREETING_HTML = '<div class="ai-msg ai-msg--bot">Здравствуйте! Расскажите, для кого букет — повод, настроение, бюджет — и я подберу несколько вариантов из нашего каталога.</div>';

  let historyLoaded = false;
  let busy = false;

  function open(){
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    if (!historyLoaded) loadHistory();
    setTimeout(() => input && input.focus(), 100);
  }
  function close(){
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
  }
  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function nf(n){ return Math.round(Number(n)||0).toLocaleString('ru-RU') + ' ₽'; }
  function scrollBottom(){ body.scrollTop = body.scrollHeight; }

  function renderProducts(products){
    if (!Array.isArray(products) || !products.length) return '';
    return '<div class="ai-products">' + products.map(p => `
      <a class="ai-product" href="product.php?id=${parseInt(p.id,10)}">
        <span class="ai-product__img">${p.image ? `<img src="${escapeHtml(p.image)}" alt="${escapeHtml(p.name)}" onerror="this.style.display='none'">` : ''}</span>
        <span class="ai-product__name">${escapeHtml(p.name)}</span>
        <span class="ai-product__price">${nf(p.price)}</span>
      </a>
    `).join('') + '</div>';
  }

  function addBotMessage(text, products){
    const msg = document.createElement('div');
    msg.className = 'ai-msg ai-msg--bot';
    msg.textContent = text;
    body.appendChild(msg);
    if (products && products.length) {
      const wrap = document.createElement('div');
      wrap.innerHTML = renderProducts(products);
      while (wrap.firstChild) body.appendChild(wrap.firstChild);
    }
    scrollBottom();
  }
  function addUserMessage(text){
    const msg = document.createElement('div');
    msg.className = 'ai-msg ai-msg--user';
    msg.textContent = text;
    body.appendChild(msg);
    scrollBottom();
    return msg;
  }
  function showTyping(){
    const t = document.createElement('div');
    t.className = 'ai-typing';
    t.id = 'aiTyping';
    t.innerHTML = '<span></span><span></span><span></span>';
    body.appendChild(t);
    scrollBottom();
  }
  function hideTyping(){
    const t = document.getElementById('aiTyping');
    if (t) t.remove();
  }

  async function loadHistory(){
    try {
      const res = await fetch('ai_advisor.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'history'}),
      });
      const data = await res.json();
      if (data && data.ok && Array.isArray(data.history) && data.history.length) {
        // Удаляем стартовое приветствие, чтобы не дублировать
        body.innerHTML = '';
        data.history.forEach(m => {
          if (m.role === 'user') addUserMessage(m.content);
          else if (m.role === 'assistant') addBotMessage(m.content, m.products);
        });
      }
      historyLoaded = true;
    } catch (e) {
      historyLoaded = true;
    }
  }

  async function sendMessage(text){
    if (!text || busy) return;
    busy = true;
    send.disabled = true;

    const userMsgEl = addUserMessage(text);
    input.value = '';
    if (hints) hints.style.display = 'none';
    showTyping();

    try {
      const res = await fetch('ai_advisor.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'message', message: text}),
      });
      const data = await res.json();
      hideTyping();
      if (data && data.ok) {
        // Если сервер замаскировал персональные данные — подменяем текст в пузырьке пользователя.
        if (data.user_message_masked && data.user_message_masked !== text && userMsgEl) {
          userMsgEl.textContent = data.user_message_masked;
          userMsgEl.classList.add('ai-msg--masked');
        }
        if (data.pii_warning) {
          addBotMessage(data.pii_warning);
        }
        addBotMessage(data.reply || 'Готов помочь дальше.', data.recommended);
      } else {
        const errText = (data && (data.debug || data.error)) ? `Ошибка: ${data.debug || data.error}` : 'Что-то пошло не так. Попробуйте ещё раз через минуту.';
        addBotMessage(errText);
      }
    } catch (e) {
      hideTyping();
      addBotMessage('Не удалось связаться с сервером. Проверьте соединение.');
    } finally {
      busy = false;
      send.disabled = false;
      input.focus();
    }
  }

  async function clearChat(){
    if (!confirm('Очистить историю диалога? Действие нельзя отменить.')) return;
    try {
      await fetch('ai_advisor.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({action: 'reset'}),
      });
    } catch (e) { /* всё равно очищаем UI */ }
    body.innerHTML = GREETING_HTML;
    if (hints) hints.style.display = '';
    historyLoaded = true;
  }

  fab.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  if (clearBtn) clearBtn.addEventListener('click', clearChat);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    sendMessage(input.value.trim());
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage(input.value.trim());
    }
  });
  if (hints) {
    hints.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-hint]');
      if (btn) sendMessage(btn.getAttribute('data-hint'));
    });
  }

  // Автоширина textarea
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });
})();
</script>
<?php endif; ?>
