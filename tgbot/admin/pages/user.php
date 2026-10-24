<?php
$id = (int)($_GET['id'] ?? 0);
$u = row('SELECT * FROM users WHERE id = ?', [$id]);
if (!$u || !bot_ok((int)$u['bot_id'])) redirect('?p=users');
$title = user_name($u);
if (is_post()) {
    $do = post('do');
    need($do === 'delete' ? 'users.delete' : 'users.edit');
    if ($do === 'add_group') { group_add($id, (int)post('group_id'), 'manual'); flash('Добавлен в группу'); }
    elseif ($do === 'del_group') { group_remove($id, (int)post('group_id')); flash('Удалён из группы'); }
    elseif ($do === 'delete') {
        foreach (['user_groups', 'user_flows', 'bq'] as $t) q("DELETE FROM $t WHERE user_id = ?", [$id]);
        q('DELETE FROM events WHERE user_id = ?', [$id]); q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Пользователь удалён'); redirect('?p=users');
    }
    redirect('?p=user&id=' . $id);
}
$groups = rows('SELECT g.* FROM user_groups ug JOIN groups g ON g.id = ug.group_id WHERE ug.user_id = ? ORDER BY g.name', [$id]);
$flows = rows('SELECT uf.*, f.name FROM user_flows uf JOIN flows f ON f.id = uf.flow_id WHERE uf.user_id = ?', [$id]);
$events = rows('SELECT * FROM events WHERE user_id = ? ORDER BY id DESC LIMIT 200', [$id]);
$evNames = ['start' => '▶ /start', 'step_sent' => '✉ Шаг цепочки отправлен', 'click' => '👆 Клик по кнопке', 'bc_sent' => '📣 Рассылка получена', 'group_add' => '➕ В группу', 'group_remove' => '➖ Из группы',
    'postback' => '🔁 Внешнее событие', 'message' => '💬 Сообщение боту', 'blocked' => '🚫 Заблокировал бота', 'unblocked' => '✅ Разблокировал', 'flow_start' => '🔗 Вход в цепочку', 'flow_done' => '🏁 Цепочка завершена', 'send_error' => '⚠ Ошибка отправки'];
?>
<h1><?= h(user_name($u)) ?> <?= $u['status'] === 'blocked' ? '<span class="badge r">заблокировал бота</span>' : '' ?></h1>
<div class="grid2">
<div class="card"><h2>Профиль</h2>
<p>Бот: <b><?= h(bot_label((int)$u['bot_id'])) ?></b> · Telegram ID: <code><?= $u['tg_id'] ?></code><?= $u['username'] ? ' · <a href="https://t.me/' . h($u['username']) . '" target="_blank" rel="noopener">@' . h($u['username']) . '</a>' : '' ?></p>
<p>Источник: <b><?= h($u['source'] ?: '—') ?></b> · Метка: <code><?= h($u['label'] ?: '—') ?></code></p>
<p>Start-параметр: <code><?= h($u['start_param'] ?: '—') ?></code></p>
<p>Пришёл: <?= fmt_dt((int)$u['created_at']) ?> · Активность: <?= fmt_dt((int)$u['last_seen']) ?></p>
<?php if (can('users.delete')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><button class="btn sm red" data-confirm="Удалить подписчика и историю?">Удалить подписчика</button></form><?php endif; ?></div>
<div class="card"><h2>Группы</h2>
<?php foreach ($groups as $g): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_group"><input type="hidden" name="group_id" value="<?= $g['id'] ?>"><?= group_tag($g) ?><button class="btn sm sec" title="убрать">×</button> </form><?php endforeach; if (!$groups) echo '<p class="muted">Нет групп</p>'; ?>
<form method="post" class="row" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="do" value="add_group"><select name="group_id"><?= group_options(0, '— добавить в группу —') ?></select><button class="btn sm">Добавить</button></form>
<h3 style="margin-top:16px">Цепочки</h3>
<?php foreach ($flows as $f): ?><p><?= h($f['name']) ?> — <span class="badge"><?= h($f['status']) ?></span>, отправлено шагов: <?= (int)$f['last_pos'] + 1 ?><?= $f['status'] === 'active' ? ', следующий: ' . fmt_dt((int)$f['next_at'], 'd.m H:i') : '' ?></p><?php endforeach; if (!$flows) echo '<p class="muted">Не в цепочках</p>'; ?></div></div>
<div class="card"><h2>История действий</h2><table><tr><th>Время</th><th>Событие</th><th>Подробности</th></tr>
<?php foreach ($events as $e): ?><tr><td><?= fmt_dt((int)$e['created_at'], 'd.m H:i:s') ?></td><td><?= h($evNames[$e['type']] ?? $e['type']) ?></td>
<td><?php
 if (in_array($e['type'], ['click', 'step_sent', 'bc_sent'], true)) echo h(msg_label(explode(':', $e['ref'])[0])) . ($e['label'] ? ' → «' . h($e['label']) . '»' : '');
 else echo h($e['label'] ?: $e['ref']);
?></td></tr><?php endforeach; ?></table></div>
