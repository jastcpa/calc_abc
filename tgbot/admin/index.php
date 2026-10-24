<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/ui.php';

if (!is_installed()) { header('Location: ../install.php'); exit; }

session_name('tgadm');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => str_starts_with(base_url(), 'https://')]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src * data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');

// ---- текущий пользователь (каждый запрос читаем заново: смена роли/деактивация/сброс пароля действуют сразу)
$ME = null;
if (!empty($_SESSION['uid'])) {
    $ME = auth_load((int)$_SESSION['uid']);
    if (!$ME || !$ME['active'] || (int)$ME['sess_ver'] !== (int)($_SESSION['ver'] ?? 0)) { $_SESSION = []; $ME = null; }
}
$GLOBALS['ME'] = $ME;

if (!$ME) {
    $err = '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    q('DELETE FROM login_fail WHERE at < ?', [now() - 900]);
    $locked = (int)val('SELECT COUNT(*) FROM login_fail WHERE ip = ?', [$ip]) >= 5;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $locked) {
        $err = 'Слишком много попыток. Повторите через 15 минут.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cand = row('SELECT * FROM admin_users WHERE login = ? COLLATE NOCASE', [trim((string)($_POST['login'] ?? ''))]);
        // проверяем хеш всегда (даже для неизвестного логина), чтобы не раскрывать существование аккаунта по времени
        $okPass = password_verify((string)($_POST['password'] ?? ''), $cand['pass_hash'] ?? '$2y$10$WGmte67shktKVtqUmeZ5pOMg6qFct.AZCZlWdlbEguUwsEg1lcGtW');
        if ($cand && $cand['active'] && $okPass) {
            session_regenerate_id(true);
            $_SESSION = ['uid' => (int)$cand['id'], 'ver' => (int)$cand['sess_ver'], 'csrf' => rand_token(16)];
            q('UPDATE admin_users SET last_login = ? WHERE id = ?', [now(), $cand['id']]);
            $GLOBALS['ME'] = auth_load((int)$cand['id']);
            audit('login', $cand['login']);
            header('Location: index.php'); exit;
        }
        q('INSERT INTO login_fail (ip, at) VALUES (?, ?)', [$ip, now()]);
        usleep(500000);
        $err = 'Неверный логин или пароль';
    }
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход</title>
    <link rel="stylesheet" href="assets/app.css"></head><body><form method="post" class="card login"><h1>🤖 Вход</h1>
    <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
    <label>Логин</label><input name="login" autofocus><label>Пароль</label><input name="password" type="password">
    <p><button class="btn">Войти</button></p></form></body></html><?php
    exit;
}

$csrf = $_SESSION['csrf'] ??= rand_token(16);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('CSRF token mismatch');
}

// страница => [название, иконка, право на просмотр, право на правку, пункт меню, только админ, только без ограничения по ботам]
$pages = [
    'dashboard' => ['Дашборд', '📊', 'dashboard.view', null, true],
    'users' => ['Подписчики', '👥', 'users.view', 'users.edit', true], 'user' => ['Подписчик', '', 'users.view', 'users.edit', false],
    'groups' => ['Группы', '🏷', 'groups.view', 'groups.edit', true],
    'flows' => ['Цепочки', '🔗', 'flows.view', 'flows.edit', true], 'flow' => ['Цепочка', '', 'flows.view', 'flows.edit', false],
    'broadcasts' => ['Рассылки', '📣', 'broadcasts.view', 'broadcasts.edit', true], 'broadcast' => ['Рассылка', '', 'broadcasts.view', 'broadcasts.edit', false],
    'links' => ['Ссылки/источники', '🧭', 'links.view', null, true],
    'postbacks' => ['Постбеки', '🔁', 'postbacks.view', 'postbacks.edit', true, false, true],
    'bots' => ['Боты', '🤖', null, null, true, true],
    'team' => ['Команда', '🧑‍💼', null, null, true, true], 'roles' => ['Роли и права', '🛡', null, null, true, true],
    'settings' => ['Настройки', '⚙️', null, null, true, true],
    'profile' => ['Мой профиль', '👤', null, null, false],
    'preview' => ['', '', null, null, false], 'logout' => ['', '', null, null, false], 'ctx' => ['', '', null, null, false],
];

