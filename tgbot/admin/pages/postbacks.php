<?php
$title = 'Постбеки';
$types = ['start' => '/start (вход пользователя)', 'click' => 'Клик по кнопке', 'group_add' => 'Добавление в группу', 'postback' => 'Входящий постбек', 'flow_done' => 'Цепочка завершена', 'blocked' => 'Заблокировал бота', 'bc_sent' => 'Получил рассылку', 'step_sent' => 'Получил шаг цепочки'];
if (is_post()) {
    $do = post('do');
    need('postbacks.edit');
    if ($do === 'rule_add') {
        $ev = mb_substr(post('event'), 0, 100); $gid = (int)post('group_id');
        if ($ev === '' || !$gid) flash('Укажите событие и группу', 'err');
        else { insert('in_rules', ['event' => $ev, 'action' => post('action') === 'remove' ? 'remove' : 'add', 'group_id' => $gid]); flash('Правило добавлено'); }
    } elseif ($do === 'rule_del') { q('DELETE FROM in_rules WHERE id = ?', [(int)post('id')]); flash('Правило удалено'); }
    elseif ($do === 'hook_add') {
        $url = post('url');
        if (!preg_match('~^https?://~i', $url) || post('name') === '') flash('Нужны название и URL (http/https)', 'err');
        else { insert('out_hooks', ['name' => mb_substr(post('name'), 0, 80), 'url' => $url, 'method' => post('method') === 'POST' ? 'POST' : 'GET',
            'event_type' => isset($types[post('event_type')]) ? post('event_type') : 'start', 'filter' => post('filter'), 'active' => 1]); flash('Постбек добавлен'); }
    } elseif ($do === 'hook_del') { q('DELETE FROM out_hooks WHERE id = ?', [(int)post('id')]); q("DELETE FROM out_queue WHERE hook_id = ? AND status='pending'", [(int)post('id')]); flash('Удалено'); }
    elseif ($do === 'hook_toggle') { q('UPDATE out_hooks SET active = 1 - active WHERE id = ?', [(int)post('id')]); }
    elseif ($do === 'regen') { set_setting('postback_key', rand_token(16)); flash('Ключ обновлён'); }
    redirect('?p=postbacks');
}
$rules = rows('SELECT r.*, g.name gname FROM in_rules r LEFT JOIN groups g ON g.id = r.group_id ORDER BY r.event');
$hooks = rows('SELECT * FROM out_hooks ORDER BY id');
$recent = rows("SELECT e.*, u.tg_id, u.first_name, u.username, u.id uid FROM events e LEFT JOIN users u ON u.id = e.user_id WHERE e.type = 'postback' ORDER BY e.id DESC LIMIT 15");
$queue = rows('SELECT q.*, h.name FROM out_queue q JOIN out_hooks h ON h.id = q.hook_id ORDER BY q.id DESC LIMIT 15');
$pb = base_url() . '/postback.php?key=' . setting('postback_key');
?>
<?= pagehead('Постбеки', '', 'Входящие события из внешних систем формируют группы; события бота можно отправлять во внешние трекеры.') ?>
<div class="card"><h2>⬇ Входящие постбеки (внешние события → группы)</h2>
<p>Внешняя система (трекер, CRM, платёжка) сообщает о событии пользователя по запросу GET или POST:</p>
<p><code data-copy><?= h($pb) ?>&event=purchase&label={click_id}</code></p>
<p class="muted">Пользователь определяется по <code>label</code> (метка из ссылки <code>?start=источник__МЕТКА</code>), либо по <code>tg_id</code>, либо по <code>uid</code>. Необязательно: <code>value</code> (сумма и т.п.). Ответ — JSON.</p>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="regen"><button class="btn sm sec" data-confirm="Старый ключ перестанет работать. Продолжить?">Сменить ключ</button></form>
<h3 style="margin-top:14px">Правила: событие → группа</h3>
<div class="table-wrap"><table><tr><th>Событие (event)</th><th>Действие</th><th>Группа</th><th></th></tr>
<?php foreach ($rules as $r): ?><tr><td><code><?= h($r['event']) ?></code></td><td><?= $r['action'] === 'remove' ? 'убрать из группы' : 'добавить в группу' ?></td><td><?= h($r['gname'] ?? '— удалена —') ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="rule_del"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn sm sec">×</button></form></td></tr><?php endforeach; ?>
<tr><td><input name="event" form="rf" placeholder="purchase"></td>
<td><select name="action" form="rf"><option value="add">добавить в группу</option><option value="remove">убрать из группы</option></select></td><td><select name="group_id" form="rf"><?= group_options(0, '— группа —') ?></select></td><td><button class="btn sm" form="rf">Добавить</button></td></tr></table></div>
<form method="post" id="rf"><?= csrf_field() ?><input type="hidden" name="do" value="rule_add"></form>
<?php if ($recent): ?><h3 style="margin-top:14px">Последние входящие</h3><div class="table-wrap"><table><?php foreach ($recent as $e): ?><tr><td><?= fmt_dt((int)$e['created_at'], 'd.m H:i:s') ?></td><td><code><?= h($e['ref']) ?></code></td><td><?= $e['uid'] ? '<a href="?p=user&id=' . $e['uid'] . '">' . h(user_name($e)) . '</a>' : '—' ?></td><td class="muted"><?= h($e['data']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div>

<div class="card"><h2>⬆ Исходящие постбеки (события бота → внешний URL)</h2>
<p class="muted">Макросы в URL: <code>{tg_id}</code> <code>{user_id}</code> <code>{label}</code> <code>{source}</code> <code>{username}</code> <code>{event}</code> <code>{ref}</code> <code>{value}</code> <code>{group}</code>. Метка пользователя (<code>{label}</code>) передаётся обратно в вашу систему. Для POST тело — JSON с теми же полями. Неудачные отправки повторяются до 5 раз.</p>
<div class="table-wrap"><table><tr><th>Название</th><th>Событие</th><th>URL</th><th></th></tr>
<?php foreach ($hooks as $hk): ?><tr><td><?= h($hk['name']) ?> <?= $hk['active'] ? '' : '<span class="badge y">выкл</span>' ?></td>
<td><?= h($types[$hk['event_type']] ?? $hk['event_type']) ?><?= $hk['filter'] !== '' ? '<br><code>' . h($hk['filter']) . '</code>' : '' ?></td>
<td><span class="badge"><?= h($hk['method']) ?></span> <code><?= h($hk['url']) ?></code></td>
<td class="row"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $hk['id'] ?>"><button class="btn sm sec" name="do" value="hook_toggle"><?= $hk['active'] ? 'Выкл' : 'Вкл' ?></button> <button class="btn sm red" name="do" value="hook_del" data-confirm="Удалить?">×</button></form></td></tr><?php endforeach; ?></table></div>
<h3 style="margin-top:14px">Новый исходящий постбек</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="hook_add">
<div class="grid3"><div><label>Название</label><input name="name" placeholder="Трекер: регистрация"></div>
<div><label>Событие</label><select name="event_type"><?php foreach ($types as $k => $v) echo '<option value="' . $k . '">' . h($v) . '</option>'; ?></select></div>
<div><label>Фильтр (необязательно)</label><input name="filter" placeholder="ID группы / s12 / s12:0 / имя события"></div></div>
<div class="grid2"><div><label>URL</label><input name="url" placeholder="https://tracker.example/postback?click={label}&event={event}&tg={tg_id}"></div>
<div><label>Метод</label><select name="method"><option>GET</option><option>POST</option></select></div></div>
<p><button class="btn">Добавить</button></p></form>
<?php if ($queue): ?><h3>Последние отправки</h3><div class="table-wrap"><table><?php foreach ($queue as $j): ?><tr><td><?= fmt_dt((int)$j['created_at'], 'd.m H:i:s') ?></td><td><?= h($j['name']) ?></td>
<td><span class="badge <?= $j['status'] === 'sent' ? 'g' : ($j['status'] === 'failed' ? 'r' : 'y') ?>"><?= h($j['status']) ?></span> <?= $j['code'] ?: '' ?></td><td class="mono"><?= h(mb_substr($j['url'], 0, 90)) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div>
