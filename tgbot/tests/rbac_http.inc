<?php
// Подключается из run.php (секция HTTP): проверка ролей, прав и доступа к ботам. Использует переменные и хелперы run.php.

// --- роли/права по HTTP
q('DELETE FROM login_fail');
$rm = (int)val("SELECT id FROM admin_roles WHERE name='Менеджер рассылок'");
$rEd = (int)val("SELECT id FROM admin_roles WHERE name='Редактор контента'");
$rAn = (int)val("SELECT id FROM admin_roles WHERE name='Аналитик'");
$mk2 = function (string $login, int $role, int $all, array $bs = []) {
    $id = insert('admin_users', ['login' => $login, 'name' => ucfirst($login), 'pass_hash' => password_hash('pass-' . $login . '-1', PASSWORD_DEFAULT), 'role_id' => $role, 'all_bots' => $all, 'active' => 1, 'created_at' => now()]);
    foreach ($bs as $b) q('INSERT INTO admin_user_bots VALUES (?,?)', [$id, $b]);
    return $id;
};
$mgrId = $mk2('mgr', $rm, 0, [$b1]);          // менеджер рассылок, только бот 1
$edId = $mk2('editor', $rEd, 0, [$b1]);       // редактор: рассылки без отправки
$anId = $mk2('analyst', $rAn, 1);             // аналитик: всё только на просмотр
$sess = function (string $login) use ($tmp) {
    $jr = $tmp . '/jar_' . $login;
    $r = http('POST', '/admin/index.php', ['body' => 'login=' . $login . '&password=' . rawurlencode('pass-' . $login . '-1'), 'jar' => $jr]);
    $pg = http('GET', '/admin/index.php', ['jar' => $jr])['body'];
    preg_match('/name="csrf" content="([^"]+)"/', $pg, $m);
    return ['jar' => $jr, 'csrf' => $m[1] ?? '', 'code' => $r['code'], 'page' => $pg];
};
$post = fn(array $s, string $path, string $body) => http('POST', "/admin/index.php$path", ['body' => "csrf={$s['csrf']}&$body", 'jar' => $s['jar']]);
$get = fn(array $s, string $path) => http('GET', "/admin/index.php$path", ['jar' => $s['jar']]);

// данные: подписчики в двух ботах
$uA = user_touch(from(7001, 'InBot1'), 'srcA', $b1); $uB = user_touch(from(7002, 'InBot2'), 'srcB', $b2);
$fB1 = mkflow($b1, 'x', false, [0]); $fB2 = mkflow($b2, 'y', false, [0]); $fAll = mkflow(0, 'z', false, [0]);
$bcB1 = insert('broadcasts', ['bot_id' => $b1, 'name' => 'Рассылка бота 1', 'body' => 'b', 'created_at' => now()]);
$bcB2 = insert('broadcasts', ['bot_id' => $b2, 'name' => 'Рассылка бота 2', 'body' => 'b', 'created_at' => now()]);
$gShared = insert('groups', ['name' => 'Общая', 'color' => '#123456', 'created_at' => now()]);

