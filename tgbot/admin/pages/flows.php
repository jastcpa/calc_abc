<?php
$title = 'Цепочки';
if (is_post()) {
    $do = post('do');
    need('flows.edit');
    if ($do === 'add') {
        $name = mb_substr(post('name'), 0, 80) ?: 'Новая цепочка';
        $bot = (int)post('bot_id');
        need_scope($bot);
        $hasDefault = (int)val('SELECT COUNT(*) FROM flows WHERE is_default = 1 AND bot_id = ?', [$bot]);
        $id = insert('flows', ['bot_id' => $bot, 'name' => $name, 'source' => mb_substr(post('source'), 0, 64), 'is_default' => $hasDefault ? 0 : 1, 'active' => 1, 'group_id' => 0, 'created_at' => now()]);
        insert('steps', ['flow_id' => $id, 'position' => 0, 'delay' => 0, 'body' => 'Привет, {first_name}!']);
        redirect('?p=flow&id=' . $id);
    } elseif ($do === 'delete') {
        $id = (int)post('id');
        $fl = row('SELECT bot_id FROM flows WHERE id = ?', [$id]);
        if (!$fl || !bot_ok((int)$fl['bot_id'])) { flash('Нет доступа к этой цепочке', 'err'); redirect('?p=flows'); }
        q('DELETE FROM steps WHERE flow_id = ?', [$id]); q('DELETE FROM user_flows WHERE flow_id = ?', [$id]); q('DELETE FROM flows WHERE id = ?', [$id]);
        flash('Цепочка удалена');
    }
    redirect('?p=flows');
}
$list = rows('SELECT f.*, (SELECT COUNT(*) FROM steps s WHERE s.flow_id = f.id) steps,
  (SELECT COUNT(*) FROM user_flows uf WHERE uf.flow_id = f.id) enrolled,
  (SELECT COUNT(*) FROM user_flows uf WHERE uf.flow_id = f.id AND uf.status = \'done\') done,
  (SELECT COUNT(*) FROM user_flows uf WHERE uf.flow_id = f.id AND uf.status = \'active\') act FROM flows f WHERE 1=1' . scope_sql('f.bot_id') . (effective_bot() ? ' AND f.bot_id IN (0, ' . effective_bot() . ')' : '') . ' ORDER BY f.id');
?>
<?= pagehead('Цепочки сообщений', can('flows.edit') ? '<a class="btn" href="#new">' . icon('plus') . ' Новая цепочка</a>' : '', 'Каждое сообщение отправляется через заданное время после предыдущего. Цепочка выбирается по боту и источнику подписчика (?start=источник), иначе — цепочка «по умолчанию».') ?>
<?php if (effective_bot()): ?><div class="row" style="margin-bottom:12px"><span class="chipx"><?= icon('bot') ?> <?= h(bot_label(effective_bot())) ?> + общие <a href="?p=flows&bot=0" aria-label="Показать все">×</a></span></div><?php endif; ?>
<?php if (!$list): ?><div class="card"><?= empty_state('flows', 'Цепочек пока нет', 'Создайте приветственную цепочку — она запустится, когда подписчик нажмёт /start.') ?></div><?php else: ?>
<div class="card" style="padding:0"><div class="table-wrap"><table><tr><th>Цепочка</th><th>Бот</th><th>Источник</th><th>Шагов</th><th>Вошло</th><th>В процессе</th><th>Завершили</th><th></th></tr>
<?php foreach ($list as $f): ?><tr><td><a href="?p=flow&id=<?= $f['id'] ?>"><b><?= h($f['name']) ?></b></a>
<?= $f['is_default'] ? ' <span class="badge g">по умолчанию</span>' : '' ?><?= $f['active'] ? '' : ' <span class="badge y">выключена</span>' ?></td>
<td><?= h(bot_label((int)$f['bot_id'])) ?></td>
<td><?= $f['source'] ? '<code>' . h($f['source']) . '</code>' : '<span class="muted">любой</span>' ?></td><td><?= $f['steps'] ?></td><td><?= $f['enrolled'] ?></td><td><?= $f['act'] ?></td><td><?= $f['done'] ?></td>
<td class="row" style="flex-wrap:nowrap"><a class="btn sm sec" href="?p=flow&id=<?= $f['id'] ?>">Открыть</a><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn sm sec danger" data-confirm="Цепочка «<?= h($f['name']) ?>» будет удалена вместе со всеми шагами и прогрессом подписчиков." aria-label="Удалить">×</button></form></td></tr>
<?php endforeach; ?></table></div></div><?php endif; ?>
<?php if (can('flows.edit')): ?><div class="card" id="new"><h2>Новая цепочка</h2><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="add"><input name="name" placeholder="Название, например «Приветствие»" style="min-width:260px;flex:1" required>
<select name="bot_id" aria-label="Бот"><?= bot_options(effective_bot(), 'Для всех ботов') ?></select><input name="source" placeholder="Источник (необязательно): fb_ads" style="max-width:260px">
<button class="btn">Создать и открыть</button></form><p class="hint">Источник — значение после <code>?start=</code> в ссылке на бота. Пусто — цепочка подходит всем.</p></div><?php endif; ?>
