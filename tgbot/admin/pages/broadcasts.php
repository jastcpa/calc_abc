<?php
$title = 'Рассылки';
if (is_post()) {
    if (post('do') === 'add') {
        $id = insert('broadcasts', ['name' => mb_substr(post('name'), 0, 80) ?: 'Новая рассылка', 'body' => 'Текст рассылки', 'created_at' => now()]);
        redirect('?p=broadcast&id=' . $id);
    }
    redirect('?p=broadcasts');
}
$list = rows('SELECT * FROM broadcasts ORDER BY id DESC');
$names = ['draft' => ['черновик', ''], 'scheduled' => ['запланирована', 'y'], 'sending' => ['отправляется', 'y'], 'done' => ['завершена', 'g'], 'cancelled' => ['отменена', 'r']];
$grp = array_column(rows('SELECT id, name FROM groups'), 'name', 'id');
?>
<h1>Рассылки</h1>
<div class="card"><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="add"><input name="name" placeholder="Название рассылки" style="min-width:260px"><button class="btn">Создать рассылку</button></form></div>
<div class="card"><table><tr><th>Рассылка</th><th>Статус</th><th>Время отправки</th><th>Кому</th><th>Отправлено</th></tr>
<?php foreach ($list as $b): $inc = json_arr($b['inc_groups']); $exc = json_arr($b['exc_groups']); ?><tr>
<td><a href="?p=broadcast&id=<?= $b['id'] ?>"><b><?= h($b['name']) ?></b></a></td>
<td><span class="badge <?= $names[$b['status']][1] ?>"><?= $names[$b['status']][0] ?></span></td>
<td><?= $b['status'] === 'draft' ? '—' : fmt_dt((int)$b['send_at']) ?></td>
<td><?= $inc ? 'группы: ' . h(implode(', ', array_map(fn($i) => $grp[$i] ?? '?', $inc))) : 'все активные' ?><?= $exc ? '<br><span class="muted">кроме: ' . h(implode(', ', array_map(fn($i) => $grp[$i] ?? '?', $exc))) . '</span>' : '' ?></td>
<td><?= $b['status'] === 'draft' || $b['status'] === 'scheduled' ? '—' : $b['sent'] . ' / ' . $b['total'] . ($b['failed'] ? ' <span class="danger">(ошибок: ' . $b['failed'] . ')</span>' : '') ?></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="5" class="muted">Рассылок пока нет</td></tr>'; ?></table></div>