// группы меню (порядок = частота использования; админские разделы свёрнуты в конце)
$navGroups = [
    'Обзор' => ['dashboard'],
    'Аудитория' => ['users', 'groups', 'links'],
    'Коммуникации' => ['flows', 'broadcasts'],
    'Интеграции' => ['postbacks'],
    'Администрирование' => ['bots', 'team', 'roles', 'settings'],
];
$navIcons = ['dashboard' => 'dashboard', 'users' => 'users', 'groups' => 'tag', 'links' => 'compass', 'flows' => 'flows', 'broadcasts' => 'send', 'postbacks' => 'repeat', 'bots' => 'bot', 'team' => 'team', 'roles' => 'shield', 'settings' => 'gear'];
$p = $_GET['p'] ?? '';
if ($p === '') {
    foreach ($pages as $k => $v) if ($v[4] && page_allowed($v)) { $p = $k; break; }
    $p = $p ?: 'profile';
}
if (!array_key_exists($p, $pages)) $p = 'profile';
[$pTitle, , $pView, $pEdit, , $pAdmin, $pUnrestricted] = array_pad($pages[$p], 7, false);

if ($p === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { audit('logout'); session_destroy(); }
    header('Location: index.php'); exit;
}

if ($p === 'ctx') {   // переключатель бота: хранится в сессии, применяется ко всем спискам
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = (int)($_POST['bot'] ?? 0);
        $_SESSION['bot_ctx'] = ($b > 0 && bot_ok($b) && bot_get_fresh($b)) ? $b : 0;
    }
    $back = (string)($_POST['back'] ?? '');
    header('Location: ' . (preg_match('/^\?p=[a-z]+(&[A-Za-z0-9_%=.\-\[\]]*)?$/', $back) ? $back : 'index.php'));
    exit;
}

