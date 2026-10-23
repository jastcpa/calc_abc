<?php
$title = 'Пользователи';
$f = [
    'q' => trim((string)($_GET['q'] ?? '')), 'group' => (int)($_GET['group'] ?? 0), 'nogroup' => (int)($_GET['nogroup'] ?? 0),
    'source' => trim((string)($_GET['source'] ?? '')), 'status' => (string)($_GET['status'] ?? ''),
];
$w = ['1=1']; $qp = [];
if ($f['q'] !== '') {
    $w[] = '(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CAST(u.tg_id AS TEXT) = ? OR u.label = ?)';
    $like = '%' . $f['q'] . '%';
    array_push($qp, $like, $like, $like, $f['q'], $f['q']);
}
if ($f['group']) { $w[] = 'EXISTS (SELECT 1 FROM user_groups g WHERE g.user_id = u.id AND g.group_id = ?)'; $qp[] = $f['group']; }
if ($f['nogroup']) { $w[] = 'NOT EXISTS (SELECT 1 FROM user_groups g WHERE g.user_id = u.id AND g.group_id = ?)'; $qp[] = $f['nogroup']; }
if ($f['source'] === '-') $w[] = "(u.source IS NULL OR u.source = '')";
elseif ($f['source'] !== '') { $w[] = 'u.source = ?'; $qp[] = $f['source']; }
if (in_array($f['status'], ['active', 'blocked'], true)) { $w[] = 'u.status = ?'; $qp[] = $f['status']; }
$where = implode(' AND ', $w);

if (is_post() && post('do') === 'bulk') {
    $gid = (int)post('group_id'); $act = post('action');
    $ids = isset($_POST['all_filtered'])
        ? array_column(rows("SELECT u.id FROM users u WHERE $where", $qp), 'id')
        : post_ids('ids');
    if (!$gid || !val('SELECT 1 FROM groups WHERE id = ?', [$gid])) flash('Выберите группу', 'err');
    elseif (!$ids) flash('Не выбраны пользователи', 'err');
    else {
        foreach ($ids as $id) { $act === 'remove' ? group_remove((int)$id, $gid) : group_add((int)$id, $gid, 'manual'); }
        flash(($act === 'remove' ? 'Удалено из группы: ' : 'Добавлено в группу: ') . count($ids));
    }
    redirect('?' . http_build_query(array_filter($f)) . '&p=users');
}

if (isset($_GET['export'])) {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['id', 'tg_id', 'username', 'first_name', 'last_name', 'source', 'label', 'status', 'created', 'groups']);
    foreach (rows("SELECT u.*, (SELECT group_concat(g.name, '; ') FROM user_groups ug JOIN groups g ON g.id = ug.group_id WHERE ug.user_id = u.id) AS gl FROM users u WHERE $where ORDER BY u.id", $qp) as $u)
        fputcsv($o, [$u['id'], $u['tg_id'], $u['username'], $u['first_name'], $u['last_name'], $u['source'], $u['label'], $u['status'], fmt_dt((int)$u['created_at']), $u['gl']]);
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
$sources = rows("SELECT source, COUNT(*) c FROM users WHERE source IS NOT NULL AND source <> '' GROUP BY source ORDER BY c DESC");
$qs = array_filter($f);
?>
<h1>Пользователи <span class="muted">(<?= $total ?>)</span></h1>
<div class="card"><form method="get" class="row"><input type="hidden" name="p" value="users">
<input name="q" value="<?= h($f['q']) ?>" placeholder="Поиск: имя, @username, ID, метка" style="min-width:230px">
<select name="group"><?= group_options($f['group'], 'Любая группа (в группе)') ?></select>
<select name="nogroup"><?= group_options($f['nogroup'], 'Не состоит в группе') ?></select>
<select name="source"><option value="">Любой источник</option><option value="-" <?= $f['source'] === '-' ? 'selected' : '' ?>>— без источника —</option>
<?php foreach ($sources as $s): ?><option value="<?= h($s['source']) ?>" <?= $f['source'] === $s['source'] ? 'selected' : '' ?>><?= h($s['source']) ?> (<?= $s['c'] ?>)</option><?php endforeach; ?></select>
<select name="status"><option value="">Любой статус</option><option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Активные</option><option value="blocked" <?= $f['status'] === 'blocked' ? 'selected' : '' ?>>Заблокировали бота</option></select>
<button class="btn">Фильтр</button><a class="btn sec" href="?p=users">Сброс</a>
<a class="btn sec" href="?<?= h(http_build_query($qs + ['p' => 'users', 'export' => 1])) ?>">⬇ CSV</a></form></div>

<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="bulk">
<div class="row" style="margin-bottom:10px"><b>Выбранным:</b>
<select name="group_id"><?= group_options(0, '— группа —') ?></select>
<button class="btn sm" name="action" value="add">Добавить в группу</button><button class="btn sm sec" name="action" value="remove">Убрать из группы</button>
<label class="chk" style="margin:0"><input type="checkbox" name="all_filtered"> ко всем <?= $total ?> найденным по фильтру</label></div>
<div style="overflow-x:auto"><table><tr><th><input type="checkbox" id="checkall" style="width:auto"></th><th>Пользователь</th><th>Источник</th><th>Метка</th><th>Группы</th><th>Статус</th><th>Пришёл</th></tr>
<?php foreach ($list as $u): ?><tr>
<td><input type="checkbox" name="ids[]" value="<?= $u['id'] ?>" style="width:auto"></td>
<td><a href="?p=user&id=<?= $u['id'] ?>"><?= h(user_name($u)) ?></a><br><span class="muted"><?= $u['username'] ? '@' . h($u['username']) . ' · ' : '' ?><?= $u['tg_id'] ?></span></td>
<td><?= $u['source'] ? '<a href="?p=users&source=' . urlencode($u['source']) . '">' . h($u['source']) . '</a>' : '<span class="muted">—</span>' ?></td>
<td class="mono"><?= h($u['label']) ?></td>
<td><?php foreach ($ugroups[$u['id']] ?? [] as $g) echo group_tag($g); ?></td>
<td><?= $u['status'] === 'blocked' ? '<span class="badge r">заблокировал</span>' : '<span class="badge g">активен</span>' ?></td>
<td><?= fmt_dt((int)$u['created_at']) ?></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="7" class="muted">Никого не найдено</td></tr><?php endif; ?></table></div>
<?= pager($total, $page, $per, $qs + ['p' => 'users']) ?></form>
