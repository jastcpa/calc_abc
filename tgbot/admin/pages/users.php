<?php
$title = 'Подписчики';
$f = [
    'q' => trim((string)($_GET['q'] ?? '')), 'group' => (int)($_GET['group'] ?? 0), 'nogroup' => (int)($_GET['nogroup'] ?? 0),
    'bot' => effective_bot(), 'source' => trim((string)($_GET['source'] ?? '')), 'status' => (string)($_GET['status'] ?? ''),
];
$w = ['1=1']; $qp = [];
if ($f['q'] !== '') {
    $w[] = '(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CAST(u.tg_id AS TEXT) = ? OR u.label = ?)';
    $like = '%' . $f['q'] . '%';
    array_push($qp, $like, $like, $like, $f['q'], $f['q']);
}
if ($f['bot']) { $w[] = 'u.bot_id = ?'; $qp[] = $f['bot']; }
if ($f['group']) { $w[] = 'EXISTS (SELECT 1 FROM user_groups g WHERE g.user_id = u.id AND g.group_id = ?)'; $qp[] = $f['group']; }
if ($f['nogroup']) { $w[] = 'NOT EXISTS (SELECT 1 FROM user_groups g WHERE g.user_id = u.id AND g.group_id = ?)'; $qp[] = $f['nogroup']; }
if ($f['source'] === '-') $w[] = "(u.source IS NULL OR u.source = '')";
elseif ($f['source'] !== '') { $w[] = 'u.source = ?'; $qp[] = $f['source']; }
if (in_array($f['status'], ['active', 'blocked'], true)) { $w[] = 'u.status = ?'; $qp[] = $f['status']; }
$where = implode(' AND ', $w) . scope_sql('u.bot_id');   // ограничение по доступным ботам

if (is_post() && post('do') === 'bulk') {
    need('users.edit');
    $gid = (int)post('group_id'); $act = post('action');
    $ids = isset($_POST['all_filtered'])
        ? array_column(rows("SELECT u.id FROM users u WHERE $where", $qp), 'id')
        : array_column(rows('SELECT u.id FROM users u WHERE u.id IN (' . (implode(',', post_ids('ids')) ?: '0') . ')' . scope_sql('u.bot_id')), 'id');
    if (!$gid || !val('SELECT 1 FROM groups WHERE id = ?', [$gid])) flash('Выберите группу', 'err');
    elseif (!$ids) flash('Не выбраны пользователи', 'err');
    else {
        foreach ($ids as $id) { $act === 'remove' ? group_remove((int)$id, $gid) : group_add((int)$id, $gid, 'manual'); }
        flash(($act === 'remove' ? 'Удалено из группы: ' : 'Добавлено в группу: ') . count($ids));
    }
    redirect('?' . http_build_query(array_filter($f)) . '&p=users');
}

if (isset($_GET['export'])) {
    need('users.export');
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['id', 'bot', 'tg_id', 'username', 'first_name', 'last_name', 'source', 'label', 'status', 'created', 'groups']);
    $safe = fn($v) => is_string($v) && $v !== '' && strpos('=+-@' . "\t\r", $v[0]) !== false ? "'" . $v : $v; // защита от CSV/формул-инъекции
    foreach (rows("SELECT u.*, (SELECT group_concat(g.name, '; ') FROM user_groups ug JOIN groups g ON g.id = ug.group_id WHERE ug.user_id = u.id) AS gl FROM users u WHERE $where ORDER BY u.id", $qp) as $u)
        fputcsv($o, array_map($safe, [$u['id'], bot_label((int)$u['bot_id']), $u['tg_id'], $u['username'], $u['first_name'], $u['last_name'], $u['source'], $u['label'], $u['status'], fmt_dt((int)$u['created_at']), $u['gl']]));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1)); $per = 50;
