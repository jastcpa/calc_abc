<?php
$title = 'Роли и права';
if (is_post()) {
    $do = post('do');
    $id = (int)post('id');
    $role = $id ? row('SELECT * FROM admin_roles WHERE id = ?', [$id]) : null;
    $name = mb_substr(post('name'), 0, 60);
    // выбранные права; любое «действие» автоматически включает просмотр своего раздела
    $perms = array_values(array_intersect(all_perms(), array_map('strval', (array)($_POST['perms'] ?? []))));
    foreach (PERM_GROUPS as $g) {
        $keys = array_keys($g);
        $view = $keys[0];
        if (array_intersect($keys, $perms) && !in_array($view, $perms, true)) $perms[] = $view;
    }
    $perms = array_values(array_intersect(all_perms(), $perms));

    if ($do === 'add') {
        if ($name === '') flash('Введите название роли', 'err');
        elseif (val('SELECT 1 FROM admin_roles WHERE name = ? COLLATE NOCASE', [$name])) flash('Такая роль уже есть', 'err');
        else { $rid = insert('admin_roles', ['name' => $name, 'description' => mb_substr(post('description'), 0, 200), 'perms' => json_encode($perms), 'is_admin' => 0, 'created_at' => now()]); audit('roles.add', $name); redirect('?p=roles&edit=' . $rid); }
    } elseif ($role && $do === 'update') {
        if ($name === '' || val('SELECT 1 FROM admin_roles WHERE name = ? COLLATE NOCASE AND id <> ?', [$name, $id])) flash('Название пустое или занято', 'err');
        else {
            // права системной роли администратора менять нельзя
            update('admin_roles', $id, ['name' => $name, 'description' => mb_substr(post('description'), 0, 200)] + ($role['is_admin'] ? [] : ['perms' => json_encode($perms)]));
            audit('roles.update', $name); flash('Роль сохранена');
        }
        redirect('?p=roles&edit=' . $id);
    } elseif ($role && $do === 'delete') {
        if ($role['is_admin']) flash('Роль администратора удалить нельзя', 'err');
        elseif ((int)val('SELECT COUNT(*) FROM admin_users WHERE role_id = ?', [$id]) > 0) flash('Сначала переведите пользователей этой роли на другую', 'err');
        else { q('DELETE FROM admin_roles WHERE id = ?', [$id]); audit('roles.delete', $role['name']); flash('Роль удалена'); }
    }
    redirect('?p=roles');
}

$edit = $_GET['edit'] ?? '';
$er = ctype_digit((string)$edit) ? row('SELECT * FROM admin_roles WHERE id = ?', [(int)$edit]) : null;
if ($edit === 'new' || $er):
    $isNew = !$er;
    $er ??= ['id' => 0, 'name' => '', 'description' => '', 'perms' => '[]', 'is_admin' => 0];
    $have = $er['is_admin'] ? all_perms() : json_arr($er['perms']);
?>
<p><a href="?p=roles">← Роли</a></p>
<h1><?= $isNew ? 'Новая роль' : h($er['name']) ?></h1>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $isNew ? 'add' : 'update' ?>"><input type="hidden" name="id" value="<?= (int)$er['id'] ?>">
<div class="card"><div class="grid2"><div><label>Название</label><input name="name" value="<?= h($er['name']) ?>"></div><div><label>Описание</label><input name="description" value="<?= h($er['description']) ?>"></div></div>
<?php if ($er['is_admin']): ?><p class="muted">Администратор имеет полный доступ ко всему (включая пользователей, роли, ботов и настройки); права этой роли изменить нельзя.</p><?php endif; ?></div>
<div class="perm-grid"><?php foreach (PERM_GROUPS as $gname => $g): ?><div class="card"><h3><?= h($gname) ?></h3>
<?php foreach ($g as $k => $label): ?><label class="chk"><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?> <?= $er['is_admin'] ? 'disabled' : '' ?>> <?= h($label) ?></label><?php endforeach; ?></div><?php endforeach; ?></div>
<p class="muted" style="margin-top:12px">Права действий автоматически включают просмотр раздела. Какие боты доступны — настраивается у каждого пользователя отдельно (вкладка «Команда»).</p>
<p class="row"><button class="btn"><?= $isNew ? 'Создать роль' : 'Сохранить' ?></button>
<?php if (!$isNew && !$er['is_admin']): ?><button class="btn red" name="do" value="delete" data-confirm="Удалить роль?">Удалить</button><?php endif; ?></p></form>
<?php return; endif;

$list = rows('SELECT r.*, (SELECT COUNT(*) FROM admin_users a WHERE a.role_id = r.id) members FROM admin_roles r ORDER BY r.is_admin DESC, r.name');
?>
<?= pagehead('Роли и права', '<a class="btn" href="?p=roles&edit=new">' . icon('plus') . ' Роль</a><a class="btn sec" href="?p=team">Команда</a>', 'Роль — набор прав. Доступ к конкретным ботам задаётся у каждого пользователя отдельно.') ?>
<div class="card" style="margin-top:14px"><div class="table-wrap"><table><tr><th>Роль</th><th>Описание</th><th>Права</th><th>Пользователей</th><th></th></tr>
<?php foreach ($list as $r): $pp = $r['is_admin'] ? all_perms() : json_arr($r['perms']); ?><tr><td><b><?= h($r['name']) ?></b> <?= $r['is_admin'] ? '<span class="badge r">полный доступ</span>' : '' ?></td>
<td class="muted"><?= h($r['description']) ?></td><td><?= $r['is_admin'] ? 'все' : count($pp) . ' из ' . count(all_perms()) ?></td>
<td><a href="?p=team&role=<?= $r['id'] ?>"><?= $r['members'] ?></a></td><td><a class="btn sm sec" href="?p=roles&edit=<?= $r['id'] ?>">Изменить</a></td></tr><?php endforeach; ?></table></div></div>
<div class="card"><h2>Матрица прав</h2><div style="overflow-x:auto"><div class="table-wrap"><table><tr><th>Право</th><?php foreach ($list as $r) echo '<th>' . h($r['name']) . '</th>'; ?></tr>
<?php foreach (PERM_GROUPS as $gname => $g) foreach ($g as $k => $label): ?><tr><td><span class="muted"><?= h($gname) ?>:</span> <?= h(mb_substr($label, 0, 48)) ?></td>
<?php foreach ($list as $r): $on = $r['is_admin'] || in_array($k, json_arr($r['perms']), true); ?><td><?= $on ? '<span class="ok">✔</span>' : '<span class="muted">—</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div></div></div>