if ($p === 'preview') {
    $html = md_to_html((string)($_POST['body'] ?? ''));
    $html = apply_vars($html, ['first_name' => 'Иван', 'last_name' => 'Петров', 'username' => 'ivan', 'tg_id' => 123456789, 'source' => 'demo']);
    header('Content-Type: application/json');
    echo json_encode(['html' => $html, 'len' => mb_strlen(html_to_plain($html))], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- проверка доступа к странице
$denied = ($pAdmin && !is_admin()) || ($pView && !can($pView)) || ($pUnrestricted && !unrestricted());
$readOnly = false;
ob_start();
$title = '';
if ($denied) {
    http_response_code(403);
    echo '<h1>Недостаточно прав</h1><div class="card"><p>У вашей роли «' . h($ME['role_name']) . '» нет доступа к этому разделу.'
        . ($pUnrestricted ? ' Раздел затрагивает все боты и доступен только пользователям с доступом ко всем ботам.' : '') . '</p></div>';
} else {
    $readOnly = $pEdit && !can($pEdit);
    require __DIR__ . '/pages/' . $p . '.php';
}
$content = ob_get_clean();

$activeNav = ['user' => 'users', 'flow' => 'flows', 'broadcast' => 'broadcasts'][$p] ?? $p;
$gjson = json_encode(rows('SELECT id, name FROM groups ORDER BY name'), JSON_UNESCAPED_UNICODE);
$visBots = visible_bots();
$ctxBot = ctx_bot();

function page_allowed(array $v): bool
{
    [, , $view, , , $adm, $unr] = array_pad($v, 7, false);
    return !($adm && !is_admin()) && !($view && !can($view)) && !($unr && !unrestricted());
}

// хлебные крошки: Группа меню / Раздел / (страница)
$crumbs = [];
foreach ($navGroups as $gn => $keys) if (in_array($activeNav, $keys, true)) $crumbs[] = [$gn, null];
if (isset($pages[$activeNav]) && $pages[$activeNav][0] !== '') $crumbs[] = [$pages[$activeNav][0], $activeNav !== $p ? '?p=' . $activeNav : null];
if ($activeNav !== $p && $title !== '') $crumbs[] = [$title, null];
if ($p === 'profile') $crumbs = [['Мой профиль', null]];

// команды палитры (Ctrl+K)
$cmds = [];
foreach ($navGroups as $gn => $keys) foreach ($keys as $k) if (page_allowed($pages[$k])) $cmds[] = ['t' => $pages[$k][0], 'g' => $gn, 'u' => '?p=' . $k];
if (can('broadcasts.edit') && page_allowed($pages['broadcasts'])) $cmds[] = ['t' => 'Создать рассылку', 'g' => 'Действие', 'u' => '?p=broadcasts#new'];
if (can('flows.edit') && page_allowed($pages['flows'])) $cmds[] = ['t' => 'Создать цепочку', 'g' => 'Действие', 'u' => '?p=flows#new'];
if (is_admin()) { $cmds[] = ['t' => 'Подключить бота', 'g' => 'Действие', 'u' => '?p=bots']; $cmds[] = ['t' => 'Добавить пользователя админки', 'g' => 'Действие', 'u' => '?p=team&edit=new']; }
$cmds[] = ['t' => 'Мой профиль', 'g' => 'Аккаунт', 'u' => '?p=profile'];
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= h($csrf) ?>"><meta name="groups" content="<?= h($gjson) ?>"><meta name="ro" content="<?= $readOnly ? '1' : '0' ?>">
<meta name="cmds" content="<?= h(json_encode($cmds, JSON_UNESCAPED_UNICODE)) ?>"><meta name="usersearch" content="<?= can('users.view') ? '1' : '0' ?>">
<title><?= h(($title ?: ($pages[$activeNav][0] ?? 'Админка')) . ' — Telegram-боты') ?></title><link rel="stylesheet" href="assets/app.css"></head><body>
<a class="skip" href="#main">К содержимому</a>
<div class="app"><aside class="side" id="side" aria-label="Основное меню">
<div class="brand"><span class="logo"><?= icon('send') ?></span> Telegram-боты</div>
<nav class="nav"><?php foreach ($navGroups as $gn => $keys):
    $items = array_filter($keys, fn($k) => page_allowed($pages[$k]));
    if (!$items) continue;
    $open = in_array($activeNav, $keys, true) || $gn !== 'Администрирование'; ?>
  <details data-grp="<?= h($gn) ?>" <?= $open ? 'open' : '' ?>><summary><?= h($gn) ?><?= icon('chev') ?></summary>
  <?php foreach ($items as $k): ?><a href="?p=<?= $k ?>" <?= $k === $activeNav ? 'aria-current="page"' : '' ?>><?= icon($navIcons[$k]) ?> <?= h($pages[$k][0]) ?></a><?php endforeach; ?></details>
<?php endforeach; ?></nav>
<div class="me"><a href="?p=profile" <?= $p === 'profile' ? 'aria-current="page"' : '' ?>><span class="avatar"><?= h(initials($ME['name'] ?: $ME['login'])) ?></span><span class="who"><b><?= h($ME['name'] ?: $ME['login']) ?></b><small><?= h($ME['role_name']) ?></small></span></a>
<form method="post" action="?p=logout"><?= csrf_field() ?><button><?= icon('logout') ?> Выйти</button></form></div></aside>
<div class="scrim" id="scrim"></div>
<div class="content"><header class="top">
<button class="iconbtn" id="navtoggle" aria-label="Меню" aria-controls="side"><?= icon('menu') ?></button>
<nav class="crumbs" aria-label="Путь"><?php foreach ($crumbs as $i => [$ct, $cu]): if ($i) echo '<span>/</span>'; echo $cu ? '<a href="' . h($cu) . '">' . h($ct) . '</a>' : ($i === count($crumbs) - 1 ? '<b>' . h($ct) . '</b>' : '<span>' . h($ct) . '</span>'); endforeach; ?></nav>
<span class="sp"></span>
<?php if (count($visBots) > 1 || ($visBots && unrestricted())): ?><form method="post" action="?p=ctx" class="botctx" title="Показывать данные выбранного бота во всех разделах"><?= csrf_field() ?><input type="hidden" name="back" value="<?= h('?' . http_build_query(array_diff_key($_GET, ['bot' => 1, 'page' => 1]))) ?>">
<span><?= icon('bot') ?></span><select name="bot" aria-label="Бот" data-autosubmit><?= bot_options($ctxBot, unrestricted() ? 'Все боты' : 'Все мои боты') ?></select></form><?php endif; ?>
<button class="iconbtn" id="palbtn" aria-label="Поиск и команды"><?= icon('search') ?> <span>Поиск</span> <kbd>Ctrl K</kbd></button></header>
<main class="main" id="main">
<div class="toasts" role="status" aria-live="polite"><?php foreach (flashes() as [$t, $m]): ?><div class="toast <?= $t === 'err' ? 'err' : '' ?>"><span><?= h($m) ?></span><button type="button" aria-label="Закрыть">×</button></div><?php endforeach; ?></div>
<?php if ($readOnly): ?><div class="notice"><?= icon('shield') ?> Режим просмотра: у вашей роли нет права редактирования этого раздела.</div><?php endif; ?>
<?= $content ?></main></div></div>
<dialog id="confirm"><form method="dialog" class="dlg"><h2 id="cf-title">Подтвердите действие</h2><p id="cf-text" class="muted"></p><div class="row"><button class="btn sec" value="cancel">Отмена</button><button class="btn" id="cf-ok" value="ok">Подтвердить</button></div></form></dialog>
<dialog id="palette"><input id="pal-q" placeholder="Раздел, действие или имя подписчика…" autocomplete="off" aria-label="Поиск"><ul id="pal-list" role="listbox"></ul><div class="pal-foot">↑↓ выбор · Enter открыть · Esc закрыть</div></dialog>
<script src="assets/app.js"></script></body></html>
