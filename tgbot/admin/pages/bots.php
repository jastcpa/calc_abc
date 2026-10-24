<?php
$title = 'Боты';
if (is_post()) {
    $do = post('do');
    $id = (int)post('id');
    audit('bots.' . $do, '#' . $id);
    $bot = $id ? row('SELECT * FROM bots WHERE id = ?', [$id]) : null;
    if ($do === 'add') {
        $tok = post('token');
        if (!preg_match('/^\d+:[\w-]{30,}$/', $tok)) flash('Токен выглядит некорректно', 'err');
        elseif (val('SELECT 1 FROM bots WHERE token = ?', [$tok])) flash('Этот бот уже подключён', 'err');
        else {
            $me = tg(0, 'getMe', [], 20, $tok);
            if (!($me['ok'] ?? false)) flash('Telegram не принял токен: ' . ($me['description'] ?? '?'), 'err');
            else {
                $bid = insert('bots', ['name' => mb_substr(post('name') ?: (string)$me['result']['first_name'], 0, 60), 'token' => $tok,
                    'username' => (string)$me['result']['username'], 'secret' => rand_token(24), 'active' => 1, 'created_at' => now()]);
                $w = tg_set_webhook($bid);
                flash('Бот @' . $me['result']['username'] . ' подключён' . (($w['ok'] ?? false) ? '' : '. Webhook не установлен: ' . ($w['description'] ?? '?') . ' (нужен HTTPS)'), ($w['ok'] ?? false) ? 'ok' : 'err');
            }
        }
    } elseif ($bot) {
        if ($do === 'rename') { update('bots', $id, ['name' => mb_substr(post('name'), 0, 60) ?: $bot['name']]); flash('Сохранено'); }
        elseif ($do === 'toggle') { update('bots', $id, ['active' => $bot['active'] ? 0 : 1]); flash($bot['active'] ? 'Бот отключён: апдейты игнорируются, рассылки его не затрагивают' : 'Бот включён'); }
        elseif ($do === 'webhook') {
            $r = tg_set_webhook($id);
            flash(($r['ok'] ?? false) ? 'Webhook установлен' : 'Ошибка: ' . ($r['description'] ?? '?'), ($r['ok'] ?? false) ? 'ok' : 'err');
        } elseif ($do === 'check') {
            $r = tg($id, 'getWebhookInfo');
            $wi = $r['result'] ?? [];
            $msg = !empty($wi['url']) ? 'Webhook: ' . $wi['url'] . ', в очереди: ' . (int)($wi['pending_update_count'] ?? 0) . (!empty($wi['last_error_message']) ? '. Ошибка: ' . $wi['last_error_message'] : '. Ошибок нет') : 'Webhook не установлен';
            flash($bot['name'] . ' — ' . $msg, !empty($wi['last_error_message']) || empty($wi['url']) ? 'err' : 'ok');
        } elseif ($do === 'delete') {
            if ((int)val('SELECT COUNT(*) FROM users WHERE bot_id = ?', [$id]) > 0) flash('У бота есть пользователи — отключите его вместо удаления', 'err');
            else {
                tg($id, 'deleteWebhook');
                q('UPDATE flows SET bot_id = 0 WHERE bot_id = ?', [$id]); q('DELETE FROM broadcasts WHERE bot_id = ? AND status = \'draft\'', [$id]);
                q('DELETE FROM bots WHERE id = ?', [$id]); flash('Бот удалён');
            }
        }
    }
    redirect('?p=bots');
}
$list = rows('SELECT b.*, (SELECT COUNT(*) FROM users u WHERE u.bot_id = b.id) users, (SELECT COUNT(*) FROM users u WHERE u.bot_id = b.id AND u.status = \'blocked\') blocked,
  (SELECT COUNT(*) FROM flows f WHERE f.bot_id = b.id) flows FROM bots b ORDER BY b.id');
?>
<?= pagehead('Боты', '', 'Любое количество ботов. Подписчики, цепочки и рассылки привязаны к боту; группы — общие.') ?>
<div class="card"><h2>Подключить бота</h2>
<p class="hint" style="margin:0 0 10px">Токен выдаёт @BotFather (<code>/newbot</code>). Webhook установится автоматически — нужен HTTPS.</p>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="add">
<input name="token" placeholder="123456:ABC-DEF..." style="min-width:340px" autocomplete="off"><input name="name" placeholder="Название (необязательно)" style="min-width:200px"><button class="btn">Подключить</button></form></div>
<div class="card"><?php foreach ($list as $b): ?>
<div style="padding:12px 0;border-bottom:1px solid var(--line)">
<div class="row"><b style="font-size:16px"><?= h($b['name']) ?></b><?= $b['username'] ? '<a href="https://t.me/' . h($b['username']) . '" target="_blank" rel="noopener">@' . h($b['username']) . '</a>' : '' ?>
<?= $b['active'] ? '<span class="badge g">активен</span>' : '<span class="badge y">отключён</span>' ?>
<span class="muted">пользователей: <a href="?p=users&bot=<?= $b['id'] ?>"><?= $b['users'] ?></a>, заблокировали: <?= $b['blocked'] ?>, цепочек: <?= $b['flows'] ?></span></div>
<form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>">
<input name="name" value="<?= h($b['name']) ?>" style="width:220px"><button class="btn sm sec" name="do" value="rename">Переименовать</button>
<button class="btn sm sec" name="do" value="webhook">Установить webhook</button><button class="btn sm sec" name="do" value="check">Проверить</button>
<button class="btn sm sec" name="do" value="toggle"><?= $b['active'] ? 'Отключить' : 'Включить' ?></button>
<button class="btn sm red" name="do" value="delete" data-confirm="Удалить бота? Возможно только если у него нет пользователей.">Удалить</button></form></div>
<?php endforeach; if (!$list) echo empty_state('bot', 'Ботов пока нет', 'Подключите первого бота по токену выше.'); ?></div>