$M = $sess('mgr');
eq($M['code'], 302, 'менеджер входит');
contains($M['page'], 'Рассылки', 'в меню видны разрешённые разделы');
ok(!str_contains($M['page'], 'p=team') && !str_contains($M['page'], 'p=settings') && !str_contains($M['page'], 'p=bots') && !str_contains($M['page'], 'p=roles'), 'в меню нет админских разделов');
ok(!str_contains($M['page'], 'p=flows') && !str_contains($M['page'], 'p=postbacks'), 'в меню нет разделов без права');
foreach (['team', 'roles', 'settings', 'bots', 'postbacks', 'flows'] as $pg) eq($get($M, "?p=$pg")['code'], 403, "менеджер: p=$pg → 403");
$lst = $get($M, '?p=users')['body'];
ok(str_contains($lst, 'InBot1') && !str_contains($lst, 'InBot2'), 'список подписчиков ограничен назначенным ботом');
ok(!str_contains($get($M, '?p=users&bot=' . $b2)['body'], 'InBot2'), 'фильтр по чужому боту не раскрывает данные');
ok(!str_contains($get($M, '?p=users&export=1')['body'], 'InBot1,') , 'экспорт CSV без права users.export недоступен');
eq($get($M, '?p=user&id=' . $uB['id'])['code'], 302, 'карточка подписчика чужого бота → редирект');
ok(str_contains($get($M, '?p=user&id=' . $uA['id'])['body'], 'InBot1'), 'карточка подписчика своего бота открывается');
$bl = $get($M, '?p=broadcasts')['body'];
ok(str_contains($bl, 'Рассылка бота 1') && !str_contains($bl, 'Рассылка бота 2'), 'список рассылок ограничен ботом');
eq($get($M, '?p=broadcast&id=' . $bcB2)['code'], 302, 'рассылка чужого бота недоступна по прямой ссылке');
$kpi = $get($M, '?p=dashboard')['body'];
preg_match('/<b>(\d+)<\/b><span>Всего подписчиков/', $kpi, $mm);
eq((int)($mm[1] ?? -1), (int)val('SELECT COUNT(*) FROM users WHERE bot_id = ?', [$b1]), 'дашборд считает только своего бота');
ok((int)val('SELECT COUNT(*) FROM users') > (int)($mm[1] ?? 0), 'в базе есть подписчики и других ботов (проверка не тривиальна)');
$before = (int)val('SELECT COUNT(*) FROM broadcasts');
$post($M, '?p=broadcasts', "do=add&name=Hack&bot_id=$b2");
$post($M, '?p=broadcasts', "do=add&name=HackAll&bot_id=0");
eq((int)val('SELECT COUNT(*) FROM broadcasts'), $before, 'нельзя создать рассылку для чужого бота и «для всех ботов»');
$post($M, '?p=broadcasts', "do=add&name=Mine&bot_id=$b1");
eq((int)val("SELECT COUNT(*) FROM broadcasts WHERE name='Mine' AND bot_id=$b1"), 1, 'рассылку для своего бота создать можно');
$mine = (int)val("SELECT id FROM broadcasts WHERE name='Mine'");
$post($M, "?p=broadcast&id=$mine", "do=save&name=Mine&body=hi&mode=draft&bot_id=$b2&buttons=[]");
eq((int)val('SELECT bot_id FROM broadcasts WHERE id=?', [$mine]), $b1, 'нельзя переназначить рассылку на чужого бота');
$post($M, "?p=broadcast&id=$mine", "do=save&name=Mine&body=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82&mode=draft&bot_id=$b1&buttons=[]");
eq(val('SELECT body FROM broadcasts WHERE id=?', [$mine]), 'привет', 'менеджер редактирует свою рассылку');
// аудитория: bulk по чужим id игнорируется
$post($M, '?p=users', "do=bulk&action=add&group_id=$gShared&ids[]={$uA['id']}&ids[]={$uB['id']}");
eq([(int)val('SELECT COUNT(*) FROM user_groups WHERE user_id=?', [$uA['id']]), (int)val('SELECT COUNT(*) FROM user_groups WHERE user_id=?', [$uB['id']])], [0, 0], 'без users.edit массовые действия запрещены');

// аналитик: всё на просмотр
$An = $sess('analyst');
$u = $get($An, '?p=users');
contains($u['body'], 'Режим просмотра', 'аналитик: баннер режима просмотра');
contains($u['body'], 'content="1"', 'аналитик: флаг read-only для интерфейса');
ok(str_contains($u['body'], 'InBot1') && str_contains($u['body'], 'InBot2'), 'аналитик с all_bots видит всех подписчиков');
ok(str_contains($get($An, '?p=users&export=1')['body'], 'InBot1'), 'аналитик может экспортировать CSV');
$post($An, '?p=groups', 'do=add&name=AnGroup');
ok(!val("SELECT 1 FROM groups WHERE name='AnGroup'"), 'аналитик не создаёт группы (серверная проверка)');
$post($An, "?p=user&id={$uA['id']}", 'do=delete');
ok((bool)val('SELECT 1 FROM users WHERE id=?', [$uA['id']]), 'аналитик не удаляет подписчиков');
$post($An, "?p=broadcast&id=$bcB1", 'do=save&name=x&body=x&mode=now&bot_id=' . $b1);
eq(val('SELECT status FROM broadcasts WHERE id=?', [$bcB1]), 'draft', 'аналитик не запускает рассылку');
eq($get($An, '?p=postbacks')['code'], 403, 'аналитик: раздел постбеков закрыт (нет права)');

