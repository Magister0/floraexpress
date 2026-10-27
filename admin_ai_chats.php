<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ai_advisor_lib.php';

$pdo = db();
ai_advisor_ensure_schema($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('admin_ai_chats.php'));
    exit;
}
$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['admin','manager'], true)) {
    http_response_code(403);
    echo '403';
    exit;
}

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$chatId = (int)($_GET['chat'] ?? 0);

// Общая статистика
$stats = $pdo->query("
    SELECT
        COUNT(DISTINCT c.id)::int                                   AS chats_total,
        COUNT(m.id)::int                                            AS messages_total,
        COUNT(m.id) FILTER (WHERE m.role = 'user')::int             AS user_messages,
        COUNT(m.id) FILTER (WHERE m.role = 'assistant')::int        AS bot_messages,
        COUNT(m.id) FILTER (WHERE m.fallback = TRUE)::int           AS fallback_count,
        COALESCE(SUM(m.tokens_used), 0)::int                        AS tokens_total,
        COUNT(DISTINCT c.user_id) FILTER (WHERE c.user_id IS NOT NULL)::int AS unique_users
    FROM ai_chats c
    LEFT JOIN ai_messages m ON m.chat_id = c.id
")->fetch(PDO::FETCH_ASSOC) ?: [];

$chats = $pdo->query("
    SELECT
        c.id, c.user_id, c.session_token, c.created_at, c.updated_at,
        u.name AS user_name, u.email AS user_email,
        COUNT(m.id)::int AS msg_count,
        MAX(m.created_at) AS last_msg_at,
        BOOL_OR(m.fallback) AS had_fallback
    FROM ai_chats c
    LEFT JOIN ai_messages m ON m.chat_id = c.id
    LEFT JOIN users u ON u.id = c.user_id
    GROUP BY c.id, u.name, u.email
    ORDER BY c.updated_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedChat = null;
$messages = [];
if ($chatId > 0) {
    $st = $pdo->prepare("SELECT c.*, u.name AS user_name, u.email AS user_email
                         FROM ai_chats c LEFT JOIN users u ON u.id = c.user_id
                         WHERE c.id = ? LIMIT 1");
    $st->execute([$chatId]);
    $selectedChat = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedChat) {
        $messages = ai_chat_history($pdo, $chatId, 200);
        // Подтянем продукты
        $allIds = [];
        foreach ($messages as $m) {
            $rec = $m['recommended_product_ids'] ? (json_decode((string)$m['recommended_product_ids'], true) ?: []) : [];
            foreach ($rec as $rid) $allIds[(int)$rid] = true;
        }
        $productsMap = [];
        if ($allIds) {
            $ids = array_keys($allIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stP = $pdo->prepare("SELECT id, name, price FROM products WHERE id IN ($in)");
            $stP->execute($ids);
            foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $p) $productsMap[(int)$p['id']] = $p;
        }
        foreach ($messages as &$m) {
            $rec = $m['recommended_product_ids'] ? (json_decode((string)$m['recommended_product_ids'], true) ?: []) : [];
            $m['products'] = [];
            foreach ($rec as $rid) {
                if (isset($productsMap[(int)$rid])) $m['products'][] = $productsMap[(int)$rid];
            }
        }
        unset($m);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI-диалоги — Админка FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .ac-wrap{max-width:1280px;margin:24px auto;padding:0 16px;display:grid;gap:18px}
    .ac-card{background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:20px;box-shadow:var(--shadow-1)}
    .ac-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
    .ac-stat{padding:14px;border-radius:14px;background:#f9fbfa;border:1px solid #e7ece8}
    .ac-stat__label{font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;font-weight:700}
    .ac-stat__value{font-size:22px;font-weight:900;color:#175a37;margin-top:4px}
    .ac-grid{display:grid;grid-template-columns:380px 1fr;gap:18px;align-items:start}
    @media (max-width:900px){.ac-grid{grid-template-columns:1fr}}
    .ac-list{max-height:760px;overflow-y:auto}
    .ac-item{display:block;padding:12px;border-radius:12px;text-decoration:none;color:#111827;border:1px solid #eef2f7;margin-bottom:8px;transition:.15s}
    .ac-item:hover{border-color:#175a37;background:#f9fbfa}
    .ac-item.is-active{border-color:#175a37;background:#eef6f1}
    .ac-item__title{font-weight:700;font-size:14px}
    .ac-item__meta{font-size:12px;color:#6b7280;margin-top:3px}
    .ac-fallback-tag{display:inline-block;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:999px;font-size:10px;font-weight:700;margin-left:4px}
    .ac-msg{margin-bottom:14px}
    .ac-msg__head{font-size:11.5px;color:#6b7280;margin-bottom:4px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
    .ac-msg__body{padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
    .ac-msg--user .ac-msg__body{background:#175a37;color:#fff;margin-left:30px}
    .ac-msg--bot .ac-msg__body{background:#f9fbfa;border:1px solid #e7ece8;margin-right:30px}
    .ac-msg__products{margin-top:8px;display:flex;flex-wrap:wrap;gap:5px;margin-right:30px}
    .ac-prod-pill{font-size:12px;background:#fff;border:1px solid #e7ece8;border-radius:999px;padding:3px 10px;text-decoration:none;color:#175a37;font-weight:600}
    .ac-prod-pill:hover{border-color:#175a37}
    .ac-mini{font-size:11px;color:#94a3b8}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="ac-wrap">
  <div class="ac-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h1 style="margin:0;font-size:24px">AI-консультант: диалоги</h1>
      <a class="btn btn--ghost" href="admin_orders.php">К заказам</a>
    </div>
    <div class="ac-stats" style="margin-top:14px">
      <div class="ac-stat"><div class="ac-stat__label">Диалогов всего</div><div class="ac-stat__value"><?= (int)($stats['chats_total'] ?? 0) ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Сообщений</div><div class="ac-stat__value"><?= (int)($stats['messages_total'] ?? 0) ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Запросов пользователей</div><div class="ac-stat__value"><?= (int)($stats['user_messages'] ?? 0) ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Ответов AI</div><div class="ac-stat__value"><?= (int)($stats['bot_messages'] ?? 0) ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Через правила (fallback)</div><div class="ac-stat__value"><?= (int)($stats['fallback_count'] ?? 0) ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Токенов потрачено</div><div class="ac-stat__value"><?= number_format((int)($stats['tokens_total'] ?? 0), 0, ',', ' ') ?></div></div>
      <div class="ac-stat"><div class="ac-stat__label">Уникальных юзеров</div><div class="ac-stat__value"><?= (int)($stats['unique_users'] ?? 0) ?></div></div>
    </div>
  </div>

  <div class="ac-grid">
    <div class="ac-card">
      <h2 style="margin:0 0 12px;font-size:17px">Диалоги (<?= count($chats) ?>)</h2>
      <div class="ac-list">
        <?php if (!$chats): ?>
          <div class="mute">Пока никто не общался с консультантом.</div>
        <?php endif; ?>
        <?php foreach ($chats as $c): ?>
          <a class="ac-item <?= (int)$c['id'] === $chatId ? 'is-active' : '' ?>" href="admin_ai_chats.php?chat=<?= (int)$c['id'] ?>">
            <div class="ac-item__title">
              <?php if (!empty($c['user_name'])): ?>
                <?= $h($c['user_name']) ?>
              <?php elseif (!empty($c['user_email'])): ?>
                <?= $h($c['user_email']) ?>
              <?php else: ?>
                Гость · <?= $h(substr((string)$c['session_token'], 0, 8)) ?>…
              <?php endif; ?>
              <?php if (!empty($c['had_fallback'])): ?>
                <span class="ac-fallback-tag">был fallback</span>
              <?php endif; ?>
            </div>
            <div class="ac-item__meta">
              <?= (int)$c['msg_count'] ?> сообщений ·
              <?= $h(substr((string)$c['updated_at'], 0, 16)) ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="ac-card">
      <?php if (!$selectedChat): ?>
        <p class="mute">Выберите диалог слева, чтобы посмотреть переписку.</p>
      <?php else: ?>
        <h2 style="margin:0 0 6px;font-size:17px">
          Диалог #<?= (int)$selectedChat['id'] ?>
          <?php if (!empty($selectedChat['user_name'])): ?>
            · <?= $h($selectedChat['user_name']) ?>
          <?php endif; ?>
        </h2>
        <div class="ac-mini" style="margin-bottom:18px">
          Сессия: <code><?= $h(substr((string)$selectedChat['session_token'], 0, 16)) ?>…</code>
          · Создан: <?= $h(substr((string)$selectedChat['created_at'], 0, 16)) ?>
        </div>

        <?php foreach ($messages as $m):
          $isUser = $m['role'] === 'user';
        ?>
          <div class="ac-msg ac-msg--<?= $isUser ? 'user' : 'bot' ?>">
            <div class="ac-msg__head"><?= $isUser ? 'Пользователь' : 'AI' ?> · <?= $h(substr((string)$m['created_at'], 0, 16)) ?><?php if (!$isUser && !empty($m['fallback'])): ?> · <span style="color:#92400e">fallback</span><?php endif; ?></div>
            <div class="ac-msg__body"><?= $h($m['content']) ?></div>
            <?php if (!empty($m['products'])): ?>
              <div class="ac-msg__products">
                <?php foreach ($m['products'] as $p): ?>
                  <a class="ac-prod-pill" href="product.php?id=<?= (int)$p['id'] ?>" target="_blank">#<?= (int)$p['id'] ?> <?= $h($p['name']) ?> · <?= number_format((float)$p['price'], 0, ',', ' ') ?> ₽</a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
