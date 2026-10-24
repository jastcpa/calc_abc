<?php
$title = 'Мой профиль';
$me = me();
if (is_post()) {
    $do = post('do');
    if ($do === 'name') { update('admin_users', (int)$me['id'], ['name' => mb_substr(post('name'), 0, 60)]); flash('Сохранено'); }
    elseif ($do === 'password') {
        $cur = (string)val('SELECT pass_hash FROM admin_users WHERE id = ?', [$me['id']]);
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), $cur)) flash('Текущий пароль неверен', 'err');
        elseif ($e = password_problem($new)) flash($e, 'err');
        else {
            $ver = (int)$me['sess_ver'] + 1;
            update('admin_users', (int)$me['id'], ['pass_hash' => password_hash($new, PASSWORD_DEFAULT), 'sess_ver' => $ver]);
            $_SESSION['ver'] = $ver;   // эта сессия остаётся, остальные — завершаются
            audit('profile.password'); flash('Пароль изменён; остальные сессии завершены');
        }
    }
    redirect('?p=profile');
}
$bn = visible_bots();
?>
<h1>Мой профиль</h1>
<div class="grid2"><div class="card"><h2><?= h($me['login']) ?></h2>
<p>Роль: <span class="badge <?= $me['is_admin'] ? 'r' : '' ?>"><?= h($me['role_name']) ?></span></p>
<p>Боты: <?= bot_scope() === null ? '<b>все</b>' : ($bn ? h(implode(', ', array_map(fn($b) => $b['username'] ? '@' . $b['username'] : $b['name'], $bn))) : '<span class="danger">не назначены</span>') ?></p>
<p class="muted">Права: <?= $me['is_admin'] ? 'полный доступ' : h(implode(', ', $me['perms']) ?: 'нет') ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="name"><label>Имя</label><input name="name" value="<?= h($me['name']) ?>"><p><button class="btn">Сохранить</button></p></form></div>
<div class="card"><h2>Сменить пароль</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="password">
<label>Текущий пароль</label><input type="password" name="old" autocomplete="current-password"><label>Новый пароль (от 8 символов)</label><input type="password" name="new" autocomplete="new-password"><p><button class="btn">Сменить</button></p></form></div></div>
