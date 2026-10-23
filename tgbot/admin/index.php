<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/ui.php';

if (!is_installed()) { header('Location: ../install.php'); exit; }

session_name('tgadm');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => str_starts_with(base_url(), 'https://')]);
session_start();

$p = $_GET['p'] ?? 'dashboard';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src * data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');

if (empty($_SESSION['auth'])) {
    $err = '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    q('DELETE FROM login_fail WHERE at < ?', [now() - 900]);
    $locked = (int)val('SELECT COUNT(*) FROM login_fail WHERE ip = ?', [$ip]) >= 5;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $locked) {
        $err = 'Слишком много попыток. Повторите через 15 минут.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $okLogin = hash_equals((string)setting('admin_login'), (string)($_POST['login'] ?? ''));
        $okPass = password_verify((string)($_POST['password'] ?? ''), (string)setting('admin_hash'));
        if ($okLogin && $okPass) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['csrf'] = rand_token(16);
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
if (($_GET['p'] ?? '') === 'logout' && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['p'] ?? '') === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

$pages = [
    'dashboard' => ['Дашборд', '📊'], 'users' => ['Пользователи', '👥'], 'user' => null, 'groups' => ['Группы', '🏷'],
    'flows' => ['Цепочки', '🔗'], 'flow' => null, 'broadcasts' => ['Рассылки', '📣'], 'broadcast' => null,
    'bots' => ['Боты', '🤖'], 'postbacks' => ['Постбеки', '🔁'], 'links' => ['Ссылки/источники', '🧭'], 'settings' => ['Настройки', '⚙️'],
    'preview' => null, 'logout' => null,
];
if (!array_key_exists($p, $pages)) $p = 'dashboard';

if ($p === 'preview') {
    $html = md_to_html((string)($_POST['body'] ?? ''));
    $html = apply_vars($html, ['first_name' => 'Иван', 'last_name' => 'Петров', 'username' => 'ivan', 'tg_id' => 123456789, 'source' => 'demo']);
    header('Content-Type: application/json');
    echo json_encode(['html' => $html, 'len' => mb_strlen(html_to_plain($html))], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_start();
$title = '';
require __DIR__ . '/pages/' . $p . '.php';
$content = ob_get_clean();

$activeNav = ['user' => 'users', 'flow' => 'flows', 'broadcast' => 'broadcasts'][$p] ?? $p;
$gjson = json_encode(rows('SELECT id, name FROM groups ORDER BY name'), JSON_UNESCAPED_UNICODE);
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= h($csrf) ?>"><meta name="groups" content="<?= h($gjson) ?>">
<title><?= h($title ?: ($pages[$activeNav][0] ?? 'Админка')) ?> — Бот</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="layout"><nav class="side"><h2>🤖 Telegram-боты</h2>
<?php foreach ($pages as $k => $v) if ($v): ?><a href="?p=<?= $k ?>" class="<?= $k === $activeNav ? 'on' : '' ?>"><?= $v[1] ?> <?= h($v[0]) ?></a><?php endif; ?>
<form method="post" action="?p=logout" class="out"><?= csrf_field() ?><button class="linkbtn">⎋ Выйти</button></form></nav>
<main class="main"><?php foreach (flashes() as [$t, $m]): ?><div class="flash <?= $t ?>"><?= h($m) ?></div><?php endforeach; ?>
<?= $content ?></main></div><script src="assets/app.js"></script></body></html>
