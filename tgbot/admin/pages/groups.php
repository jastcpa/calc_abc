<?php
$title = 'Группы';
if (is_post()) {
    $do = post('do');
    need('groups.edit');
    if (in_array($do, ['edit', 'delete'], true) && !unrestricted()) { flash('Переименование и удаление групп доступны только при доступе ко всем ботам', 'err'); redirect('?p=groups'); }
    if ($do === 'add') {
        $name = mb_substr(post('name'), 0, 60);
        if ($name === '') flash('Введите название', 'err');
        elseif (val('SELECT 1 FROM groups WHERE name = ?', [$name])) flash('Такая группа уже есть', 'err');
        else { insert('groups', ['name' => $name, 'color' => post('color', '#3b82f6'), 'created_at' => now()]); flash('Группа создана'); }
    } elseif ($do === 'edit') {
        $id = (int)post('id'); $name = mb_substr(post('name'), 0, 60);
        if ($name !== '' && !val('SELECT 1 FROM groups WHERE name = ? AND id <> ?', [$name, $id])) {
            update('groups', $id, ['name' => $name, 'color' => post('color', '#3b82f6')]); flash('Сохранено');
        } else flash('Название пустое или занято', 'err');
    } elseif ($do === 'delete') {
        $id = (int)post('id');
        q('DELETE FROM user_groups WHERE group_id = ?', [$id]);
        q('DELETE FROM in_rules WHERE group_id = ?', [$id]);
        q('DELETE FROM groups WHERE id = ?', [$id]);
        flash('Группа удалена');
    }
    redirect('?p=groups');
}
$list = rows('SELECT g.*, (SELECT COUNT(*) FROM user_groups ug' . (bot_scope() === null ? '' : ' JOIN users uu ON uu.id = ug.user_id' . scope_sql('uu.bot_id')) . ' WHERE ug.group_id = g.id) AS cnt FROM groups g ORDER BY g.name');
?>
<?= pagehead('Группы', '', 'Группы общие для всех ботов. Подписчики попадают в них автоматически (вход в цепочку, шаг, клик по кнопке, внешний постбек) или вручную из раздела «Подписчики». Группы выбираются при настройке рассылки.') ?>
<div class="card"><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="add">
<input name="name" placeholder="Название новой группы" style="min-width:260px"><input type="color" name="color" value="#3b82f6" style="width:50px;padding:2px">
<button class="btn">Создать</button></form>
</div>
<div class="card">
<?php foreach ($list as $g): ?><form method="post" class="row" style="padding:6px 0;border-bottom:1px solid var(--line)"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $g['id'] ?>">
<input type="color" name="color" value="<?= h($g['color']) ?>" style="width:44px;padding:2px"><input name="name" value="<?= h($g['name']) ?>" style="min-width:240px">
<a href="?p=users&group=<?= $g['id'] ?>" style="min-width:110px"><?= (int)$g['cnt'] ?> польз.</a>
<button class="btn sm" name="do" value="edit">Сохранить</button><button class="btn sm red" name="do" value="delete" data-confirm="Удалить группу? Участники останутся, но выйдут из группы.">Удалить</button></form>
<?php endforeach; if (!$list): ?><?= empty_state('tag', 'Групп пока нет', 'Создайте первую группу, например «Купили» или «Прогрев».') ?><?php endif; ?></div>
