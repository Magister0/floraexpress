<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/map_config.php';
require_once __DIR__ . '/address_book_lib.php';
$pdo = db();
address_book_ensure_columns($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('account_addresses.php'));
    exit;
}

$userId = (int)$me['id'];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes')
        ? bin2hex(random_bytes(16))
        : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

$err = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            throw new RuntimeException('Сессия устарела. Обновите страницу.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $label     = trim((string)($_POST['label'] ?? ''));
            $address   = trim((string)($_POST['address'] ?? ''));
            $lat       = isset($_POST['lat']) ? (float)$_POST['lat'] : 0.0;
            $lon       = isset($_POST['lon']) ? (float)$_POST['lon'] : 0.0;
            $isDefault = !empty($_POST['is_default']);
            $apartment = address_book_clean((string)($_POST['apartment'] ?? ''), 20);
            $entrance  = address_book_clean((string)($_POST['entrance'] ?? ''), 20);
            $floor     = address_book_clean((string)($_POST['floor'] ?? ''), 10);
            $intercom  = address_book_clean((string)($_POST['intercom'] ?? ''), 20);

            if ($label === '') {
                throw new RuntimeException('Укажите название адреса.');
            }
            if ($address === '') {
                throw new RuntimeException('Выберите адрес на карте.');
            }
            if ($lat == 0.0 || $lon == 0.0) {
                throw new RuntimeException('Не выбраны координаты адреса.');
            }

            $pdo->beginTransaction();

            $stCount = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = ?');
            $stCount->execute([$userId]);
            $hasAddresses = ((int)$stCount->fetchColumn()) > 0;

            if (!$hasAddresses) {
                $isDefault = true;
            }

            if ($isDefault) {
                $st = $pdo->prepare('UPDATE user_addresses SET is_default = FALSE WHERE user_id = ?');
                $st->execute([$userId]);
            }

            $st = $pdo->prepare('INSERT INTO user_addresses (user_id, label, address, lat, lon, is_default, apartment, entrance, floor, intercom) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            // PostgreSQL не принимает PHP-false как '' для boolean — передаём явный литерал.
            $st->execute([$userId, $label, $address, $lat, $lon, ($isDefault ? 'true' : 'false'), $apartment, $entrance, $floor, $intercom]);

            $pdo->commit();
            $ok = 'Адрес сохранён.';
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Некорректный адрес.');
            }

            $pdo->beginTransaction();

            $stCurrent = $pdo->prepare('SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1');
            $stCurrent->execute([$id, $userId]);
            $current = $stCurrent->fetch(PDO::FETCH_ASSOC);

            $stDelete = $pdo->prepare('DELETE FROM user_addresses WHERE id = ? AND user_id = ?');
            $stDelete->execute([$id, $userId]);

            if ($current && db_bool($current['is_default'] ?? false)) {
                $stNext = $pdo->prepare('SELECT id FROM user_addresses WHERE user_id = ? ORDER BY id DESC LIMIT 1');
                $stNext->execute([$userId]);
                $nextId = (int)$stNext->fetchColumn();
                if ($nextId > 0) {
                    $stSet = $pdo->prepare('UPDATE user_addresses SET is_default = TRUE WHERE id = ? AND user_id = ?');
                    $stSet->execute([$nextId, $userId]);
                }
            }

            $pdo->commit();
            $ok = 'Адрес удалён.';
        }

        if ($action === 'set_default') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Некорректный адрес.');
            }

            $pdo->beginTransaction();

            $st = $pdo->prepare('UPDATE user_addresses SET is_default = FALSE WHERE user_id = ?');
            $st->execute([$userId]);

            $st = $pdo->prepare('UPDATE user_addresses SET is_default = TRUE WHERE id = ? AND user_id = ?');
            $st->execute([$id, $userId]);

            if ($st->rowCount() === 0) {
                throw new RuntimeException('Адрес не найден.');
            }

            $pdo->commit();
            $ok = 'Адрес по умолчанию обновлён.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

