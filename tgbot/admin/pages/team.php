<?php
$title = 'Команда';
$loginRe = '/^[A-Za-z0-9_.@-]{3,40}$/';
$roles = rows('SELECT * FROM admin_roles ORDER BY is_admin DESC, name');
$bots = rows('SELECT id, name, username FROM bots ORDER BY id');

if (is_post()) {
    $do = post('do');
    $id = (int)post('id');
    $target = $id ? row('SELECT a.*, r.is_admin FROM admin_users a JOIN admin_roles r ON r.id = a.role_id WHERE a.id = ?', [$id]) : null;
    $roleId = (int)post('role_id');
    $role = row('SELECT * FROM admin_roles WHERE id = ?', [$roleId]);
    $botIds = array_values(array_intersect(post_ids('bots'), array_map('intval', array_column($bots, 'id'))));
    $allBots = isset($_POST['all_bots']) || ($role && $role['is_admin']) ? 1 : 0;

    $save = function (int $uid) use ($botIds, $allBots) {
        q('DELETE FROM admin_user_bots WHERE admin_id = ?', [$uid]);
        if (!$allBots) foreach ($botIds as $b) q('INSERT INTO admin_user_bots (admin_id, bot_id) VALUES (?, ?)', [$uid, $b]);
    };

    if ($do === 'add') {
        $login = post('login'); $pass = (string)($_POST['password'] ?? '');
        if (!preg_match($loginRe, $login)) flash('Логин: 3–40 символов (латиница, цифры, . _ - @)', 'err');
        elseif (val('SELECT 1 FROM admin_users WHERE login = ? COLLATE NOCASE', [$login])) flash('Такой логин уже есть', 'err');
        elseif ($e = password_problem($pass)) flash($e, 'err');
        elseif (!$role) flash('Выберите роль', 'err');
        elseif (!$allBots && !$botIds) flash('Назначьте хотя бы одного бота или включите «все боты»', 'err');
        else {
            $uid = insert('admin_users', ['login' => $login, 'name' => mb_substr(post('name'), 0, 60), 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'role_id' => $roleId, 'all_bots' => $allBots, 'active' => 1, 'created_at' => now()]);
            $save($uid);
            audit('team.add', "$login / {$role['name']}");
            flash('Пользователь создан'); redirect('?p=team');
        }
        redirect('?p=team&edit=new');
    } elseif ($target && $do === 'update') {
        $active = isset($_POST['active']) ? 1 : 0;
        $pass = (string)($_POST['password'] ?? '');
        $wasAdmin = (bool)$target['is_admin'] && $target['active'];
        $willAdmin = $role && $role['is_admin'] && $active;
        $err = null;
        if (!$role) $err = 'Выберите роль';
        elseif ($wasAdmin && !$willAdmin && active_admin_count() <= 1) $err = 'Нельзя снять права или деактивировать последнего администратора';
        elseif ($id === (int)me()['id'] && !$active) $err = 'Нельзя деактивировать самого себя';
        elseif ($pass !== '' && ($e = password_problem($pass))) $err = $e;
        elseif (!$allBots && !$botIds) $err = 'Назначьте хотя бы одного бота или включите «все боты»';
        if ($err) { flash($err, 'err'); redirect('?p=team&edit=' . $id); }
        $upd = ['name' => mb_substr(post('name'), 0, 60), 'role_id' => $roleId, 'all_bots' => $allBots, 'active' => $active];
        if ($pass !== '') $upd['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        if ($pass !== '' || !$active) $upd['sess_ver'] = (int)$target['sess_ver'] + 1;   // выкидываем активные сессии
        update('admin_users', $id, $upd);
        $save($id);
        audit('team.update', $target['login'] . ($pass !== '' ? ' (пароль сброшен)' : ''));
        flash('Сохранено'); redirect('?p=team');
    } elseif ($target && $do === 'delete') {
        if ($id === (int)me()['id']) flash('Нельзя удалить самого себя', 'err');
        elseif ($target['is_admin'] && $target['active'] && active_admin_count() <= 1) flash('Нельзя удалить последнего администратора', 'err');
        else {
            q('DELETE FROM admin_user_bots WHERE admin_id = ?', [$id]); q('DELETE FROM admin_users WHERE id = ?', [$id]);
            audit('team.delete', $target['login']); flash('Пользователь удалён');
        }
    }
    redirect('?p=team');
}

$edit = $_GET['edit'] ?? '';
$eu = ctype_digit((string)$edit) ? auth_load((int)$edit) : null;
if ($edit === 'new' || $eu):
    $isNew = !$eu;
    $eu ??= ['id' => 0, 'login' => '', 'name' => '', 'role_id' => (int)val('SELECT id FROM admin_roles WHERE is_admin = 0 ORDER BY id LIMIT 1'), 'all_bots' => 0, 'active' => 1, 'bots' => []];
?>
<p><a href="?p=team">← Команда</a></p>
<h1><?= $isNew ? 'Новый пользователь' : h($eu['login']) ?></h1>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $isNew ? 'add' : 'update' ?>"><input type="hidden" name="id" value="<?= (int)$eu['id'] ?>">
<div class="grid2"><div><label>Логин</label><input name="login" value="<?= h($eu['login']) ?>" <?= $isNew ? '' : 'disabled' ?> autocomplete="off"></div>
<div><label>Имя</label><input name="name" value="<?= h($eu['name']) ?>"></div></div>
<div class="grid2"><div><label><?= $isNew ? 'Пароль (от 8 символов)' : 'Новый пароль (оставьте пустым, чтобы не менять)' ?></label><input type="password" name="password" autocomplete="new-password"></div>
<div><label>Роль</label><select name="role_id"><?php foreach ($roles as $r) echo '<option value="' . $r['id'] . '"' . ($r['id'] == $eu['role_id'] ? ' selected' : '') . '>' . h($r['name']) . '</option>'; ?></select></div></div>
<h3 style="margin-top:16px">Доступ к ботам</h3>
<label class="chk"><input type="checkbox" name="all_bots" id="allbots" <?= $eu['all_bots'] ? 'checked' : '' ?>> Все боты (включая будущие) <span class="muted">— нужно для постбеков, общих групп и цепочек «для всех ботов»; у администратора всегда включено</span></label>
<div id="botlist" class="<?= $eu['all_bots'] ? 'dim' : '' ?>"><?php foreach ($bots as $b): ?><label class="chk"><input type="checkbox" name="bots[]" value="<?= $b['id'] ?>" <?= in_array((int)$b['id'], $eu['bots'], true) ? 'checked' : '' ?>> <?= h($b['name']) ?> <span class="muted"><?= $b['username'] ? '@' . h($b['username']) : '' ?></span></label><?php endforeach; if (!$bots) echo '<p class="muted">Ботов пока нет</p>'; ?></div>
<?php if (!$isNew): ?><label class="chk"><input type="checkbox" name="active" <?= $eu['active'] ? 'checked' : '' ?>> Активен (вход разрешён)</label><?php else: ?><input type="hidden" name="active" value="1"><?php endif; ?>
<p class="row"><button class="btn"><?= $isNew ? 'Создать' : 'Сохранить' ?></button>
<?php if (!$isNew && (int)$eu['id'] !== (int)me()['id']): ?><button class="btn red" name="do" value="delete" data-confirm="Удалить пользователя <?= h($eu['login']) ?>?">Удалить</button><?php endif; ?></p></form>
<?php return; endif;

$fr = (int)($_GET['role'] ?? 0);
$list = rows('SELECT a.*, r.name role_name, r.is_admin FROM admin_users a JOIN admin_roles r ON r.id = a.role_id' . ($fr ? ' WHERE a.role_id = ' . $fr : '') . ' ORDER BY r.is_admin DESC, r.name, a.login');
$ub = [];
foreach (rows('SELECT admin_id, bot_id FROM admin_user_bots') as $r) $ub[$r['admin_id']][] = $r['bot_id'];
$bn = array_column($bots, null, 'id');
$cnt = array_column(rows('SELECT role_id, COUNT(*) c FROM admin_users GROUP BY role_id'), 'c', 'role_id');
?>
<div class="row"><h1 style="margin:0">Команда</h1><span class="sp"></span><a class="btn" href="?p=team&edit=new">+ Пользователь</a><a class="btn sec" href="?p=roles">Роли и права</a></div>
<p class="muted" style="margin-top:8px">Доступ к админке. Править пользователей, роли, ботов и настройки может только администратор.</p>
<div class="row" style="margin:12px 0"><a class="btn sm <?= $fr ? 'sec' : '' ?>" href="?p=team">Все (<?= array_sum($cnt) ?>)</a>
<?php foreach ($roles as $r): ?><a class="btn sm <?= $fr === (int)$r['id'] ? '' : 'sec' ?>" href="?p=team&role=<?= $r['id'] ?>"><?= h($r['name']) ?> (<?= (int)($cnt[$r['id']] ?? 0) ?>)</a><?php endforeach; ?></div>
<div class="card"><table><tr><th>Пользователь</th><th>Роль</th><th>Доступ к ботам</th><th>Статус</th><th>Последний вход</th><th></th></tr>
<?php foreach ($list as $a): ?><tr><td><b><?= h($a['login']) ?></b><br><span class="muted"><?= h($a['name']) ?></span></td>
<td><span class="badge <?= $a['is_admin'] ? 'r' : '' ?>"><?= h($a['role_name']) ?></span></td>
<td><?= $a['all_bots'] || $a['is_admin'] ? 'все боты' : h(implode(', ', array_map(fn($b) => isset($bn[$b]) ? ($bn[$b]['username'] ? '@' . $bn[$b]['username'] : $bn[$b]['name']) : '?', $ub[$a['id']] ?? [])) ?: '— нет —') ?></td>
<td><?= $a['active'] ? '<span class="badge g">активен</span>' : '<span class="badge y">отключён</span>' ?></td><td><?= fmt_dt((int)$a['last_login']) ?></td>
<td><a class="btn sm sec" href="?p=team&edit=<?= $a['id'] ?>">Изменить</a></td></tr><?php endforeach; ?></table></div>
<?php $log = rows('SELECT au.*, a.login FROM audit au LEFT JOIN admin_users a ON a.id = au.admin_id ORDER BY au.id DESC LIMIT 30'); ?>
<div class="card"><h2>Журнал действий</h2><table><?php foreach ($log as $l): ?><tr><td style="white-space:nowrap"><?= fmt_dt((int)$l['at'], 'd.m H:i:s') ?></td><td><?= h($l['login'] ?? '—') ?></td><td><code><?= h($l['action']) ?></code></td><td class="muted"><?= h($l['detail']) ?></td><td class="muted"><?= h($l['ip']) ?></td></tr><?php endforeach; if (!$log) echo '<tr><td class="muted">Пусто</td></tr>'; ?></table></div>
