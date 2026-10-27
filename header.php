<?php
require_once __DIR__ . '/helpers.php';
flora_start_session();

if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
}
if (file_exists(__DIR__ . '/cart_store.php')) {
    require_once __DIR__ . '/cart_store.php';
    cart_store_sync();
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$user = $_SESSION['user'] ?? null;
$role = (string)($user['role'] ?? 'guest');
$cartCount = function_exists('cart_store_count') ? cart_store_count() : array_sum($_SESSION['cart'] ?? []);

$canManage = in_array($role, ['admin', 'manager'], true);
$isStaff = in_array($role, ['admin', 'manager', 'courier'], true);
$current = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$logoutHref = file_exists(__DIR__ . '/logout.php') ? 'logout.php' : 'auth.php?logout=1';
$favoritesHref = 'account_favorites.php';
$favoritesCount = 0;
if ($user && file_exists(__DIR__ . '/favorites_lib.php')) {
    require_once __DIR__ . '/favorites_lib.php';
    if (function_exists('favorites_init') && function_exists('favorites_count') && function_exists('db')) {
        try {
            $pdoFavHeader = db();
            favorites_init($pdoFavHeader);
            $favoritesCount = favorites_count($pdoFavHeader, (int)($user['id'] ?? 0));
        } catch (Throwable $e) {
            $favoritesCount = 0;
        }
    }
}

$mainNav = [
    ['href' => 'catalog.php', 'label' => 'Каталог'],
    ['href' => 'sales.php',   'label' => 'Акции'],
    ['href' => 'contacts.php', 'label' => 'Контакты'],
];

$careNav = [
    ['href' => 'care.php',          'label' => 'Уход за цветами'],
    ['href' => 'care_balloons.php', 'label' => 'Уход за шарами'],
];
$careActive = in_array($current, ['care.php', 'care_balloons.php'], true);

$manageNav = [];
if ($canManage) {
    $manageNav[] = ['href' => 'products.php', 'label' => 'Товары'];
    $manageNav[] = ['href' => 'extra_services.php', 'label' => 'Доп. услуги'];
    $manageNav[] = ['href' => 'categories.php', 'label' => 'Категории'];
    $manageNav[] = ['href' => 'promocodes.php', 'label' => 'Промокоды'];
    $manageNav[] = ['href' => 'admin_pricing_rules.php', 'label' => 'Цены'];
    $manageNav[] = ['href' => 'admin_delivery_zones.php', 'label' => 'Доставка'];
    $manageNav[] = ['href' => 'admin_ai_catalog.php', 'label' => 'AI-теги'];
    $manageNav[] = ['href' => 'admin_ai_chats.php', 'label' => 'AI-диалоги'];
    $manageNav[] = ['href' => 'admin_orders.php', 'label' => 'Заказы'];
    $manageNav[] = ['href' => 'admin_reports.php', 'label' => 'Отчётность'];
    if (file_exists(__DIR__ . '/admin.php')) {
        $manageNav[] = ['href' => 'admin.php', 'label' => 'Пользователи'];
    }
}
if ($role === 'courier' && file_exists(__DIR__ . '/courier_panel.php')) {
    $manageNav[] = ['href' => 'courier_panel.php', 'label' => 'Панель курьера'];
}

$roleLabel = match ($role) {
    'admin'   => 'Админ',
    'manager' => 'Менеджер',
    'courier' => 'Курьер',
    default   => '',
};

$userName = trim((string)($user['name'] ?? 'Профиль'));
$manageActive = in_array($current, ['extra_service_form.php'], true);
foreach ($manageNav as $m) {
    if ($current === basename($m['href'])) {
        $manageActive = true;
        break;
    }
}
$favoritesActive = in_array($current, ['account_favorites.php'], true);
?>
<header class="fe-header">
  <div class="container fe-header__inner">
    <a class="fe-brand" href="index.php" aria-label="FloraExpress">
      <span class="fe-brand__logo">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="42" height="42" style="display:block">
          <rect width="64" height="64" rx="14" fill="#0f6b45"/>
          <ellipse cx="32" cy="20" rx="5" ry="9" fill="#fff" opacity=".95"/>
          <ellipse cx="32" cy="20" rx="5" ry="9" fill="#fff" opacity=".95" transform="rotate(60 32 32)"/>
          <ellipse cx="32" cy="20" rx="5" ry="9" fill="#fff" opacity=".95" transform="rotate(120 32 32)"/>
          <ellipse cx="32" cy="20" rx="5" ry="9" fill="#fff" opacity=".95" transform="rotate(180 32 32)"/>
          <ellipse cx="32" cy="20" rx="5" ry="9" fill="#fff" opacity=".95" transform="rotate(240 32 32)"/>
          <ellipse cx="32" cy="20" rx="5" ry="9" fill="#fff" opacity=".95" transform="rotate(300 32 32)"/>
          <circle cx="32" cy="32" r="7" fill="#f9d74b"/>
        </svg>
      </span>
      <span class="fe-brand__text">FloraExpress</span>
    </a>

    <nav class="fe-nav" aria-label="Основная навигация">
      <?php foreach ($mainNav as $item): ?>
        <?php $isActive = ($current === basename($item['href'])); ?>
        <a class="fe-nav__link<?= $isActive ? ' is-active' : '' ?>" href="<?= $h($item['href']) ?>">
          <?= $h($item['label']) ?>
        </a>
      <?php endforeach; ?>

      <div class="fe-menu<?= $careActive ? ' is-active' : '' ?>" data-hover-menu>
        <button
          type="button"
          class="fe-menu__summary<?= $careActive ? ' is-active' : '' ?>"
          aria-haspopup="true"
          aria-expanded="false"
        >
          Уход
          <span class="fe-menu__arrow">▾</span>
        </button>
        <div class="fe-menu__dropdown" role="menu" aria-label="Уход">
          <?php foreach ($careNav as $item): ?>
            <a class="fe-menu__item<?= $current === basename($item['href']) ? ' is-active' : '' ?>" role="menuitem" href="<?= $h($item['href']) ?>">
              <?= $h($item['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($canManage): ?>
        <a class="fe-nav__link<?= ($manageActive || $current === 'admin_panel.php') ? ' is-active' : '' ?>" href="admin_panel.php">
          ⚙ Админка
        </a>
      <?php endif; ?>
      <?php if ($role === 'courier' && file_exists(__DIR__ . '/courier_panel.php')): ?>
        <a class="fe-nav__link<?= $current === 'courier_panel.php' ? ' is-active' : '' ?>" href="courier_panel.php">
          Панель курьера
        </a>
      <?php endif; ?>
    </nav>

    <div class="fe-header__actions">
      <?php if ($user): ?>
        <a class="fe-user-badge" href="account.php" title="Открыть личный кабинет">
          <?php if ($isStaff): ?>
            <span class="fe-user-badge__role"><?= $h($roleLabel) ?></span>
          <?php endif; ?>
          <span class="fe-user-badge__name"><?= $h($userName) ?></span>
        </a>

        <?php if ($isStaff): ?>
          <a class="fe-notif-btn" href="admin_orders.php?status=new" id="feNotifBell" title="Новые заказы" aria-label="Новые заказы">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span class="fe-notif-badge" id="feNotifBadge" hidden>0</span>
          </a>
        <?php endif; ?>
        <a class="fe-fav-link<?= ($favoritesActive || $favoritesCount > 0) ? ' is-active' : '' ?>" href="<?= $h($favoritesHref) ?>" title="Избранное" aria-label="Избранное">
          <span class="fe-fav-link__icon"><?= ($favoritesActive || $favoritesCount > 0) ? '♥' : '♡' ?></span>
          <span class="fe-fav-link__count" id="favCount"><?= (int)$favoritesCount ?></span>
        </a>

        <a class="fe-btn fe-btn--cart" href="cart.php">
          Корзина <span class="fe-btn__count" id="cartCount"><?= (int)$cartCount ?></span>
        </a>

        <a class="fe-btn fe-btn--ghost" href="<?= $h($logoutHref) ?>">Выйти</a>
      <?php else: ?>
        <a class="fe-fav-link" href="auth.php?mode=login" title="Избранное" aria-label="Избранное">
          <span class="fe-fav-link__icon">♡</span>
        </a>
        <a class="fe-btn fe-btn--cart" href="cart.php">
          Корзина <span class="fe-btn__count" id="cartCount"><?= (int)$cartCount ?></span>
        </a>
        <a class="fe-btn fe-btn--ghost" href="auth.php?mode=login">Войти</a>
      <?php endif; ?>
    </div>

    <!-- Мобильная панель: корзина + бургер (только на маленьких экранах) -->
    <div class="fe-mobile-actions">
      <a class="fe-btn fe-btn--cart" href="cart.php" style="padding:0 14px;min-height:42px;font-size:14px">
        Корзина&nbsp;<span id="cartCountMob"><?= (int)$cartCount ?></span>
      </a>
      <button class="fe-burger" id="feBurger" aria-expanded="false" aria-label="Открыть меню">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>

  <!-- Выдвижное мобильное меню -->
  <div class="fe-mobile-panel" id="feMobilePanel">
    <div class="container">
      <nav class="fe-mobile-panel__nav">
        <?php foreach ($mainNav as $item): ?>
          <a class="fe-nav__link<?= ($current === basename($item['href'])) ? ' is-active' : '' ?>" href="<?= $h($item['href']) ?>">
            <?= $h($item['label']) ?>
          </a>
        <?php endforeach; ?>
        <div class="fe-mobile-panel__divider"></div>
        <div style="font-size:12px;font-weight:700;color:#9ca3af;padding:4px 12px;text-transform:uppercase;letter-spacing:.06em">Уход</div>
        <?php foreach ($careNav as $item): ?>
          <a class="fe-nav__link<?= ($current === basename($item['href'])) ? ' is-active' : '' ?>" href="<?= $h($item['href']) ?>">
            <?= $h($item['label']) ?>
          </a>
        <?php endforeach; ?>
        <?php if ($manageNav): ?>
          <div class="fe-mobile-panel__divider"></div>
          <div style="font-size:12px;font-weight:700;color:#9ca3af;padding:4px 12px;text-transform:uppercase;letter-spacing:.06em">Управление</div>
          <?php foreach ($manageNav as $item): ?>
            <a class="fe-nav__link<?= ($current === basename($item['href'])) ? ' is-active' : '' ?>" href="<?= $h($item['href']) ?>">
              <?= $h($item['label']) ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </nav>
      <div class="fe-mobile-panel__divider"></div>
      <div class="fe-mobile-panel__actions">
        <?php if ($user): ?>
          <a class="fe-btn fe-btn--ghost" href="account.php" style="justify-content:center"><?= $h($userName) ?></a>
          <a class="fe-fav-link" href="<?= $h($favoritesHref) ?>" style="justify-content:center;width:100%;gap:8px">
            <span class="fe-fav-link__icon"><?= ($favoritesCount > 0) ? '♥' : '♡' ?></span>
            <span style="font-weight:700">Избранное<?= $favoritesCount > 0 ? ' (' . (int)$favoritesCount . ')' : '' ?></span>
          </a>
          <a class="fe-btn fe-btn--ghost" href="<?= $h($logoutHref) ?>" style="justify-content:center">Выйти</a>
        <?php else: ?>
          <a class="fe-fav-link" href="auth.php?mode=login" style="justify-content:center;width:100%;gap:8px">
            <span class="fe-fav-link__icon">♡</span>
            <span style="font-weight:700">Избранное</span>
          </a>
          <a class="fe-btn fe-btn--ghost" href="auth.php?mode=login" style="justify-content:center">Войти</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Уведомления о новых заказах для персонала
  var notifBadge = document.getElementById('feNotifBadge');
  var notifBell  = document.getElementById('feNotifBell');
  if (notifBadge && notifBell) {
    function showBadge(cnt) {
      notifBadge.textContent = cnt > 99 ? '99+' : cnt;
      notifBadge.hidden = false;
      notifBadge.style.display = 'inline-flex';
    }
    function hideBadge() {
      notifBadge.hidden = true;
      notifBadge.style.display = 'none';
    }
    // По умолчанию скрыт сразу при старте скрипта, до первого ответа API
    hideBadge();

    function fetchNotifCount() {
      fetch('notifications_api.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
          var cnt = parseInt(data.count || 0, 10);
          if (cnt > 0) showBadge(cnt);
          else hideBadge();
        })
        .catch(function(){});
    }
    fetchNotifCount();
    setInterval(fetchNotifCount, 30000);

    notifBell.addEventListener('click', function() {
      fetch('notifications_api.php?mark_seen=1').catch(function(){});
      hideBadge();
    });
  }

  document.querySelectorAll('[data-hover-menu]').forEach(function (menu) {
    const summary = menu.querySelector('.fe-menu__summary');
    let timer = null;

    const openMenu = function () {
      clearTimeout(timer);
      menu.classList.add('is-open');
      if (summary) summary.setAttribute('aria-expanded', 'true');
    };

    const closeMenu = function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        if (!menu.matches(':focus-within')) {
          menu.classList.remove('is-open');
          if (summary) summary.setAttribute('aria-expanded', 'false');
        }
      }, 180);
    };

    menu.addEventListener('mouseenter', openMenu);
    menu.addEventListener('mouseleave', closeMenu);
    menu.addEventListener('focusin', openMenu);
    menu.addEventListener('focusout', function () {
      setTimeout(function () {
        if (!menu.matches(':focus-within')) {
          menu.classList.remove('is-open');
          if (summary) summary.setAttribute('aria-expanded', 'false');
        }
      }, 60);
    });

    if (summary) {
      summary.addEventListener('click', function (e) {
        if (window.innerWidth <= 700) {
          e.preventDefault();
          menu.classList.toggle('is-open');
          summary.setAttribute('aria-expanded', menu.classList.contains('is-open') ? 'true' : 'false');
        }
      });
    }
  });

  document.addEventListener('click', function (e) {
    document.querySelectorAll('[data-hover-menu].is-open').forEach(function (menu) {
      if (!menu.contains(e.target)) {
        menu.classList.remove('is-open');
        const summary = menu.querySelector('.fe-menu__summary');
        if (summary) summary.setAttribute('aria-expanded', 'false');
      }
    });
  });

  // Бургер-меню для мобильных
  (function(){
    var burger = document.getElementById('feBurger');
    var panel  = document.getElementById('feMobilePanel');
    if (!burger || !panel) return;

    burger.addEventListener('click', function(){
      var isOpen = panel.classList.toggle('is-open');
      burger.classList.toggle('is-open', isOpen);
      burger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      burger.setAttribute('aria-label', isOpen ? 'Закрыть меню' : 'Открыть меню');
    });

    // Синхронизация счётчика корзины
    var cartMain = document.getElementById('cartCount');
    var cartMob  = document.getElementById('cartCountMob');
    if (cartMain && cartMob) {
      new MutationObserver(function(){ cartMob.textContent = cartMain.textContent; })
        .observe(cartMain, {childList:true, characterData:true, subtree:true});
    }

    // Закрыть меню при клике на ссылку
    panel.querySelectorAll('a').forEach(function(a){
      a.addEventListener('click', function(){
        panel.classList.remove('is-open');
        burger.classList.remove('is-open');
        burger.setAttribute('aria-expanded', 'false');
      });
    });
  })();
});
</script>