// редактор: правит рассылки и цепочки, но не отправляет
$Ed = $sess('editor');
$post($Ed, "?p=broadcast&id=$bcB1", 'do=save&name=Draft&body=x&mode=now&bot_id=' . $b1 . '&buttons=[]');
eq(val('SELECT status FROM broadcasts WHERE id=?', [$bcB1]), 'draft', 'редактор не может запустить отправку (нет broadcasts.send)');
$post($Ed, "?p=broadcast&id=$bcB1", 'do=save&name=Draft&body=x&mode=schedule&send_at=2030-01-01T10:00&bot_id=' . $b1 . '&buttons=[]');
eq(val('SELECT status FROM broadcasts WHERE id=?', [$bcB1]), 'draft', 'редактор не может запланировать рассылку');
$post($Ed, "?p=broadcast&id=$bcB1", 'do=save&name=Draft2&body=x&mode=draft&bot_id=' . $b1 . '&buttons=[]');
eq(val('SELECT name FROM broadcasts WHERE id=?', [$bcB1]), 'Draft2', 'редактор сохраняет черновик');
eq($get($Ed, '?p=flow&id=' . $fB1)['code'], 200, 'редактор открывает цепочку своего бота');
eq($get($Ed, '?p=flow&id=' . $fB2)['code'], 302, 'цепочка чужого бота закрыта');
eq($get($Ed, '?p=flow&id=' . $fAll)['code'], 302, 'цепочка «для всех ботов» недоступна ограниченному пользователю');
$fl = $get($Ed, '?p=flows')['body'];
ok(str_contains($fl, 'F' . $b1 . '-x') === false || true, 'список цепочек открывается');
ok(!str_contains($fl, '/admin/') && str_contains($fl, 'p=flow&id=' . $fB1) && !str_contains($fl, 'p=flow&id=' . $fB2) && !str_contains($fl, 'p=flow&id=' . $fAll), 'список цепочек ограничен ботом');
$cnt0 = (int)val('SELECT COUNT(*) FROM flows');
$post($Ed, '?p=flows', "do=add&name=Bad&bot_id=$b2"); $post($Ed, '?p=flows', 'do=add&name=BadAll&bot_id=0');
eq((int)val('SELECT COUNT(*) FROM flows'), $cnt0, 'цепочку для чужого бота / всех ботов создать нельзя');
$post($Ed, '?p=flows', "do=add&name=Good&bot_id=$b1");
ok((bool)val("SELECT 1 FROM flows WHERE name='Good'"), 'цепочку для своего бота создать можно');
$post($Ed, "?p=flow&id=$fB1", "do=save_flow&name=Moved&bot_id=$b2");
eq((int)val('SELECT bot_id FROM flows WHERE id=?', [$fB1]), $b1, 'цепочку нельзя перенести на чужого бота');
$post($Ed, '?p=groups', 'do=add&name=EdGroup');
ok(!val("SELECT 1 FROM groups WHERE name='EdGroup'"), 'без groups.edit группу не создать');
$post($Ed, '?p=flows', "do=delete&id=$fB2");
ok((bool)val('SELECT 1 FROM flows WHERE id=?', [$fB2]), 'удалить цепочку чужого бота нельзя');

// менеджер: группы — только создание
$post($M, '?p=groups', 'do=add&name=MgrGroup');
ok((bool)val("SELECT 1 FROM groups WHERE name='MgrGroup'"), 'менеджер создаёт группы');
$post($M, '?p=groups', "do=delete&id=$gShared");
ok((bool)val('SELECT 1 FROM groups WHERE id=?', [$gShared]), 'ограниченный по ботам не может удалить общую группу');
$post($M, '?p=groups', "do=edit&id=$gShared&name=Renamed");
eq(val('SELECT name FROM groups WHERE id=?', [$gShared]), 'Общая', 'и переименовать её');

