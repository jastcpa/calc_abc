<?php
$title = 'Рассылки';
if (is_post()) {
    if (post('do') === 'add') {
        need('broadcasts.edit'); need_scope((int)post('bot_id'));
        $id = insert('broadcasts', ['bot_id' => (int)post('bot_id'), 'name' => mb_substr(post('name'), 0, 80) ?: 'Новая рассылка', 'body' => 'Текст рассылки', 'created_at' => now()]);
        redirect('?p=broadcast&id=' . $id);
    }
    redirect('?p=broadcasts');
}
$list = rows('SELECT * FROM broadcasts WHERE 1=1' . scope_sql('bot_id') . (effective_bot() ? ' AND bot_id IN (0, ' . effective_bot() . ')' : '') . ' ORDER BY id DESC');
$names = ['draft' => ['черновик', ''], 'scheduled' => ['запланирована', 'y'], 'sending' => ['отправляется', 'y'], 'done' => ['завершена', 'g'], 'cancelled' => ['отменена', 'r']];
$grp = array_column(rows('SELECT id, name FROM groups'), 'name', 'id');
?>
<?= pagehead('Рассылки', can('broadcasts.edit') ? '<a class="btn" href="#new">' . icon('plus') . ' Новая рассылка</a>' : '') ?>
<?php if (effective_bot()): ?><div class="row" style="margin-bottom:12px"><span class="chipx"><?= icon('bot') ?> <?= h(bot_label(effective_bot())) ?> + для всех ботов <a href="?p=broadcasts&bot=0" aria-label="Показать все">×</a></span></div><?php endif; ?>
<?php if (!$list): ?><div class="card"><?= empty_state('send', 'Рассылок пока нет', 'Подготовьте сообщение, выберите аудиторию по группам и отправьте сейчас или по расписанию.') ?></div><?php else: ?>
<div class="card" style="padding:0"><div class="table-wrap"><table><tr><th>Рассылка</th><th>Бот</th><th>Статус</th><th>Время отправки</th><th>Кому</th><th>Доставлено</th></tr>
<?php foreach ($list as $b): $inc = json_arr($b['inc_groups']); $exc = json_arr($b['exc_groups']); ?><tr>
<td><a href="?p=broadcast&id=<?= $b['id'] ?>"><b><?= h($b['name']) ?></b></a></td>
<td><?= h(bot_label((int)$b['bot_id'])) ?></td>
<td><span class="badge <?= $names[$b['status']][1] ?>"><?= $names[$b['status']][0] ?></span></td>
<td><?= $b['status'] === 'draft' ? '<span class="muted">—</span>' : fmt_dt((int)$b['send_at']) ?></td>
<td><?= $inc ? 'группы: ' . h(implode(', ', array_map(fn($i) => $grp[$i] ?? '?', $inc))) : 'все активные' ?><?= $exc ? '<br><span class="muted">кроме: ' . h(implode(', ', array_map(fn($i) => $grp[$i] ?? '?', $exc))) . '</span>' : '' ?></td>
<td><?php if (in_array($b['status'], ['draft', 'scheduled'], true)) echo '<span class="muted">—</span>'; else { $pc = $b['total'] ? round($b['sent'] / $b['total'] * 100) : 0; echo $b['sent'] . ' / ' . $b['total'] . ' <span class="muted">(' . $pc . '%)</span>' . ($b['failed'] ? ' <span class="danger">ошибок: ' . $b['failed'] . '</span>' : ''); } ?></td></tr>
<?php endforeach; ?></table></div></div><?php endif; ?>
<?php if (can('broadcasts.edit')): ?><div class="card" id="new"><h2>Новая рассылка</h2><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="add"><input name="name" placeholder="Название (видно только вам)" style="min-width:260px;flex:1" required>
<select name="bot_id" aria-label="Бот"><?= bot_options(effective_bot(), 'Все боты') ?></select><button class="btn">Создать и открыть</button></form></div><?php endif; ?>
