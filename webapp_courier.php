<?php
// FloraExpress — Telegram Mini App курьера (фронтенд).
// Токен бота здесь НЕ используется и не отдаётся клиенту — вся проверка на сервере (webapp_courier_api.php).
require_once __DIR__ . '/map_config.php';
header('Content-Type: text/html; charset=utf-8');
$ymapsKey = defined('FLORA_YANDEX_MAPS_API_KEY') ? FLORA_YANDEX_MAPS_API_KEY : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>FloraExpress — Курьер</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
  :root{
    --bg: var(--tg-theme-secondary-bg-color, #f3f1f4);
    --card: var(--tg-theme-bg-color, #ffffff);
    --text: var(--tg-theme-text-color, #2b1f26);
    --hint: var(--tg-theme-hint-color, #8c7682);
    --link: var(--tg-theme-link-color, #2f6fc2);
    --rose:#c2557a; --green:#5e8c5a; --berry:#401a2c;
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
  body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
       background:var(--bg);color:var(--text);padding-bottom:24px;}
  header{padding:14px 16px 10px;}
  .hi{font-size:18px;font-weight:700;}
  .shift{display:flex;align-items:center;justify-content:space-between;
         margin-top:10px;background:var(--card);border-radius:14px;padding:12px 14px;}
  .shift .lbl{font-size:14px;}
  .dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;vertical-align:middle;}
  .on{background:var(--green);} .off{background:#c0392b;}
  .switch{border:none;border-radius:10px;padding:9px 14px;font-size:14px;font-weight:600;color:#fff;cursor:pointer;}
  .switch.go{background:var(--green);} .switch.stop{background:#c0392b;}
  .locbtn{width:100%;margin-top:10px;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:700;color:#fff;background:var(--green);cursor:pointer;}
  .locbtn.on{background:#c0392b;}
  .locstatus{font-size:12px;color:var(--hint);margin-top:6px;text-align:center;line-height:1.3;}
  .locstatus.err{color:#c0392b;}
  .tabs{display:flex;gap:6px;padding:8px 12px 4px;position:sticky;top:0;background:var(--bg);z-index:5;}
  .tab{flex:1;text-align:center;padding:10px 4px;border-radius:10px;font-size:13px;font-weight:600;
       color:var(--hint);background:transparent;border:none;cursor:pointer;}
  .tab.active{background:var(--rose);color:#fff;}
  .tab .badge{display:inline-block;min-width:18px;padding:0 5px;margin-left:4px;border-radius:9px;
              background:rgba(0,0,0,.12);font-size:11px;line-height:18px;}
  .tab.active .badge{background:rgba(255,255,255,.3);}
  main{padding:6px 12px;}
  .card{background:var(--card);border-radius:16px;padding:14px;margin:10px 0;
        box-shadow:0 1px 6px rgba(0,0,0,.06);}
  .row{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
  .id{font-weight:700;font-size:15px;}
  .badge-st{font-size:12px;font-weight:700;padding:3px 9px;border-radius:8px;color:#fff;white-space:nowrap;}
  .st-pending{background:#9b8aa0;} .st-assigned{background:#d9a300;} .st-picked{background:#c2557a;}
  .st-en_route{background:#2f6fc2;} .st-delivered{background:#5e8c5a;} .st-failed{background:#c0392b;}
  .line{font-size:14px;margin-top:7px;line-height:1.35;}
  .line .k{color:var(--hint);} .line .v{font-weight:600;}
  .sep{height:1px;background:rgba(0,0,0,.08);margin:11px 0;}
  .money{display:flex;gap:14px;flex-wrap:wrap;font-size:14px;margin-top:4px;}
  .fee{color:var(--green);font-weight:700;}
  a.maplink{color:var(--link);text-decoration:none;font-weight:600;}
  .btn{display:block;width:100%;border:none;border-radius:12px;padding:13px;margin-top:10px;
       font-size:15px;font-weight:700;color:#fff;cursor:pointer;text-align:center;text-decoration:none;
       font-family:inherit;line-height:1.2;box-sizing:border-box;}
  .btn.take{background:var(--rose);} .btn.next{background:var(--green);}
  .btn.fail{background:transparent;color:#c0392b;border:1.5px solid #c0392b;font-weight:600;margin-top:8px;}
  .btn.tel{background:var(--berry);}
  .empty{text-align:center;color:var(--hint);padding:48px 16px;font-size:15px;}
  .stat{display:flex;gap:10px;margin:12px 0;}
  .stat .box{flex:1;background:var(--card);border-radius:16px;padding:16px;text-align:center;}
  .stat .num{font-size:24px;font-weight:800;}
  .stat .num.r{color:var(--rose);} .stat .num.g{color:var(--green);}
  .stat .cap{font-size:12px;color:var(--hint);margin-top:4px;}
  .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);
         background:var(--berry);color:#fff;padding:11px 18px;border-radius:12px;font-size:14px;
         opacity:0;transition:opacity .2s;pointer-events:none;z-index:30;max-width:90%;text-align:center;}
  .toast.show{opacity:.96;}
  .btn.map{background:#4f6d7a;}
  .mapwrap{position:fixed;inset:0;background:var(--bg);z-index:40;display:none;flex-direction:column;}
  .maphead{display:flex;align-items:center;gap:10px;justify-content:space-between;padding:12px 14px;background:var(--card);font-weight:700;font-size:14px;color:var(--text);}
  .maphead .addr{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .maphead button{border:none;background:transparent;font-size:22px;color:var(--hint);cursor:pointer;line-height:1;padding:0 4px;}
  #map{flex:1;width:100%;}
</style>
</head>
<body>
<header>
  <div class="hi" id="hi">FloraExpress</div>
  <div class="shift">
    <div class="lbl"><span class="dot off" id="shiftDot"></span><span id="shiftLbl">Не на смене</span></div>
    <button class="switch go" id="shiftBtn">Выйти на смену</button>
  </div>
  <button class="locbtn" id="locBtn">📍 Транслировать геопозицию</button>
  <div class="locstatus" id="locStatus">Клиенты увидят вас на карте, пока трансляция включена.</div>
</header>

<div class="tabs">
  <button class="tab active" data-tab="mine">Мои <span class="badge" id="bMine">0</span></button>
  <button class="tab" data-tab="free">Свободные <span class="badge" id="bFree">0</span></button>
  <button class="tab" data-tab="stats">Статистика</button>
</div>

<main id="view"><div class="empty">Загрузка…</div></main>
<div class="toast" id="toast"></div>

<div class="mapwrap" id="mapwrap">
  <div class="maphead">
    <span class="addr" id="mapAddr">Маршрут</span>
    <button id="mapClose" aria-label="Закрыть">✕</button>
  </div>
  <div id="map"></div>
</div>

<script>
const YMAPS_KEY = <?= json_encode($ymapsKey) ?>;
const tg = window.Telegram?.WebApp;
if (tg) { tg.ready(); tg.expand(); try{ tg.setHeaderColor('secondary_bg_color'); }catch(e){} }

// initData в некоторых клиентах (Telegram Desktop) возвращается нестабильно:
// есть при старте, но пустой при последующих обращениях. Запоминаем первое
// непустое значение и переиспользуем его во всех запросах.
let _initData = (tg && tg.initData) ? tg.initData : "";
function getInitData(){
  const cur = (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initData) || "";
  if (cur) _initData = cur;
  return _initData;
}

let state = { courier:{}, free:[], mine:[], stats:{}, shop:{} };
let tab = "mine";

const esc = s => String(s ?? "").replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmt = n => new Intl.NumberFormat('ru-RU').format(Math.round(Number(n)||0));
const STLABEL = {pending:'Ожидает',assigned:'Назначен',picked:'Забран',en_route:'В пути',delivered:'Доставлен',failed:'Не доставлен'};
const NEXT = {assigned:['picked','📦 Забрал заказ'], picked:['delivered','✅ Доставлен'], en_route:['delivered','✅ Доставлен']};

function toast(t){ const el=document.getElementById('toast'); el.textContent=t; el.classList.add('show');
  setTimeout(()=>el.classList.remove('show'),1800); }

async function api(action, extra={}){
  const id = getInitData();
  const body = JSON.stringify(Object.assign({action, initData:id}, extra));
  for(let attempt=0; attempt<2; attempt++){
    try{
      const r = await fetch('webapp_courier_api.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, keepalive:true, body
      });
      const txt = await r.text();
      try { return JSON.parse(txt); }
      catch(e){ return {ok:false, error:'bad_response', message:'Код '+r.status+': '+txt.slice(0,160)}; }
    }catch(e){
      if(attempt===0){ await new Promise(res=>setTimeout(res, 800)); continue; }
      return {ok:false, error:'network', message:String(e)};
    }
  }
}

async function callPhone(p){
  // В Telegram Mini App (особенно на iOS) прямой звонок по tel: часто блокируется
  // системой. Поэтому копируем номер в буфер обмена и пробуем открыть набор.
  let copied=false;
  try { if(navigator.clipboard && navigator.clipboard.writeText){ await navigator.clipboard.writeText(p); copied=true; } } catch(e){}
  try { const a=document.createElement('a'); a.href='tel:'+p; a.style.display='none'; document.body.appendChild(a); a.click(); setTimeout(()=>a.remove(),200); } catch(e){}
  toast(copied ? ('📋 Номер скопирован: '+p) : ('Телефон получателя: '+p));
}

async function load(){
  if(!getInitData()){
    document.getElementById('view').innerHTML =
      '<div class="empty">Не удалось получить данные входа от Telegram.<br><br>'
      +'Откройте приложение в <b>мобильном Telegram</b> или обновите <b>Telegram Desktop</b> до последней версии.</div>';
    return;
  }
  const res = await api('state');
  if(!res.ok){
    document.getElementById('view').innerHTML =
      '<div class="empty">'+esc(res.message || 'Доступ только для курьеров FloraExpress.')+
      '<br><br><small style="opacity:.55">['+esc(res.error||'')+']</small></div>';
    return;
  }
  state = res; render();
}

function mapLink(d){
  if(d.address_lat && d.address_lon)
    return ' · <a class="maplink" target="_blank" href="https://yandex.ru/maps/?pt='+d.address_lon+','+d.address_lat+'&z=17&l=map">на карте</a>';
  return '';
}

function card(d, isFree){
  const st = d.status;
  const km = (d.distance_km_est!=null) ? (Number(d.distance_km_est).toFixed(1).replace('.',',')+' км') : '—';
  const phone = (d.phone||'').trim();
  const telDigits = phone.replace(/[^\d+]/g,'');
  let buttons = '';
  if(isFree){
    buttons = '<button class="btn take" onclick="doTake('+d.id+')">📦 Взять доставку</button>';
  } else if(NEXT[st]){
    buttons = '<button class="btn next" onclick="doStatus('+d.id+',\''+NEXT[st][0]+'\')">'+NEXT[st][1]+'</button>'
            + '<button class="btn fail" onclick="doStatus('+d.id+',\'failed\')">❌ Не удалось доставить</button>';
  }
  const telBtn = phone ? '<button class="btn tel" onclick="callPhone(\''+esc(telDigits)+'\')">📞 Позвонить получателю</button>' : '';
  const mapBtn = (d.address_lat && d.address_lon) ? '<button class="btn map" onclick="openMap('+d.id+')">🗺 Показать маршрут</button>' : '';
  return `<div class="card">
    <div class="row">
      <span class="id">📦 Доставка #${d.id} · заказ #${d.order_id}</span>
      <span class="badge-st st-${st}">${STLABEL[st]||st}</span>
    </div>
    <div class="line"><span class="k">Получатель:</span> <span class="v">${esc(d.recipient_name||'—')}</span></div>
    ${phone?`<div class="line"><span class="k">Телефон:</span> <span class="v">${esc(phone)}</span></div>`:''}
    <div class="line"><span class="k">Адрес:</span> <span class="v">${esc(d.address||'—')}</span>${mapLink(d)}</div>
    <div class="line"><span class="k">Слот:</span> <span class="v">${esc(d.delivery_slot||'—')}</span></div>
    ${d.comment?`<div class="line"><span class="k">Комментарий:</span> <span class="v">${esc(d.comment)}</span></div>`:''}
    <div class="sep"></div>
    <div class="money">
      <span>Заказ: <b>${fmt(d.total)} ₽</b></span>
      <span class="fee">Вам: ${fmt(d.courier_fee)} ₽</span>
      <span class="k">${km}</span>
    </div>
    ${mapBtn}
    ${telBtn}
    ${buttons}
  </div>`;
}

function render(){
  document.getElementById('hi').textContent = 'Привет, ' + (state.courier.name || 'курьер') + '!';
  const on = !!state.courier.on_shift;
  document.getElementById('shiftDot').className = 'dot ' + (on?'on':'off');
  document.getElementById('shiftLbl').textContent = on ? 'На смене' : 'Не на смене';
  const sb = document.getElementById('shiftBtn');
  sb.textContent = on ? 'Уйти со смены' : 'Выйти на смену';
  sb.className = 'switch ' + (on?'stop':'go');
  document.getElementById('bMine').textContent = state.mine.length;
  document.getElementById('bFree').textContent = state.free.length;

  document.querySelectorAll('.tab').forEach(t=>t.classList.toggle('active', t.dataset.tab===tab));
  const v = document.getElementById('view');

  if(tab==='stats'){
    const s = state.stats||{};
    v.innerHTML = `<div class="stat">
        <div class="box"><div class="num g">${s.delivered_count||0}</div><div class="cap">доставок</div></div>
        <div class="box"><div class="num r">${fmt(s.earned_total)} ₽</div><div class="cap">заработано</div></div>
      </div>
      <div class="stat"><div class="box"><div class="num">${(Number(s.distance_total)||0).toFixed(1).replace('.',',')} км</div><div class="cap">пробег (оценочно)</div></div></div>`;
    return;
  }
  if(tab==='free' && !on){
    v.innerHTML = '<div class="empty">Выйдите на смену, чтобы видеть свободные доставки.</div>'; return;
  }
  const list = tab==='mine' ? state.mine : state.free;
  if(!list.length){
    v.innerHTML = '<div class="empty">'+(tab==='mine'?'Нет активных доставок.':'Свободных доставок пока нет.')+'</div>';
    return;
  }
  v.innerHTML = list.map(d=>card(d, tab==='free')).join('');
}

async function doTake(id){
  const r = await api('take', {delivery_id:id});
  toast(r.message || (r.ok?'Готово':('Ошибка: '+(r.error||'?'))));
  if(r.ok){ tab='mine'; } await load();
}
async function doStatus(id, status){
  if(status==='failed' && !confirm('Отметить доставку как несостоявшуюся?')) return;
  const extra = {delivery_id:id, status};
  if(status==='delivered'){
    // Подтвердить доставку можно только рядом с адресом — берём текущие координаты.
    let c;
    try { c = await getCoords(); }
    catch(e){ toast('Включите доступ к геопозиции, чтобы подтвердить доставку рядом с адресом.'); return; }
    extra.lat = c.lat; extra.lon = c.lon;
  }
  const r = await api('status', extra);
  toast(r.ok ? (STLABEL[status]||'Обновлено') : (r.message || ('Ошибка: '+(r.error||'?'))));
  await load();
}
async function toggleShift(){
  const on = !state.courier.on_shift;
  const r = await api('shift', {on});
  if(r.ok){
    state.courier.on_shift = r.on_shift;
    if(!r.on_shift){ if(tab==='free') tab='mine'; stopSharing(); }
    await load();
  }
  else { toast(r.message || ('Ошибка: '+(r.error||'?'))); }
}

/* ---------- Трансляция геопозиции ---------- */
let sharing=false, locTimer=null;
function setLocStatus(t, err){ const el=document.getElementById('locStatus'); el.textContent=t; el.classList.toggle('err', !!err); }
function updateLocBtn(){ const b=document.getElementById('locBtn');
  b.textContent = sharing ? '⏹ Остановить трансляцию' : '📍 Транслировать геопозицию';
  b.classList.toggle('on', sharing); }

function getCoords(){
  return new Promise((resolve, reject)=>{
    const lm = tg && tg.LocationManager;
    const verOk = tg && tg.isVersionAtLeast && tg.isVersionAtLeast('8.0');
    if(lm && verOk){
      const go = ()=> lm.getLocation(loc=>{
        if(loc && (loc.latitude || loc.longitude)){
          resolve({lat:loc.latitude, lon:loc.longitude, accuracy:loc.horizontal_accuracy,
                   heading:loc.course, speed:(loc.speed!=null?loc.speed*3.6:null)});
        } else {
          reject({code:'denied'});
        }
      });
      if(lm.isInited) go(); else lm.init(go);
      return;
    }
    if(navigator.geolocation){
      navigator.geolocation.getCurrentPosition(
        p=>resolve({lat:p.coords.latitude, lon:p.coords.longitude, accuracy:p.coords.accuracy,
                    heading:p.coords.heading, speed:(p.coords.speed!=null?p.coords.speed*3.6:null)}),
        e=>reject({code:'denied', e}),
        {enableHighAccuracy:true, timeout:12000, maximumAge:5000}
      );
      return;
    }
    reject({code:'no_geo'});
  });
}

async function sendLocation(){
  let c;
  try { c = await getCoords(); }
  catch(err){
    setLocStatus('Нет доступа к геопозиции. Разрешите доступ для Telegram/браузера.', true);
    stopSharing();
    const lm = tg && tg.LocationManager;
    if(lm && lm.openSettings){ try{ lm.openSettings(); }catch(e){} }
    return;
  }
  const r = await api('location', {lat:c.lat, lon:c.lon, accuracy:c.accuracy, heading:c.heading, speed:c.speed});
  if(r.ok){
    const t = new Date().toLocaleTimeString('ru-RU').slice(0,5);
    setLocStatus('📍 Геопозиция передаётся клиентам · обновлено в '+t);
  } else {
    setLocStatus('Ошибка отправки: '+(r.message||r.error||'?'), true);
  }
}

function startSharing(){ sharing=true; updateLocBtn(); setLocStatus('Запрашиваю геопозицию…'); sendLocation(); locTimer=setInterval(sendLocation, 25000); }
function stopSharing(){ if(!sharing && !locTimer){return;} sharing=false; updateLocBtn(); if(locTimer){clearInterval(locTimer); locTimer=null;} setLocStatus('Трансляция остановлена.'); }
function toggleLocation(){ sharing ? stopSharing() : startSharing(); }

/* ---------- Карта с маршрутом (магазин → адрес доставки) ---------- */
let ymapsLoading=null, yMap=null, yRoute=null;
function loadYmaps(){
  if(window.ymaps && window.ymaps.Map) return Promise.resolve();
  if(ymapsLoading) return ymapsLoading;
  ymapsLoading = new Promise((resolve,reject)=>{
    const s=document.createElement('script');
    s.src='https://api-maps.yandex.ru/2.1/?lang=ru_RU'+(YMAPS_KEY?('&apikey='+encodeURIComponent(YMAPS_KEY)):'');
    s.onload=()=>ymaps.ready(resolve);
    s.onerror=()=>reject(new Error('ymaps load failed'));
    document.head.appendChild(s);
  });
  return ymapsLoading;
}
function findDelivery(id){ return [...(state.free||[]),...(state.mine||[])].find(x=>String(x.id)===String(id)); }
async function openMap(id){
  const d = findDelivery(id);
  if(!d || !d.address_lat || !d.address_lon){ toast('Нет координат адреса'); return; }
  document.getElementById('mapAddr').textContent = d.address || 'Маршрут';
  document.getElementById('mapwrap').style.display='flex';
  try{ await loadYmaps(); }catch(e){ toast('Карта недоступна'); closeMap(); return; }
  const shop = (state.shop && state.shop.lat) ? [Number(state.shop.lat), Number(state.shop.lon)] : [56.8389,60.6051];
  const dest = [Number(d.address_lat), Number(d.address_lon)];
  if(!yMap){
    yMap = new ymaps.Map('map', {center:dest, zoom:13, controls:['zoomControl','geolocationControl']});
  } else {
    yMap.container.fitToViewport();
  }
  if(yRoute){ yMap.geoObjects.remove(yRoute); yRoute=null; }
  yRoute = new ymaps.multiRouter.MultiRoute(
    { referencePoints:[shop, dest], params:{routingMode:'auto'} },
    { boundsAutoApply:true, routeActiveStrokeColor:'5e8c5a', routeActiveStrokeWidth:5,
      wayPointStartIconColor:'#5e8c5a', wayPointFinishIconColor:'#c2557a' }
  );
  yMap.geoObjects.add(yRoute);
}
function closeMap(){ document.getElementById('mapwrap').style.display='none'; }

document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{tab=t.dataset.tab;render();}));
document.getElementById('shiftBtn').addEventListener('click', toggleShift);
document.getElementById('locBtn').addEventListener('click', toggleLocation);
document.getElementById('mapClose').addEventListener('click', closeMap);

// На старте initData может прийти с задержкой — даём ему несколько попыток.
let _tries = 0;
(function start(){
  if(!getInitData() && _tries < 6){ _tries++; setTimeout(start, 300); return; }
  load();
})();
setInterval(()=>{ if(document.visibilityState==='visible') load(); }, 30000);
</script>
</body>
</html>
