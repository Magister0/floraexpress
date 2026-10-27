<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/promo_lib.php';

$pdo = db();
promo_ensure_schema($pdo);


$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? ''), ['admin','manager'], true)) {
    header('Location: auth.php?mode=login');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];
$msg = null;
$err = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $err = 'Сессия устарела. Обновите страницу.';
    } else {
        $action = (string)($_POST['action'] ?? 'save');
        try {
            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare('DELETE FROM promocodes WHERE id = ?')->execute([$id]);
                $msg = 'Промокод удалён';
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare('UPDATE promocodes SET active = NOT active WHERE id = ?')->execute([$id]);
                $msg = 'Статус промокода изменён';
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $code = promo_normalize_code((string)($_POST['code'] ?? ''));
                $title = trim((string)($_POST['title'] ?? ''));
                $discountType = (string)($_POST['discount_type'] ?? 'percent');
                $discountType = in_array($discountType, ['percent','fixed'], true) ? $discountType : 'percent';
                $discountValue = max(0, (float)($_POST['discount_value'] ?? 0));
                $minOrder = max(0, (float)($_POST['min_order_sum'] ?? 0));
                $maxDiscountRaw = trim((string)($_POST['max_discount'] ?? ''));
                $maxDiscount = $maxDiscountRaw === '' ? null : max(0, (float)$maxDiscountRaw);
                $deliveryMethod = (string)($_POST['delivery_method'] ?? 'any');
                $deliveryMethod = in_array($deliveryMethod, ['any','delivery','pickup'], true) ? $deliveryMethod : 'any';
                $usageLimitRaw = trim((string)($_POST['usage_limit'] ?? ''));
                $usageLimit = $usageLimitRaw === '' ? null : max(0, (int)$usageLimitRaw);
                $startsAtRaw = trim((string)($_POST['starts_at'] ?? ''));
                $endsAtRaw = trim((string)($_POST['ends_at'] ?? ''));
                $startsAt = $startsAtRaw !== '' ? str_replace('T',' ', $startsAtRaw) . ':00' : null;
                $endsAt = $endsAtRaw !== '' ? str_replace('T',' ', $endsAtRaw) . ':00' : null;
                $active = isset($_POST['active']) ? 'true' : 'false';
                $singleUsePerUser = isset($_POST['single_use_per_user']) ? 'true' : 'false';

                if ($code === '') throw new RuntimeException('Укажите код промокода');
                if ($discountValue <= 0) throw new RuntimeException('Укажите размер скидки');
                if ($discountType === 'percent' && $discountValue > 100) throw new RuntimeException('Процент скидки не может быть больше 100');

                if ($id > 0) {
                    $sql = 'UPDATE promocodes SET code=?, title=?, discount_type=?, discount_value=?, min_order_sum=?, max_discount=?, delivery_method=?, usage_limit=?, starts_at=?, ends_at=?, active=?, single_use_per_user=? WHERE id=?';
                    $pdo->prepare($sql)->execute([$code, $title, $discountType, $discountValue, $minOrder, $maxDiscount, $deliveryMethod, $usageLimit, $startsAt, $endsAt, $active, $singleUsePerUser, $id]);
                    $msg = 'Промокод обновлён';
                    $editId = $id;
                } else {
                    $sql = 'INSERT INTO promocodes (code, title, discount_type, discount_value, min_order_sum, max_discount, delivery_method, usage_limit, starts_at, ends_at, active, single_use_per_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    $pdo->prepare($sql)->execute([$code, $title, $discountType, $discountValue, $minOrder, $maxDiscount, $deliveryMethod, $usageLimit, $startsAt, $endsAt, $active, $singleUsePerUser]);
                    $msg = 'Промокод создан';
                }
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$promos = $pdo->query('SELECT * FROM promocodes ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$editPromo = null;
if ($editId > 0) {
    foreach ($promos as $promo) {
        if ((int)$promo['id'] === $editId) { $editPromo = $promo; break; }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Промокоды — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .promo-wrap{display:grid;grid-template-columns:minmax(360px,.92fr) minmax(0,1.08fr);gap:18px;align-items:start}
    @media (max-width:1100px){.promo-wrap{grid-template-columns:1fr}}
    .promo-box{background:#fff;border:1px solid #e5ece8;border-radius:22px;box-shadow:var(--shadow-1);padding:20px;min-width:0}
    .promo-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    @media (max-width:720px){.promo-form-grid{grid-template-columns:1fr}}
    .promo-label{display:block;font-size:14px;font-weight:700;color:#667085;margin:0 0 6px}
    .promo-checks{display:grid;gap:10px;margin-top:14px}
    .promo-check{display:flex;align-items:center;gap:10px;padding:14px 16px;border:1px solid #dfe8e3;border-radius:16px;background:#fbfdfc;font-weight:700;color:#10233c}
    .promo-check input{width:18px;height:18px;accent-color:#16a34a}
    .promo-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    .promo-list{display:grid;gap:14px}
    .promo-card{border:1px solid #edf1ef;border-radius:18px;padding:16px;background:#fff}
    .promo-card__top{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}
    .promo-code{font-weight:900;font-size:18px;color:#0f172a}
    .promo-title{color:#667085;font-size:14px;margin-top:4px}
    .promo-badges{display:flex;gap:8px;flex-wrap:wrap}
    .promo-badge{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;font-size:13px;font-weight:800;border:1px solid #d7eadf;background:#f4fbf7;color:#185a37;white-space:nowrap}
    .promo-badge.off{background:#fff1f2;border-color:#fecdd3;color:#9f1239}
    .promo-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}
    @media (max-width:760px){.promo-meta{grid-template-columns:1fr}}
    .promo-meta__item{padding:12px 14px;border:1px solid #edf1ef;border-radius:14px;background:#fafcfb}
    .promo-meta__label{display:block;font-size:12px;font-weight:700;color:#667085;margin-bottom:4px}
    .promo-meta__value{font-size:14px;font-weight:700;color:#10233c;line-height:1.5}
    .promo-row-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    .okmsg,.errmsg{padding:10px 12px;border-radius:12px;margin-bottom:12px}
    .okmsg{background:#ecfdf3;border:1px solid #bbf7d0;color:#166534}
    .errmsg{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <h1 class="section__title">Промокоды</h1>
    <p class="section__sub">Создание и управление скидками для доставки и самовывоза.</p>
    <?php if ($msg): ?><div class="okmsg"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="errmsg"><?= h($err) ?></div><?php endif; ?>

    <div class="promo-wrap">
      <div class="promo-box">
        <h2 style="margin:0 0 14px;font-size:22px"><?= $editPromo ? 'Редактировать промокод' : 'Новый промокод' ?></h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)($editPromo['id'] ?? 0) ?>">

          <div class="promo-form-grid">
            <div>
              <label class="promo-label">Код</label>
              <input class="input" name="code" value="<?= h((string)($editPromo['code'] ?? '')) ?>" placeholder="SPRING10" required>
            </div>
            <div>
              <label class="promo-label">Название</label>
              <input class="input" name="title" value="<?= h((string)($editPromo['title'] ?? '')) ?>" placeholder="Весенняя скидка">
            </div>
            <div>
              <label class="promo-label">Тип скидки</label>
              <select class="input" name="discount_type">
                <option value="percent" <?= (($editPromo['discount_type'] ?? 'percent') === 'percent') ? 'selected' : '' ?>>Процент</option>
                <option value="fixed" <?= (($editPromo['discount_type'] ?? '') === 'fixed') ? 'selected' : '' ?>>Фиксированная сумма</option>
              </select>
            </div>
            <div>
              <label class="promo-label">Размер скидки</label>
              <input class="input" type="number" step="0.01" min="0" name="discount_value" value="<?= h((string)($editPromo['discount_value'] ?? '')) ?>" required>
            </div>
            <div>
              <label class="promo-label">Минимальная сумма заказа</label>
              <input class="input" type="number" step="0.01" min="0" name="min_order_sum" value="<?= h((string)($editPromo['min_order_sum'] ?? '0')) ?>">
            </div>
            <div>
              <label class="promo-label">Максимальная скидка</label>
              <input class="input" type="number" step="0.01" min="0" name="max_discount" value="<?= h(($editPromo['max_discount'] ?? null) !== null ? (string)$editPromo['max_discount'] : '') ?>" placeholder="Не ограничивать">
            </div>
            <div>
              <label class="promo-label">Способ получения</label>
              <?php $dm = (string)($editPromo['delivery_method'] ?? 'any'); ?>
              <select class="input" name="delivery_method">
                <option value="any" <?= $dm === 'any' ? 'selected' : '' ?>>Любой способ</option>
                <option value="delivery" <?= $dm === 'delivery' ? 'selected' : '' ?>>Только доставка</option>
                <option value="pickup" <?= $dm === 'pickup' ? 'selected' : '' ?>>Только самовывоз</option>
              </select>
            </div>
            <div>
              <label class="promo-label">Лимит использований</label>
              <input class="input" type="number" min="0" name="usage_limit" value="<?= h(($editPromo['usage_limit'] ?? null) !== null ? (string)$editPromo['usage_limit'] : '') ?>" placeholder="Без лимита">
            </div>
            <div>
              <label class="promo-label">Начало действия</label>
              <input class="input" type="datetime-local" name="starts_at" value="<?= h(!empty($editPromo['starts_at']) ? date('Y-m-d\TH:i', strtotime((string)$editPromo['starts_at'])) : '') ?>">
            </div>
            <div>
              <label class="promo-label">Конец действия</label>
              <input class="input" type="datetime-local" name="ends_at" value="<?= h(!empty($editPromo['ends_at']) ? date('Y-m-d\TH:i', strtotime((string)$editPromo['ends_at'])) : '') ?>">
            </div>
          </div>

          <div class="promo-checks">
            <label class="promo-check"><input type="checkbox" name="active" <?= !empty($editPromo) ? (!empty($editPromo['active']) ? 'checked' : '') : 'checked' ?>> Активен</label>
            <label class="promo-check"><input type="checkbox" name="single_use_per_user" <?= !empty($editPromo['single_use_per_user']) ? 'checked' : '' ?>> Один раз на один аккаунт</label>
          </div>

          <div class="promo-actions">
            <button class="btn" type="submit">Сохранить</button>
            <a class="btn btn--ghost" href="promocodes.php">Очистить форму</a>
          </div>
        </form>
      </div>

      <div class="promo-box">
        <h2 style="margin:0 0 14px;font-size:22px">Список промокодов</h2>
        <?php if (!$promos): ?>
          <p class="mute" style="margin:0">Пока нет ни одного промокода.</p>
        <?php else: ?>
          <div class="promo-list">
            <?php foreach ($promos as $promo): ?>
              <article class="promo-card">
                <div class="promo-card__top">
                  <div>
                    <div class="promo-code"><?= h((string)$promo['code']) ?></div>
                    <div class="promo-title"><?= h((string)($promo['title'] ?: 'Без названия')) ?></div>
                  </div>
                  <div class="promo-badges">
                    <span class="promo-badge<?= !empty($promo['active']) ? '' : ' off' ?>"><?= !empty($promo['active']) ? 'Активен' : 'Выключен' ?></span>
                    <span class="promo-badge"><?= h(promo_type_label((string)$promo['discount_type'], (float)$promo['discount_value'])) ?></span>
                    <?php if (!empty($promo['single_use_per_user'])): ?><span class="promo-badge">1 раз на аккаунт</span><?php endif; ?>
                  </div>
                </div>

                <div class="promo-meta">
                  <div class="promo-meta__item"><span class="promo-meta__label">Условия</span><div class="promo-meta__value">Мин. сумма: <?= number_format((float)($promo['min_order_sum'] ?? 0), 0, ',', ' ') ?> ₽</div></div>
                  <div class="promo-meta__item"><span class="promo-meta__label">Способ</span><div class="promo-meta__value"><?= h(promo_delivery_label((string)($promo['delivery_method'] ?? 'any'))) ?></div></div>
                  <div class="promo-meta__item"><span class="promo-meta__label">Использовано</span><div class="promo-meta__value"><?= (int)($promo['used_count'] ?? 0) ?><?= ($promo['usage_limit'] ?? null) !== null && (int)$promo['usage_limit'] > 0 ? ' из ' . (int)$promo['usage_limit'] : '' ?></div></div>
                  <div class="promo-meta__item"><span class="promo-meta__label">Период</span><div class="promo-meta__value"><?= !empty($promo['starts_at']) ? 'С ' . date('d.m.Y H:i', strtotime((string)$promo['starts_at'])) : 'Без даты начала' ?><br><?= !empty($promo['ends_at']) ? 'До ' . date('d.m.Y H:i', strtotime((string)$promo['ends_at'])) : 'Без даты конца' ?></div></div>
                </div>

                <div class="promo-row-actions">
                  <a class="btn btn--ghost btn--sm" href="promocodes.php?edit=<?= (int)$promo['id'] ?>">Изменить</a>
                  <form method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$promo['id'] ?>">
                    <button class="btn btn--ghost btn--sm" type="submit"><?= !empty($promo['active']) ? 'Выключить' : 'Включить' ?></button>
                  </form>
                  <form method="post" style="margin:0" onsubmit="return confirm('Удалить промокод?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$promo['id'] ?>">
                    <button class="btn btn--ghost btn--sm" type="submit">Удалить</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