$total = (int)val("SELECT COUNT(*) FROM users u WHERE $where", $qp);
$list = rows("SELECT u.* FROM users u WHERE $where ORDER BY u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $qp);
$ugroups = [];
if ($list) {
    $in = implode(',', array_column($list, 'id'));
    foreach (rows("SELECT ug.user_id, g.* FROM user_groups ug JOIN groups g ON g.id = ug.group_id WHERE ug.user_id IN ($in) ORDER BY g.name") as $r) $ugroups[$r['user_id']][] = $r;
}
$sources = rows("SELECT source, COUNT(*) c FROM users WHERE source IS NOT NULL AND source <> ''" . scope_sql('bot_id') . " GROUP BY source ORDER BY c DESC");
$qs = array_filter($f);
?>
<?php
$gnames = array_column(rows('SELECT id, name FROM groups'), 'name', 'id');
$chips = [];   // активные фильтры → «чипы» с кнопкой сброса
$drop = fn(string $k) => '?' . http_build_query(array_diff_key($qs + ['p' => 'users', 'bot' => $f['bot'] ?: null], [$k => 1]));
if ($f['q'] !== '') $chips['q'] = 'Поиск: ' . $f['q'];
if ($f['group']) $chips['group'] = 'В группе: ' . ($gnames[$f['group']] ?? '?');
if ($f['nogroup']) $chips['nogroup'] = 'Не в группе: ' . ($gnames[$f['nogroup']] ?? '?');
if ($f['source'] !== '') $chips['source'] = 'Источник: ' . ($f['source'] === '-' ? 'без источника' : $f['source']);
if ($f['status'] !== '') $chips['status'] = $f['status'] === 'blocked' ? 'Заблокировали бота' : 'Активные';
$showBot = !$f['bot'] && count(visible_bots()) > 1;
?>
<?= pagehead('Подписчики', can('users.export') ? '<a class="btn sec" href="?' . h(http_build_query($qs + ['p' => 'users', 'export' => 1])) . '">⬇ Экспорт CSV</a>' : '') ?>
<div class="card" style="padding:12px 16px"><form method="get" class="row"><input type="hidden" name="p" value="users"><?php if (isset($_GET['bot'])): ?><input type="hidden" name="bot" value="<?= (int)$_GET['bot'] ?>"><?php endif; ?>
<input name="q" value="<?= h($f['q']) ?>" placeholder="Поиск: имя, @username, ID, метка" style="min-width:240px;flex:1" aria-label="Поиск">
<select name="group" aria-label="В группе"><?= group_options($f['group'], 'Любая группа') ?></select>
<select name="nogroup" aria-label="Не в группе"><?= group_options($f['nogroup'], 'Не состоит в группе…') ?></select>
<select name="source" aria-label="Источник"><option value="">Любой источник</option><option value="-" <?= $f['source'] === '-' ? 'selected' : '' ?>>— без источника —</option>
<?php foreach ($sources as $s): ?><option value="<?= h($s['source']) ?>" <?= $f['source'] === $s['source'] ? 'selected' : '' ?>><?= h($s['source']) ?> (<?= $s['c'] ?>)</option><?php endforeach; ?></select>
<select name="status" aria-label="Статус"><option value="">Любой статус</option><option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Активные</option><option value="blocked" <?= $f['status'] === 'blocked' ? 'selected' : '' ?>>Заблокировали бота</option></select>
<button class="btn">Применить</button></form>
<?php if ($chips): ?><div class="row" style="margin-top:10px"><span class="muted">Фильтры:</span><?php foreach ($chips as $k => $t): ?><span class="chipx"><?= h($t) ?> <a href="<?= h($drop($k)) ?>" aria-label="Убрать фильтр">×</a></span><?php endforeach; ?><a href="?p=users" class="btn ghost sm">Сбросить всё</a></div><?php endif; ?></div>
<?php if ($f['bot']): ?><div class="row" style="margin-bottom:12px"><span class="chipx"><?= icon('bot') ?> <?= h(bot_label($f['bot'])) ?> <a href="?p=users&bot=0" aria-label="Показать все боты">×</a></span></div><?php endif; ?>

<form method="post" class="card" style="padding:0"><?= csrf_field() ?><input type="hidden" name="do" value="bulk">
<div class="row" style="padding:12px 16px;border-bottom:1px solid var(--line)"><b>Найдено: <?= number_format($total, 0, '', ' ') ?></b><span class="muted">Отметьте строки, чтобы добавить их в группу или убрать из неё</span></div>
<?php if (!$list): ?><?= empty_state('inbox', $chips || $f['bot'] ? 'Никого не найдено' : 'Подписчиков пока нет', $chips ? 'Попробуйте ослабить фильтры.' : 'Они появятся, когда кто-то нажмёт /start у вашего бота. Сформируйте ссылку с источником на странице «Ссылки».', $chips ? '<a class="btn sec" href="?p=users">Сбросить фильтры</a>' : (can('links.view') ? '<a class="btn" href="?p=links">Сформировать ссылку</a>' : '')) ?><?php else: ?>
<div class="table-wrap"><table><tr><th style="width:32px"><input type="checkbox" id="checkall" aria-label="Выбрать все на странице"></th><th>Подписчик</th><?php if ($showBot): ?><th>Бот</th><?php endif; ?><th>Источник</th><th>Метка</th><th>Группы</th><th>Статус</th><th>Пришёл</th></tr>
<?php foreach ($list as $u): ?><tr>
<td><input type="checkbox" name="ids[]" value="<?= $u['id'] ?>" aria-label="Выбрать"></td>
<td><div class="row" style="flex-wrap:nowrap"><span class="avatar" style="background:#475569"><?= h(initials(user_name($u))) ?></span><div><a href="?p=user&id=<?= $u['id'] ?>"><?= h(user_name($u)) ?></a><br><span class="muted"><?= $u['username'] ? '@' . h($u['username']) . ' · ' : '' ?><?= $u['tg_id'] ?></span></div></div></td>
<?php if ($showBot): ?><td><?= h(bot_label((int)$u['bot_id'])) ?></td><?php endif; ?>
<td><?= $u['source'] ? '<a href="?p=users&source=' . urlencode($u['source']) . '">' . h($u['source']) . '</a>' : '<span class="muted">—</span>' ?></td>
<td class="mono"><?= h($u['label']) ?></td>
<td><?php foreach ($ugroups[$u['id']] ?? [] as $g) echo group_tag($g); ?></td>
<td><?= $u['status'] === 'blocked' ? '<span class="badge r">заблокировал</span>' : '<span class="badge g">активен</span>' ?></td>
<td><?= fmt_dt((int)$u['created_at']) ?></td></tr>
<?php endforeach; ?></table></div><?php endif; ?>
<div style="padding:10px 16px"><?= pager($total, $page, $per, $qs + ['p' => 'users']) ?></div>
<div class="bulkbar" id="bulkbar" role="toolbar" aria-label="Массовые действия"><b>Выбрано: <span id="bulkn">0</span></b>
<select name="group_id" aria-label="Группа"><?= group_options(0, '— группа —') ?></select>
<button class="btn sm" name="action" value="add">Добавить в группу</button><button class="btn sm sec" name="action" value="remove">Убрать из группы</button>
<label class="chk" style="margin:0"><input type="checkbox" name="all_filtered"> все <?= $total ?> по фильтру</label></div></form>
