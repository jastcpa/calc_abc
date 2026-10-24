<?php
$title = 'Настройки';
if (is_post()) {
    $do = post('do');
    audit('settings.' . $do);
    if ($do === 'save') {
        $tzs = post('tz', 'UTC');
        if (in_array($tzs, DateTimeZone::listIdentifiers(), true)) set_setting('tz', $tzs); else flash('Неверный часовой пояс', 'err');
        set_setting('admin_tg_id', (string)(int)post('admin_tg_id'));
        set_setting('default_reply', post('default_reply'));
        set_setting('restart_on_start', isset($_POST['restart_on_start']) ? '1' : '0');
        set_setting('auto_group_events', isset($_POST['auto_group_events']) ? '1' : '0');
        $bu = rtrim(post('base_url'), '/');
        set_setting('base_url', $bu);
        flash('Сохранено');
    } elseif ($do === 'regen') {
        $k = post('key');
        if (in_array($k, ['postback_key', 'cron_key'], true)) { set_setting($k, rand_token(16)); flash('Ключ обновлён'); }
    }
    redirect('?p=settings');
}
?>
<?= pagehead('Настройки') ?>
<div class="card"><h2>Общие параметры</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save">
<p class="muted">Токены ботов и webhook — в разделе <a href="?p=bots">Боты</a>.</p>
<div class="grid2"><div><label>Часовой пояс админки</label><input name="tz" value="<?= h(setting('tz', 'Europe/Moscow')) ?>"></div>
<div><label>Ваш Telegram ID (для тестовых отправок; напишите боту /start)</label><input name="admin_tg_id" value="<?= h(setting('admin_tg_id')) ?>" placeholder="узнать: @userinfobot"></div></div>
<label>Базовый URL установки (для вебхука и трекинга ссылок; HTTPS обязателен)</label>
<input name="base_url" value="<?= h(setting('base_url')) ?>" placeholder="<?= h(base_url()) ?>">
<label>Ответ на любое сообщение пользователя (markdown, пусто = не отвечать)</label>
<textarea name="default_reply" rows="3"><?= h(setting('default_reply')) ?></textarea>
<label class="chk"><input type="checkbox" name="restart_on_start" <?= setting('restart_on_start') === '1' ? 'checked' : '' ?>> Перезапускать цепочку, если существующий пользователь снова нажал /start</label>
<label class="chk"><input type="checkbox" name="auto_group_events" <?= setting('auto_group_events') === '1' ? 'checked' : '' ?>> Автоматически создавать группу «Событие: …» для входящих постбеков без правила</label>
<p><button class="btn">Сохранить</button></p></form></div>

<div class="card"><h2>Cron (отправка по задержкам и расписанию)</h2>
<p>Последний запуск планировщика: <b><?= fmt_dt((int)setting('last_tick', '0') ?: null, 'd.m.Y H:i:s') ?></b></p>
<p>Команда для cron (каждую минуту):</p>
<p><code data-copy>* * * * * php <?= h(APP_ROOT) ?>/cron.php >/dev/null 2>&1</code></p>
<p>Или по HTTP: <code data-copy><?= h(base_url()) ?>/cron.php?key=<?= h(setting('cron_key')) ?></code></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="regen"><input type="hidden" name="key" value="cron_key"><button class="btn sec sm" data-confirm="Старые ссылки cron перестанут работать. Продолжить?">Сменить ключ cron</button></form></div>