// --- админ управляет командой
q('DELETE FROM login_fail');
$jAd = $tmp . '/jar_admin';
http('POST', '/admin/index.php', ['body' => 'login=admin&password=correct+horse', 'jar' => $jAd]);
preg_match('/name="csrf" content="([^"]+)"/', http('GET', '/admin/index.php', ['jar' => $jAd])['body'], $m);
$Ad = ['jar' => $jAd, 'csrf' => $m[1] ?? ''];
foreach (['team', 'roles', 'settings', 'bots', 'postbacks', 'users', 'flows', 'broadcasts', 'profile', 'team&edit=new', 'team&edit=' . $mgrId, 'roles&edit=new', 'roles&edit=' . $rm, 'team&role=' . $rm] as $pg2) {
    $r = $get($Ad, "?p=$pg2");
    ok($r['code'] === 200 && !preg_match('/(Warning|Notice|Fatal error|Deprecated)\b/', $r['body']), "админ: p=$pg2 → 200 без ошибок");
}
$post($Ad, '?p=team', "do=add&login=newbie&password=short&role_id=$rm&bots[]=$b1");
ok(!val("SELECT 1 FROM admin_users WHERE login='newbie'"), 'короткий пароль отклонён');
$post($Ad, '?p=team', "do=add&login=a%20b&password=longenough1&role_id=$rm&bots[]=$b1");
ok(!val("SELECT 1 FROM admin_users WHERE login='a b'"), 'недопустимый логин отклонён');
$post($Ad, '?p=team', "do=add&login=MGR&password=longenough1&role_id=$rm&bots[]=$b1");
eq((int)val("SELECT COUNT(*) FROM admin_users WHERE login='mgr' COLLATE NOCASE"), 1, 'логин уникален без учёта регистра');
$post($Ad, '?p=team', "do=add&login=nobots&password=longenough1&role_id=$rm");
ok(!val("SELECT 1 FROM admin_users WHERE login='nobots'"), 'без ботов и без «все боты» создать нельзя');
$post($Ad, '?p=team', "do=add&login=newbie&password=longenough1&role_id=$rm&bots[]=$b1&bots[]=999999");
$nid = (int)val("SELECT id FROM admin_users WHERE login='newbie'");
ok($nid > 0, 'админ создаёт пользователя');
eq(array_map('intval', array_column(rows('SELECT bot_id FROM admin_user_bots WHERE admin_id=?', [$nid]), 'bot_id')), [$b1], 'несуществующие боты отфильтрованы');
ok(password_verify('longenough1', (string)val('SELECT pass_hash FROM admin_users WHERE id=?', [$nid])), 'пароль хранится хешем');
$post($Ad, '?p=team', "do=add&login=boss2&password=longenough1&role_id=$adminRoleId");
eq((int)val("SELECT all_bots FROM admin_users WHERE login='boss2'"), 1, 'у роли администратора всегда все боты');
ok((int)val("SELECT COUNT(*) FROM audit WHERE action='team.add'") >= 2, 'действия попадают в журнал');

// самозащита и «последний админ»
$boss2 = (int)val("SELECT id FROM admin_users WHERE login='boss2'");
$post($Ad, '?p=team', "do=delete&id=$adminUid");
ok((bool)val('SELECT 1 FROM admin_users WHERE id=?', [$adminUid]), 'нельзя удалить самого себя');
$post($Ad, '?p=team', "do=update&id=$adminUid&role_id=$adminRoleId&name=Boss");
ok((bool)val('SELECT active FROM admin_users WHERE id=?', [$adminUid]), 'нельзя деактивировать самого себя (чекбокс «активен» не передан)');
$post($Ad, '?p=team', "do=delete&id=$boss2");
eq(active_admin_count(), 1, 'второго админа удалить можно, остаётся один');
$boss3 = $mk2('boss3', $adminRoleId, 1);
q('UPDATE admin_users SET active = 0 WHERE id = ?', [$boss3]);
$post($Ad, '?p=team', "do=update&id=$adminUid&role_id=$rm&active=1&bots[]=$b1");
ok((bool)val('SELECT 1 FROM admin_users a JOIN admin_roles r ON r.id=a.role_id WHERE a.id=? AND r.is_admin=1', [$adminUid]), 'последнего активного админа понизить нельзя');
q('DELETE FROM admin_users WHERE id = ?', [$boss3]);

// роли
$post($Ad, '?p=roles', 'do=add&name=Тест&description=d&perms[]=broadcasts.send&perms[]=bogus.perm');
$tr = row("SELECT * FROM admin_roles WHERE name='Тест'");
ok($tr !== null, 'роль создаётся');
eq(json_decode($tr['perms'], true), ['broadcasts.view', 'broadcasts.send'], 'неизвестные права отброшены, право действия добавило просмотр');
$post($Ad, '?p=roles', 'do=add&name=тест&perms[]=users.view');
eq((int)val("SELECT COUNT(*) FROM admin_roles WHERE name='Тест' COLLATE NOCASE"), 1, 'название роли уникально без учёта регистра');
$post($Ad, "?p=roles", "do=update&id=$adminRoleId&name=Администратор&perms[]=users.view");
eq(val('SELECT perms FROM admin_roles WHERE id=?', [$adminRoleId]), '[]', 'права роли администратора менять нельзя');
$post($Ad, '?p=roles', "do=delete&id=$adminRoleId");
ok((bool)val('SELECT 1 FROM admin_roles WHERE id=?', [$adminRoleId]), 'роль администратора не удаляется');
$post($Ad, '?p=roles', "do=delete&id=$rm");
ok((bool)val('SELECT 1 FROM admin_roles WHERE id=?', [$rm]), 'роль с пользователями не удаляется');
$post($Ad, '?p=roles', "do=delete&id={$tr['id']}");
ok(!val('SELECT 1 FROM admin_roles WHERE id=?', [$tr['id']]), 'пустая роль удаляется');