$st = $pdo->prepare('SELECT id, label, address, lat, lon, is_default, apartment, entrance, floor, intercom, created_at FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC');
$st->execute([$userId]);
$addresses = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$yandexApiKey = defined('FLORA_YANDEX_MAPS_API_KEY') ? trim((string)FLORA_YANDEX_MAPS_API_KEY) : '';
$mapLat = defined('FLORA_MAP_DEFAULT_LAT') ? (float)FLORA_MAP_DEFAULT_LAT : 56.8389;
$mapLon = defined('FLORA_MAP_DEFAULT_LON') ? (float)FLORA_MAP_DEFAULT_LON : 60.6057;
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Адресная книга — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">

  <?php $ymapsSrc = $yandexApiKey !== '' ? 'https://api-maps.yandex.ru/2.1/?apikey=' . rawurlencode($yandexApiKey) . '&lang=ru_RU' : 'https://api-maps.yandex.ru/2.1/?lang=ru_RU'; ?>
    <script src="<?= h($ymapsSrc) ?>" type="text/javascript"></script>

  <style>
    .lk-wrap{max-width:860px;margin:0 auto;display:grid;gap:16px}
    .lk-back{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:14px;text-decoration:none}
    .lk-back:hover{color:#0f172a}
    .lk-tabs{display:flex;gap:4px;background:#fff;border:1px solid #e8edf2;border-radius:14px;padding:6px;box-shadow:0 2px 8px rgba(15,23,42,.05);flex-wrap:wrap}
    .lk-tab{padding:8px 16px;border-radius:10px;color:#6b7280;text-decoration:none;font-size:14px;font-weight:600;transition:.15s ease}
    .lk-tab:hover{background:#f3f6f4;color:#0f172a}
    .lk-tab.active{background:var(--pistachio-soft);color:#0f172a;border:1px solid #cfe9d9}
    .addr-box{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:16px}
    .addr-err{background:#fee2e2;border:1px solid #fecdd3;color:#7f1d1d;padding:8px 12px;border-radius:10px;margin:10px 0}
    .addr-ok{background:#E8F5EC;border:1px solid #cfe9d9;color:#124d31;padding:8px 12px;border-radius:10px;margin:10px 0}
    .addr-cards{display:grid;gap:12px}
    .addr-card{border:1px solid #e8edf2;border-radius:14px;padding:14px}
    .addr-tag{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #d7eadf;background:#f2faf6;color:#185a37;font-weight:700;font-size:12px}
    .addr-form-grid{display:grid;grid-template-columns:1fr 190px;gap:12px}
    .addr-details-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    .addr-extra{margin-top:6px;font-size:13px;color:#475569}
    .addr-map{height:340px;border-radius:16px;overflow:hidden;border:1px solid #dbe7de;margin-top:10px}
    .addr-muted{color:#6b7280}
    @media(max-width:640px){.addr-form-grid{grid-template-columns:1fr}.addr-details-grid{grid-template-columns:1fr 1fr}.lk-tab{font-size:13px;padding:7px 12px}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section">
  <div class="container">
    <div class="lk-wrap">
      <div>
        <a class="lk-back" href="account.php">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
          Личный кабинет
        </a>
      </div>
      <nav class="lk-tabs">
        <a class="lk-tab" href="account_orders.php">Заказы</a>
        <a class="lk-tab" href="account_profile.php">Профиль</a>
        <a class="lk-tab" href="account_favorites.php">Избранное</a>
        <a class="lk-tab active" href="account_addresses.php">Адреса</a>
      </nav>
      <main style="display:grid;gap:16px">
      <div class="addr-box">
        <h1 style="margin:0 0 10px;font-size:24px">Адресная книга</h1>
        <p class="addr-muted" style="margin:0">Сохраняйте адреса, чтобы быстрее оформлять заказы.</p>

        <?php if ($err): ?><div class="addr-err"><?= h($err) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="addr-ok"><?= h($ok) ?></div><?php endif; ?>
      </div>

      <div class="addr-box">
        <h2 style="margin:0 0 12px;font-size:18px">Добавить адрес</h2>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="create">

          <div class="addr-form-grid">
            <div>
              <label class="addr-muted">Название адреса</label>
              <input class="input" name="label" placeholder="Например: Дом / Работа" required value="<?= h($_POST['label'] ?? '') ?>">
            </div>
            <div style="display:flex;align-items:end">
              <label style="display:flex;gap:8px;align-items:center;font-weight:700">
                <input type="checkbox" name="is_default" value="1" <?= !empty($_POST['is_default']) ? 'checked' : '' ?>>
                По умолчанию
              </label>
            </div>
          </div>

          <div style="margin-top:12px">
            <label class="addr-muted">Адрес</label>
            <input class="input" id="addressInput" name="address" readonly required placeholder="Выберите точку на карте" value="<?= h($_POST['address'] ?? '') ?>">
            <input type="hidden" id="addressLat" name="lat" value="<?= h($_POST['lat'] ?? '') ?>">
            <input type="hidden" id="addressLon" name="lon" value="<?= h($_POST['lon'] ?? '') ?>">
            <div id="addressBookMap" class="addr-map"></div>
            <div class="addr-muted" style="margin-top:8px">Нажмите на Яндекс.Карту или определите текущее местоположение.</div>
          </div>

          <div style="margin-top:14px">
            <label class="addr-muted">Детали входа (подставятся при оформлении заказа)</label>
            <div class="addr-details-grid" style="margin-top:6px">
              <div>
                <input class="input" name="apartment" placeholder="Квартира" maxlength="20" value="<?= h($_POST['apartment'] ?? '') ?>">
              </div>
              <div>
                <input class="input" name="entrance" placeholder="Подъезд" maxlength="20" value="<?= h($_POST['entrance'] ?? '') ?>">
              </div>
              <div>
                <input class="input" name="floor" placeholder="Этаж" maxlength="10" inputmode="numeric" value="<?= h($_POST['floor'] ?? '') ?>">
              </div>
              <div>
                <input class="input" name="intercom" placeholder="Домофон" maxlength="20" value="<?= h($_POST['intercom'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="row" style="margin-top:12px;gap:10px;flex-wrap:wrap">
            <button type="button" class="btn btn--ghost" id="detectLocationBtn">Определить моё местоположение</button>
            <button class="btn" type="submit">Сохранить адрес</button>
          </div>
        </form>
      </div>

      <div class="addr-box">
        <h2 style="margin:0 0 12px;font-size:18px">Мои адреса</h2>

        <?php if (!$addresses): ?>
          <p class="addr-muted" style="margin:0">У вас пока нет сохранённых адресов.</p>
        <?php else: ?>
          <div class="addr-cards">
            <?php foreach ($addresses as $a): ?>
              <div class="addr-card">
                <div class="row" style="justify-content:space-between;gap:12px;flex-wrap:wrap">
                  <div>
                    <div style="font-weight:800;font-size:18px"><?= h($a['label']) ?></div>
                    <div class="addr-muted" style="margin-top:4px"><?= h($a['address']) ?></div>
                    <?php
                      $extra = [];
                      if (trim((string)($a['apartment'] ?? '')) !== '') $extra[] = 'кв. ' . h($a['apartment']);
                      if (trim((string)($a['entrance'] ?? '')) !== '') $extra[] = 'подъезд ' . h($a['entrance']);
                      if (trim((string)($a['floor'] ?? '')) !== '') $extra[] = 'этаж ' . h($a['floor']);
                      if (trim((string)($a['intercom'] ?? '')) !== '') $extra[] = 'домофон ' . h($a['intercom']);
                    ?>
                    <?php if ($extra): ?>
                      <div class="addr-extra"><?= implode(' · ', $extra) ?></div>
                    <?php endif; ?>
                    <div class="addr-muted" style="margin-top:6px;font-size:13px">
                      <?= h($a['lat']) ?>, <?= h($a['lon']) ?>
                    </div>
                  </div>

                  <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;justify-content:flex-end">
                    <?php if (db_bool($a['is_default'] ?? false)): ?>
                      <span class="addr-tag">По умолчанию</span>
                    <?php else: ?>
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn--ghost" type="submit">Сделать основным</button>
                      </form>
                    <?php endif; ?>

                    <form method="post" onsubmit="return confirm('Удалить этот адрес?')">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                      <button class="btn btn--ghost" type="submit">Удалить</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      </main>
    </div>
  </div>
</section>

<script>
(function () {
  const mapEl = document.getElementById('addressBookMap');
  if (!mapEl) return;

  const addressInput = document.getElementById('addressInput');
  const latInput = document.getElementById('addressLat');
  const lonInput = document.getElementById('addressLon');
  const detectBtn = document.getElementById('detectLocationBtn');

  const startLat = latInput.value ? parseFloat(latInput.value) : <?= json_encode($mapLat, JSON_UNESCAPED_UNICODE) ?>;
  const startLon = lonInput.value ? parseFloat(lonInput.value) : <?= json_encode($mapLon, JSON_UNESCAPED_UNICODE) ?>;

  function setValues(lat, lon, address) {
    latInput.value = lat;
    lonInput.value = lon;
    if (typeof address === 'string') {
      addressInput.value = address;
    }
  }

  function initYandexMap() {
    ymaps.ready(function () {
      const map = new ymaps.Map('addressBookMap', {
        center: [startLat, startLon],
        zoom: 13,
        controls: ['zoomControl']
      }, {
        searchControlProvider: 'yandex#search'
      });

      let placemark = null;

      function ensurePlacemark(coords) {
        if (placemark) {
          placemark.geometry.setCoordinates(coords);
          return placemark;
        }

        placemark = new ymaps.Placemark(coords, {}, {
          preset: 'islands#redDotIcon',
          draggable: true
        });

        placemark.events.add('dragend', function () {
          reverseGeocode(placemark.geometry.getCoordinates());
        });

        map.geoObjects.add(placemark);
        return placemark;
      }

      function reverseGeocode(coords) {
        const lat = coords[0];
        const lon = coords[1];
        setValues(lat, lon);
        ensurePlacemark(coords);
        map.setCenter(coords, 16);

        ymaps.geocode(coords, { results: 1 }).then(function (res) {
          const firstGeoObject = res.geoObjects.get(0);
          const address = firstGeoObject
            ? (firstGeoObject.getAddressLine ? firstGeoObject.getAddressLine() : firstGeoObject.properties.get('text'))
            : `${lat}, ${lon}`;
          setValues(lat, lon, address || `${lat}, ${lon}`);
        }).catch(function () {
          setValues(lat, lon, `${lat}, ${lon}`);
        });
      }

      map.events.add('click', function (e) {
        reverseGeocode(e.get('coords'));
      });

      detectBtn?.addEventListener('click', function () {
        if (!navigator.geolocation) {
          alert('Геолокация не поддерживается браузером');
          return;
        }

        navigator.geolocation.getCurrentPosition(function (pos) {
          reverseGeocode([pos.coords.latitude, pos.coords.longitude]);
        }, function () {
          alert('Не удалось получить геолокацию');
        }, {
          enableHighAccuracy: true,
          timeout: 10000
        });
      });

      if (latInput.value && lonInput.value) {
        const lat = parseFloat(latInput.value);
        const lon = parseFloat(lonInput.value);
        ensurePlacemark([lat, lon]);
        map.setCenter([lat, lon], 16);
      }
    });
  }

  if (window.ymaps) {
    initYandexMap();
  }
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
