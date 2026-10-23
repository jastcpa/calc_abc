<?php
// Веб-установщик. После установки файл можно (и нужно) удалить.
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/telegram.php';
require __DIR__ . '/lib/engine.php';

$errors = [];
$done = null;

$reqs = [
    'PHP 8.0+' => PHP_VERSION_ID >= 80000,
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'curl' => extension_loaded('curl'),
    'mbstring' => extension_loaded('mbstring'),
    'Папка data/ доступна для записи' => is_writable(__DIR__ . '/data'),
    'Папка проекта доступна для записи (для config.php)' => is_writable(__DIR__),
];
$reqOk = !in_array(false, $reqs, true);

if (is_installed()) {
    http_response_code(403);
    exit('<meta charset="utf-8"><body style="font-family:sans-serif;padding:40px"><h2>Уже установлено</h2><p>Удалите файл <code>install.php</code> с сервера. Админка: <a href="admin/">admin/</a></p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reqOk) {
    $login = trim($_POST['login'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $token = trim($_POST['token'] ?? '');
    $tzs = trim($_POST['tz'] ?? 'Europe/Moscow');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $login)) $errors[] = 'Логин: 3–32 символа, латиница/цифры.';
    if (mb_strlen($pass) < 8) $errors[] = 'Пароль не короче 8 символов.';
    if (!in_array($tzs, DateTimeZone::listIdentifiers(), true)) $errors[] = 'Неизвестный часовой пояс.';

    $me = null;
    if (!$errors && $token !== '') {
        if (!preg_match('/^\d+:[\w-]{30,}$/', $token)) $errors[] = 'Токен выглядит некорректно.';
    }
    if (!$errors) {
        $dbFile = 'data/bot_' . rand_token(6) . '.sqlite';
        $php = "<?php\nreturn " . var_export(['db' => __DIR__ . '/' . $dbFile], true) . ";\n";
        if (file_put_contents(__DIR__ . '/config.php', $php) === false) {
            $errors[] = 'Не удалось записать config.php';
        } else {
            migrate();
            set_setting('admin_login', $login);
            set_setting('admin_hash', password_hash($pass, PASSWORD_DEFAULT));
            set_setting('secret', rand_token(24));
                        set_setting('postback_key', rand_token(16));
            set_setting('cron_key', rand_token(16));
            set_setting('tz', $tzs);
            set_setting('base_url', base_url());
            $done = ['webhook' => null, 'me' => null];
            if ($token !== '') {
                $me = tg(0, 'getMe', [], 20, $token);
                if ($me['ok'] ?? false) {
                    $bid = insert('bots', ['name' => (string)$me['result']['first_name'], 'token' => $token, 'username' => (string)$me['result']['username'],
                        'secret' => rand_token(24), 'active' => 1, 'created_at' => now()]);
                    $done['me'] = $me['result']['username'];
                    $done['webhook'] = tg_set_webhook($bid);
                } else {
                    $done['me_error'] = $me['description'] ?? 'ошибка';
                }
            }
            // flow по умолчанию
            $fid = insert('flows', ['name' => 'Приветственная цепочка', 'source' => '', 'bot_id' => 0, 'is_default' => 1, 'active' => 1, 'group_id' => 0, 'created_at' => now()]);
            insert('steps', ['flow_id' => $fid, 'position' => 0, 'delay' => 0, 'body' => "Привет, **{first_name}**! 👋\nЭто первое сообщение цепочки. Измените его в админке.", 'buttons' => json_encode([[['text' => 'Нажми меня', 'type' => 'cb', 'reply' => 'Спасибо за клик! ✅']]], JSON_UNESCAPED_UNICODE)]);
            insert('steps', ['flow_id' => $fid, 'position' => 1, 'delay' => 3600, 'body' => 'Прошёл час — это второе сообщение цепочки.']);
        }
    }
}
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Установка бота</title>
<style>
body{font-family:system-ui,sans-serif;background:#f3f4f6;margin:0;padding:24px;color:#111}
.card{max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px #0001}
h1{margin-top:0;font-size:22px} label{display:block;margin:14px 0 4px;font-weight:600;font-size:14px}
input{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;font-size:15px;box-sizing:border-box}
button{margin-top:20px;background:#2563eb;color:#fff;border:0;border-radius:8px;padding:12px 20px;font-size:16px;cursor:pointer}
.ok{color:#15803d}.bad{color:#b91c1c}.err{background:#fee2e2;padding:10px;border-radius:8px;margin-bottom:10px}
small{color:#666} code{background:#eee;padding:1px 5px;border-radius:4px}
</style></head><body><div class="card">
<h1>🤖 Установка Telegram-бота</h1>
<?php if ($done): ?>
  <p class="ok"><b>Установка завершена.</b></p>
  <?php if ($done['me']): ?><p>Бот <b>@<?= h($done['me']) ?></b> подключён.
    Webhook: <?= ($done['webhook']['ok'] ?? false) ? '<span class="ok">установлен</span>' : '<span class="bad">ошибка — ' . h($done['webhook']['description'] ?? '') . ' (нужен HTTPS)</span>' ?></p>
  <?php elseif (isset($done['me_error'])): ?><p class="bad">Токен не принят Telegram: <?= h($done['me_error']) ?>. Добавьте бота в Админка → Боты.</p>
  <?php else: ?><p>Токен бота не указан — добавьте бота в Админка → Боты (можно подключить любое количество).</p><?php endif; ?>
  <p><b>Теперь:</b></p>
  <ol><li>Удалите файл <code>install.php</code> с сервера.</li>
  <li>Откройте <a href="admin/">админку</a> и войдите.</li>
  <li>Добавьте задачу cron (см. INSTALL.md), чтобы работали задержки и рассылки по расписанию.</li></ol>
<?php else: ?>
  <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
  <h3>Проверка окружения</h3>
  <ul><?php foreach ($reqs as $n => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✔' : '✘' ?> <?= h($n) ?></li><?php endforeach; ?></ul>
  <?php if ($reqOk): ?>
  <form method="post">
    <label>Логин администратора</label><input name="login" value="<?= h($_POST['login'] ?? 'admin') ?>" required>
    <label>Пароль (от 8 символов)</label><input name="password" type="password" required>
    <label>Токен первого бота от @BotFather</label><input name="token" value="<?= h($_POST['token'] ?? '') ?>" placeholder="123456:ABC..."><small>Можно оставить пустым — ботов добавляют в админке (раздел «Боты»), их может быть сколько угодно.</small>
    <label>Часовой пояс</label><input name="tz" value="<?= h($_POST['tz'] ?? 'Europe/Moscow') ?>"><small>Например Europe/Moscow, Europe/Kyiv, Asia/Almaty</small>
    <button>Установить</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div></body></html>
