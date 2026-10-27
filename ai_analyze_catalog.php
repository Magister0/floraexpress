<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/product_ai_tagging_lib.php';
require_once __DIR__ . '/audit_log_lib.php';

$pdo = db();
product_ai_tagging_ensure_schema($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? ''), ['admin','manager'], true)) {
    http_response_code(403);
    echo 'Доступ только для администраторов.';
    exit;
}

// AJAX-режим: ?ajax=1&id=N&kind=product|extra → анализирует одну позицию
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $kind = (string)($_GET['kind'] ?? 'product');
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'no_id']);
        exit;
    }

    if ($kind === 'extra') {
        $st = $pdo->prepare("SELECT id, name, price, COALESCE(description,'') AS description FROM extra_services WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'service_not_found']); exit; }

        $res = extra_service_ai_analyze_one($pdo, $row);
        if (!$res['ok']) { echo json_encode($res); exit; }

        extra_service_ai_metadata_save($pdo, $id, $res['data'], $res['model'] ?? null, false);
        audit_log_user_action($pdo, 'extra_service_ai_analyze', 'extra_service', $id, ['tokens' => $res['tokens'] ?? 0]);

        echo json_encode([
            'ok' => true, 'id' => $id, 'kind' => 'extra',
            'name' => $row['name'], 'data' => $res['data'], 'tokens' => $res['tokens'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare("SELECT id, name, price, COALESCE(category,'') AS category, COALESCE(description,'') AS description FROM products WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        echo json_encode(['ok' => false, 'error' => 'product_not_found']);
        exit;
    }

    $res = product_ai_analyze_one($pdo, $product);
    if (!$res['ok']) {
        echo json_encode($res);
        exit;
    }

    product_ai_metadata_save($pdo, $id, $res['data'], $res['model'] ?? null, false);
    audit_log_user_action($pdo, 'product_ai_analyze', 'product', $id, ['tokens' => $res['tokens'] ?? 0]);

    echo json_encode([
        'ok' => true, 'id' => $id, 'kind' => 'product',
        'name' => $product['name'], 'data' => $res['data'], 'tokens' => $res['tokens'] ?? 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Очередь товаров и доп. услуг на анализ
$unanalyzed = product_ai_unanalyzed_products($pdo, 0);
$unanalyzedExtras = extra_service_ai_unanalyzed($pdo);
$stats = product_ai_stats($pdo);
$extraStats = extra_service_ai_stats($pdo);

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI-анализ каталога — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .ac-wrap{max-width:1100px;margin:24px auto;padding:0 16px;display:grid;gap:18px}
    .ac-card{background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:22px;box-shadow:var(--shadow-1)}
    .ac-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px}
    .ac-stat{padding:14px;border-radius:14px;background:#f9fbfa;border:1px solid #e7ece8}
    .ac-stat__label{font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;font-weight:700}
    .ac-stat__value{font-size:22px;font-weight:900;color:#175a37;margin-top:4px}
    .progress{height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:10px}
    .progress-bar{height:100%;background:linear-gradient(90deg,#175a37,#16a34a);width:0%;transition:width .4s}
    .ac-log{max-height:340px;overflow-y:auto;background:#fafbfa;border:1px solid #e7ece8;border-radius:12px;padding:12px;margin-top:14px;font-family:'SF Mono',Consolas,monospace;font-size:12px;line-height:1.55;display:none}
    .ac-log.is-active{display:block}
    .ac-log .ok{color:#166534}
    .ac-log .err{color:#9f1239}
    .ac-log .info{color:#1d4ed8}
    .ac-q{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .ac-q .btn{flex:0 0 auto}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="ac-wrap">
  <div class="ac-card">
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;align-items:center">
      <div>
        <h1 style="margin:0 0 4px;font-size:24px">AI-анализ каталога</h1>
        <p class="mute" style="margin:0">Прогоняет каждый товар через YandexGPT и сохраняет его «паспорт»: для каких поводов подходит, кому дарить, какое настроение.</p>
      </div>
      <a class="btn btn--ghost" href="admin_orders.php">К заказам</a>
    </div>

    <div class="ac-stats" style="margin-top:18px">
      <div class="ac-stat"><div class="ac-stat__label">Всего товаров</div><div class="ac-stat__value"><?= (int)$stats['total_products'] ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Проанализировано</div><div class="ac-stat__value" id="acDone"><?= (int)$stats['analyzed'] ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Ждут анализа</div><div class="ac-stat__value" id="acPending"><?= (int)$stats['unanalyzed'] ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Покрытие</div><div class="ac-stat__value" id="acCoverage"><?= number_format((float)$stats['coverage_percent'], 1) ?>%</div></div>
      <div class="ac-stat"><div class="ac-stat__label">Поправлено вручную</div><div class="ac-stat__value"><?= (int)$stats['manually_edited'] ?></div></div>
    </div>

    <div class="progress"><div class="progress-bar" id="acProgress" style="width:<?= (float)$stats['coverage_percent'] ?>%"></div></div>

    <?php if (!ai_advisor_yandex_available()): ?>
      <div class="notice notice--err" style="margin-top:14px;padding:10px 14px;border-radius:12px;background:#fff1f2;border:1px solid #fecdd3;color:#9f1239">
        YandexGPT API не подключен. Заполни <code>_YANDEX_GPT_API_KEY</code> и <code>_YANDEX_GPT_FOLDER_ID</code> в <code>config.local.php</code>.
      </div>
    <?php else: ?>
      <div class="ac-q">
        <button class="btn" id="acAnalyzeAll" type="button">▶️ Проанализировать всё (<?= count($unanalyzed) ?>)</button>
        <button class="btn btn--ghost" id="acStop" type="button" disabled>⏹ Стоп</button>
        <a class="btn btn--ghost" href="admin_ai_catalog.php">Посмотреть результаты</a>
      </div>
    <?php endif; ?>

    <div class="ac-log" id="acLog"></div>
  </div>

  <div class="ac-card">
    <h2 style="margin:0 0 10px;font-size:18px">Что делает скрипт</h2>
    <ol style="margin:0;padding-left:20px;line-height:1.7;color:#374151">
      <li>Берёт по одному товару из списка не проанализированных.</li>
      <li>Отправляет в YandexGPT с промптом «ты флорист-эксперт, проанализируй товар».</li>
      <li>Модель учитывает культурный контекст (белые хризантемы — траур, жёлтые гвоздики — отказ и т.д.) и язык цветов из БД.</li>
      <li>Возвращает структурированный JSON: поводы, получатели, настроения, бюджетный класс.</li>
      <li>Сохраняется в <code>product_ai_metadata</code>. После этого подбор в чате идёт по этим тегам.</li>
    </ol>
    <p class="mute" style="margin:14px 0 0">На каталог из 100 товаров уходит примерно 70 000 токенов (≈7% месячного бесплатного лимита) и 5-10 минут реального времени.</p>
  </div>
</div>

<script>
(function(){
  const productData = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'kind' => 'product'], $unanalyzed), JSON_UNESCAPED_UNICODE) ?>;
  const total = <?= (int)$stats['total_products'] ?>;
  let alreadyDone = <?= (int)$stats['analyzed'] ?>;
  let queue = [];
  let running = false;
  let aborted = false;

  const log = document.getElementById('acLog');
  const btnGo = document.getElementById('acAnalyzeAll');
  const btnStop = document.getElementById('acStop');
  const doneEl = document.getElementById('acDone');
  const pendingEl = document.getElementById('acPending');
  const coverageEl = document.getElementById('acCoverage');
  const progressBar = document.getElementById('acProgress');

  function addLog(msg, cls){
    if (!log.classList.contains('is-active')) log.classList.add('is-active');
    const line = document.createElement('div');
    line.className = cls || '';
    line.textContent = msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
  }

  function updateProgress(){
    if (doneEl) doneEl.textContent = alreadyDone;
    if (pendingEl) pendingEl.textContent = queue.length;
    const pct = total > 0 ? (alreadyDone / total * 100) : 0;
    if (coverageEl) coverageEl.textContent = pct.toFixed(1) + '%';
    if (progressBar) progressBar.style.width = pct.toFixed(1) + '%';
  }

  async function processOne(item){
    const kindLabel = item.kind === 'extra' ? '🎈' : '🌷';
    addLog(`${kindLabel} [${item.id}] ${item.name} … анализ`, 'info');
    try {
      const res = await fetch('ai_analyze_catalog.php?ajax=1&id=' + item.id + '&kind=' + item.kind, {credentials: 'same-origin'});
      const data = await res.json();
      if (data.ok) {
        const occ = (data.data.occasions || []).join(', ') || '—';
        const avoid = (data.data.avoid_occasions || []).join(', ') || '—';
        addLog(`✓ ${item.name} → поводы: ${occ}${avoid !== '—' ? ' · избегать: ' + avoid : ''} · ${data.tokens} ток.`, 'ok');
        if (item.kind === 'product') {
          alreadyDone++;
          updateProgress();
        }
      } else {
        addLog(`✗ ${item.name} → ${data.error || 'ошибка'}`, 'err');
      }
    } catch (e) {
      addLog(`✗ ${item.name} → сетевая ошибка: ${e.message}`, 'err');
    }
  }

  async function runQueue(){
    if (running) return;
    if (!queue.length) {
      addLog('Очередь пустая.', 'ok');
      return;
    }
    running = true;
    aborted = false;
    if (btnGo) btnGo.disabled = true;
    btnStop.disabled = false;
    addLog('=== Старт анализа ===', 'info');

    while (queue.length && !aborted) {
      const item = queue.shift();
      await processOne(item);
      await new Promise(r => setTimeout(r, 400));
    }

    addLog(aborted ? '=== Остановлено пользователем ===' : '=== Готово! ===', aborted ? 'err' : 'ok');
    if (btnGo) btnGo.disabled = false;
    btnStop.disabled = true;
    running = false;
  }

  if (btnGo)   btnGo.addEventListener('click',   () => { queue = productData.slice(); runQueue(); });
  if (btnStop) btnStop.addEventListener('click', () => { aborted = true; });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
