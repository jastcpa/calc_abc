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
    'preview' => ['', '', null, null, false], 'logout' => ['', '', null, null, false],
];
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

function page_allowed(array $v): bool
{
    [, , $view, , , $adm, $unr] = array_pad($v, 7, false);
    return !($adm && !is_admin()) && !($view && !can($view)) && !($unr && !unrestricted());
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= h($csrf) ?>"><meta name="groups" content="<?= h($gjson) ?>"><meta name="ro" content="<?= $readOnly ? '1' : '0' ?>">
<title><?= h($title ?: ($pages[$activeNav][0] ?? 'Админка')) ?> — Бот</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="layout"><nav class="side"><h2>🤖 Telegram-боты</h2>
<?php foreach ($pages as $k => $v) if ($v[4] && page_allowed($v)): ?><a href="?p=<?= $k ?>" class="<?= $k === $activeNav ? 'on' : '' ?>"><?= $v[1] ?> <?= h($v[0]) ?></a><?php endif; ?>
<div class="out"><a href="?p=profile" class="<?= $p === 'profile' ? 'on' : '' ?>">👤 <?= h($ME['name'] ?: $ME['login']) ?><br><small><?= h($ME['role_name']) ?></small></a>
<form method="post" action="?p=logout"><?= csrf_field() ?><button class="linkbtn">⎋ Выйти</button></form></div></nav>
<main class="main"><?php foreach (flashes() as [$t, $m]): ?><div class="flash <?= $t ?>"><?= h($m) ?></div><?php endforeach; ?>
<?php if ($readOnly): ?><div class="flash ro-note">👁 Режим просмотра: у вашей роли нет права редактирования этого раздела.</div><?php endif; ?>
<?= $content ?></main></div><script src="assets/app.js"></script></body></html>
