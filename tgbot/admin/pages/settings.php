<?php
$title = 'Настройки';
if (is_post()) {
    $do = post('do');
    if ($do === 'save') {
        $tok = post('bot_token');
        if ($tok !== '' && $tok !== setting('bot_token')) {
            set_setting('bot_token', $tok);
            $me = tg('getMe');
            if ($me['ok'] ?? false) { set_setting('bot_username', (string)$me['result']['username']); flash('Токен принят: @' . $me['result']['username']); }
            else flash('Telegram не принял токен: ' . ($me['description'] ?? ''), 'err');
        }
        $tzs = post('tz', 'UTC');
        if (in_array($tzs, DateTimeZone::listIdentifiers(), true)) set_setting('tz', $tzs); else flash('Неверный часовой пояс', 'err');
        set_setting('admin_tg_id', (string)(int)post('admin_tg_id'));
        set_setting('default_reply', post('default_reply'));
        set_setting('restart_on_start', isset($_POST['restart_on_start']) ? '1' : '0');
        set_setting('auto_group_events', isset($_POST['auto_group_events']) ? '1' : '0');
        $bu = rtrim(post('base_url'), '/');
        set_setting('base_url', $bu);
        flash('Сохранено');
    } elseif ($do === 'webhook_set') {
        $r = tg_set_webhook();
        flash(($r['ok'] ?? false) ? 'Webhook установлен: ' . base_url() . '/webhook.php' : 'Ошибка: ' . ($r['description'] ?? '?'), ($r['ok'] ?? false) ? 'ok' : 'err');
    } elseif ($do === 'webhook_del') {
        $r = tg('deleteWebhook');
        flash(($r['ok'] ?? false) ? 'Webhook удалён' : 'Ошибка: ' . ($r['description'] ?? '?'), ($r['ok'] ?? false) ? 'ok' : 'err');
    } elseif ($do === 'password') {
        if (!password_verify(post('old'), (string)setting('admin_hash'))) flash('Текущий пароль неверен', 'err');
        elseif (mb_strlen(post('new')) < 8) flash('Новый пароль короче 8 символов', 'err');
        else { set_setting('admin_hash', password_hash(post('new'), PASSWORD_DEFAULT)); flash('Пароль изменён'); }
    } elseif ($do === 'regen') {
        $k = post('key');
        if (in_array($k, ['postback_key', 'cron_key'], true)) { set_setting($k, rand_token(16)); flash('Ключ обновлён'); }
    }
    redirect('?p=settings');
}
$info = setting('bot_token') ? tg('getWebhookInfo') : null;
$wi = $info['result'] ?? [];
?>
<h1>Настройки</h1>
<div class="card"><h2>Бот и общие параметры</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save">
<label>Токен бота (@BotFather)</label><input name="bot_token" value="<?= h(setting('bot_token')) ?>" autocomplete="off">
<div class="grid2"><div><label>Часовой пояс админки</label><input name="tz" value="<?= h(setting('tz', 'Europe/Moscow')) ?>"></div>
<div><label>Ваш Telegram ID (для тестовых отправок)</label><input name="admin_tg_id" value="<?= h(setting('admin_tg_id')) ?>" placeholder="узнать: @userinfobot"></div></div>
<label>Базовый URL установки (для вебхука и трекинга ссылок; HTTPS обязателен)</label>
<input name="base_url" value="<?= h(setting('base_url')) ?>" placeholder="<?= h(base_url()) ?>">
<label>Ответ на любое сообщение пользователя (markdown, пусто = не отвечать)</label>
<textarea name="default_reply" rows="3"><?= h(setting('default_reply')) ?></textarea>
<label class="chk"><input type="checkbox" name="restart_on_start" <?= setting('restart_on_start') === '1' ? 'checked' : '' ?>> Перезапускать цепочку, если существующий пользователь снова нажал /start</label>
<label class="chk"><input type="checkbox" name="auto_group_events" <?= setting('auto_group_events') === '1' ? 'checked' : '' ?>> Автоматически создавать группу «Событие: …» для входящих постбеков без правила</label>
<p><button class="btn">Сохранить</button></p></form></div>

<div class="card"><h2>Webhook</h2>
<p>URL: <code><?= h(base_url()) ?>/webhook.php</code></p>
<?php if ($info): ?>
<p>Статус: <?= !empty($wi['url']) ? '<span class="badge g">установлен</span> <code>' . h($wi['url']) . '</code>' : '<span class="badge r">не установлен</span>' ?>
 · в очереди: <?= (int)($wi['pending_update_count'] ?? 0) ?>
<?php if (!empty($wi['last_error_message'])): ?><br><span class="danger">Последняя ошибка: <?= h($wi['last_error_message']) ?></span><?php endif; ?></p>
<?php endif; ?>
<form method="post" class="row"><?= csrf_field() ?><button class="btn" name="do" value="webhook_set">Установить webhook</button>
<button class="btn sec" name="do" value="webhook_del">Удалить webhook</button></form></div>

<div class="card"><h2>Cron (отправка по задержкам и расписанию)</h2>
<p>Последний запуск планировщика: <b><?= fmt_dt((int)setting('last_tick', '0') ?: null, 'd.m.Y H:i:s') ?></b></p>
<p>Команда для cron (каждую минуту):</p>
<p><code data-copy>* * * * * php <?= h(APP_ROOT) ?>/cron.php >/dev/null 2>&1</code></p>
<p>Или по HTTP: <code data-copy><?= h(base_url()) ?>/cron.php?key=<?= h(setting('cron_key')) ?></code></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="regen"><input type="hidden" name="key" value="cron_key"><button class="btn sec sm" data-confirm="Старые ссылки cron перестанут работать. Продолжить?">Сменить ключ cron</button></form></div>

<div class="card"><h2>Пароль администратора</h2>
<form method="post" class="grid3"><?= csrf_field() ?><input type="hidden" name="do" value="password">
<div><label>Текущий</label><input type="password" name="old"></div><div><label>Новый</label><input type="password" name="new"></div>
<div><label>&nbsp;</label><button class="btn">Сменить</button></div></form></div>