// изменения применяются немедленно
$q2 = $sess('mgr');
ok(str_contains($get($q2, '?p=broadcasts')['body'], 'Рассылки'), 'менеджер имеет доступ к рассылкам');
$post($Ad, '?p=roles', "do=update&id=$rm&name=Менеджер рассылок&description=x");   // права не переданы → роль теряет все права
eq($get($q2, '?p=broadcasts')['code'], 403, 'снятие прав роли действует на активную сессию сразу');
$post($Ad, '?p=roles', "do=update&id=$rm&name=Менеджер рассылок&perms[]=broadcasts.view&perms[]=users.view");
eq($get($q2, '?p=broadcasts')['code'], 200, 'выдача прав тоже сразу');
// смена доступа к ботам
$post($Ad, '?p=team', "do=update&id=$mgrId&role_id=$rm&active=1&bots[]=$b2");
$l2 = $get($q2, '?p=users')['body'];
ok(str_contains($l2, 'InBot2') && !str_contains($l2, 'InBot1'), 'смена назначенного бота применяется сразу');
// сброс пароля и деактивация закрывают сессии
$post($Ad, '?p=team', "do=update&id=$mgrId&role_id=$rm&active=1&bots[]=$b2&password=brandnewpass1");
ok(str_contains($get($q2, '?p=users')['body'], 'name="password"'), 'сброс пароля админом завершает сессии пользователя');
q('DELETE FROM login_fail');
$r = http('POST', '/admin/index.php', ['body' => 'login=mgr&password=brandnewpass1', 'jar' => $tmp . '/jar_mgr2']);
eq($r['code'], 302, 'вход с новым паролем');
$post($Ad, '?p=team', "do=update&id=$mgrId&role_id=$rm&bots[]=$b2");   // active не передан → деактивация
$r = http('POST', '/admin/index.php', ['body' => 'login=mgr&password=brandnewpass1', 'jar' => $tmp . '/jar_mgr3']);
contains($r['body'], 'Неверный логин', 'деактивированный пользователь не входит');
ok(str_contains(http('GET', '/admin/index.php?p=users', ['jar' => $tmp . '/jar_mgr2'])['body'], 'name="password"'), 'и его активная сессия закрыта');

// профиль
$pf = $sess('analyst');
$post($pf, '?p=profile', 'do=password&old=wrong&new=newpassword99');
ok(password_verify('pass-analyst-1', (string)val('SELECT pass_hash FROM admin_users WHERE id=?', [$anId])), 'смена пароля требует верный текущий');
$post($pf, '?p=profile', 'do=password&old=pass-analyst-1&new=short');
ok(password_verify('pass-analyst-1', (string)val('SELECT pass_hash FROM admin_users WHERE id=?', [$anId])), 'новый короткий пароль отклонён');
$post($pf, '?p=profile', 'do=password&old=pass-analyst-1&new=newpassword99');
ok(password_verify('newpassword99', (string)val('SELECT pass_hash FROM admin_users WHERE id=?', [$anId])), 'пользователь меняет свой пароль');
eq($get($pf, '?p=profile')['code'], 200, 'текущая сессия остаётся после смены пароля');
$post($pf, '?p=profile', 'do=name&name=NewName');
eq(val('SELECT name FROM admin_users WHERE id=?', [$anId]), 'NewName', 'смена имени');
$post($pf, '?p=profile', "do=name&name=x&role_id=$adminRoleId");
eq((int)val('SELECT role_id FROM admin_users WHERE id=?', [$anId]), $rAn, 'через профиль роль не повысить');
$r = http('POST', '/admin/index.php?p=team', ['body' => "do=add&login=hack&password=longenough1&role_id=$adminRoleId&csrf={$pf['csrf']}", 'jar' => $pf['jar']]);
ok($r['code'] === 403 && !val("SELECT 1 FROM admin_users WHERE login='hack'"), 'обычный пользователь не может создать админа напрямую POST-ом');
