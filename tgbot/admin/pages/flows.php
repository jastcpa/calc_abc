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
  (SELECT COUNT(*) FROM user_flows uf WHERE uf.flow_id = f.id AND uf.status = \'active\') act FROM flows f WHERE 1=1' . scope_sql('f.bot_id') . ' ORDER BY f.id');
?>
<h1>Цепочки сообщений</h1>
<div class="card"><p class="muted">Цепочка — последовательность сообщений: каждое отправляется через заданное время после предыдущего (первое — после входа пользователя). Цепочка выбирается для пользователя по его боту и источнику (параметр <code>?start=источник</code>): сначала цепочка этого бота с подходящим источником, затем общая («для всех ботов»), иначе — цепочка «по умолчанию» бота, затем общая по умолчанию.</p>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="add"><input name="name" placeholder="Название новой цепочки" style="min-width:240px"><select name="bot_id"><?= bot_options(0, 'Для всех ботов') ?></select><input name="source" placeholder="Источник (необязательно), напр. fb_ads">
<button class="btn">Создать цепочку</button></form></div>
<div class="card"><table><tr><th>Цепочка</th><th>Бот</th><th>Источник</th><th>Шагов</th><th>Вошло</th><th>В процессе</th><th>Завершили</th><th></th></tr>
<?php foreach ($list as $f): ?><tr><td><a href="?p=flow&id=<?= $f['id'] ?>"><b><?= h($f['name']) ?></b></a>
<?= $f['is_default'] ? ' <span class="badge g">по умолчанию</span>' : '' ?><?= $f['active'] ? '' : ' <span class="badge y">выключена</span>' ?></td>
<td><?= h(bot_label((int)$f['bot_id'])) ?></td>
<td><?= $f['source'] ? '<code>' . h($f['source']) . '</code>' : '<span class="muted">любой</span>' ?></td><td><?= $f['steps'] ?></td><td><?= $f['enrolled'] ?></td><td><?= $f['act'] ?></td><td><?= $f['done'] ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn sm red" data-confirm="Удалить цепочку со всеми шагами?">×</button></form></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="8" class="muted">Цепочек нет</td></tr>'; ?></table></div>
