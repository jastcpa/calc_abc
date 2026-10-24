<?php
$title = 'Дашборд';
$bot = (int)($_GET['bot'] ?? 0);
if ($bot && !bot_ok($bot)) $bot = 0;
// список ботов, по которым считаем: выбранный → иначе доступные пользователю → иначе без ограничения
$scope = $bot ? [$bot] : bot_scope();
$bl = $scope === null ? '' : ($scope ? implode(',', array_map('intval', $scope)) : '0');
$ub = $bl !== '' ? " AND bot_id IN ($bl)" : '';                                            // для таблицы users
$eb = $bl !== '' ? " AND user_id IN (SELECT id FROM users WHERE bot_id IN ($bl))" : '';   // для events
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 14, 30, 90], true)) $days = 30;
$off = tz()->getOffset(new DateTime());
$since = strtotime('today', now() + $off) - $off - ($days - 1) * 86400; // начало периода по tz админки
$todayStart = $since + ($days - 1) * 86400;

function series(string $sql, array $p, int $since, int $days, int $off): array
{
    $raw = array_column(rows($sql, $p), 'c', 'd');
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = gmdate('Y-m-d', $since + $off + $i * 86400);
        $out[date('d.m', strtotime($d))] = (int)($raw[$d] ?? 0);
    }
    return $out;
}
$dayExpr = "strftime('%Y-%m-%d', created_at + $off, 'unixepoch')";
$newSeries = series("SELECT $dayExpr d, COUNT(*) c FROM users WHERE created_at >= ?$ub GROUP BY d", [$since], $since, $days, $off);
$clickSeries = series("SELECT $dayExpr d, COUNT(*) c FROM events WHERE type='click' AND created_at >= ?$eb GROUP BY d", [$since], $since, $days, $off);

$total = (int)val('SELECT COUNT(*) FROM users WHERE 1=1' . $ub);
$blocked = (int)val("SELECT COUNT(*) FROM users WHERE status='blocked'$ub");
$kpi = [
    ['Всего пользователей', $total], ['Новых сегодня', (int)val('SELECT COUNT(*) FROM users WHERE created_at >= ?' . $ub, [$todayStart])],
    ["Новых за $days дн.", array_sum($newSeries)], ['Активны (не блок.)', $total - $blocked],
    ['Заблокировали бота', $blocked], ["Активность за $days дн.", (int)val('SELECT COUNT(*) FROM users WHERE last_seen >= ?' . $ub, [$since])],
    ['Кликов по кнопкам', (int)val("SELECT COUNT(*) FROM events WHERE type='click' AND created_at >= ?$eb", [$since])],
    ['Кликнули (уник.)', (int)val("SELECT COUNT(DISTINCT user_id) FROM events WHERE type='click' AND created_at >= ?$eb", [$since])],
    ['Сообщений доставлено', (int)val("SELECT COUNT(*) FROM events WHERE type IN ('step_sent','bc_sent') AND created_at >= ?$eb", [$since])],
    ['Внешних событий', (int)val("SELECT COUNT(*) FROM events WHERE type='postback' AND created_at >= ?$eb", [$since])],
];

$sources = rows("SELECT COALESCE(NULLIF(u.source,''),'(без источника)') s, COUNT(*) users, SUM(u.status='blocked') blocked,
  SUM(EXISTS (SELECT 1 FROM events e WHERE e.user_id = u.id AND e.type='click')) clicked,
  SUM(EXISTS (SELECT 1 FROM events e WHERE e.user_id = u.id AND e.type='postback')) converted,
  SUM(EXISTS (SELECT 1 FROM user_groups g WHERE g.user_id = u.id)) grouped
  FROM users u WHERE u.created_at >= ?" . ($bl !== '' ? " AND u.bot_id IN ($bl)" : '') . " GROUP BY 1 ORDER BY users DESC LIMIT 30", [$since]);
$grp = rows('SELECT g.*, (SELECT COUNT(*) FROM user_groups ug' . ($bl !== '' ? " JOIN users uu ON uu.id = ug.user_id AND uu.bot_id IN ($bl)" : '') . ' WHERE ug.group_id = g.id) cnt FROM groups g ORDER BY cnt DESC');
$gmax = max(1, ...array_column($grp ?: [['cnt' => 1]], 'cnt'));

$sentBy = array_column(rows("SELECT ref, COUNT(DISTINCT user_id) c FROM events WHERE type IN ('step_sent','bc_sent')$eb GROUP BY ref"), 'c', 'ref');
$buttons = rows("SELECT ref, label, COUNT(*) c, COUNT(DISTINCT user_id) u FROM events WHERE type='click' AND created_at >= ?$eb GROUP BY ref, label ORDER BY c DESC LIMIT 30", [$since]);

$flows = rows('SELECT * FROM flows' . ($bl !== '' ? " WHERE bot_id IN (0, $bl)" : '') . ' ORDER BY id');
$funnel = [];
foreach ($flows as $f) {
    $entered = (int)val('SELECT COUNT(*) FROM user_flows WHERE flow_id = ?' . $eb, [$f['id']]);
    $steps = rows('SELECT id, position, delay FROM steps WHERE flow_id = ? ORDER BY position', [$f['id']]);
    foreach ($steps as &$s) {
        $s['sent'] = (int)($sentBy['s' . $s['id']] ?? 0);
        $s['clicks'] = (int)val("SELECT COUNT(DISTINCT user_id) FROM events WHERE type='click' AND ref LIKE ?$eb", ['s' . $s['id'] . ':%']);
    }
    $funnel[] = [$f, $entered, $steps];
}
$bcs = rows('SELECT * FROM broadcasts WHERE status IN (\'sending\',\'done\',\'cancelled\')' . ($bl === '' ? '' : (unrestricted() ? " AND bot_id IN (0, $bl)" : " AND bot_id IN ($bl)")) . ' ORDER BY id DESC LIMIT 8');
$feed = rows('SELECT e.*, u.first_name, u.last_name, u.username, u.tg_id, u.id uid FROM events e LEFT JOIN users u ON u.id = e.user_id' . ($bl !== '' ? " WHERE u.bot_id IN ($bl)" : '') . ' ORDER BY e.id DESC LIMIT 20');
$evNames = ['start' => '▶ /start', 'step_sent' => '✉ шаг', 'click' => '👆 клик', 'bc_sent' => '📣 рассылка', 'group_add' => '➕ группа', 'group_remove' => '➖ группа', 'postback' => '🔁 постбек', 'message' => '💬 сообщение', 'blocked' => '🚫 блок', 'unblocked' => '✅ разблок', 'flow_start' => '🔗 цепочка', 'flow_done' => '🏁 цепочка готова', 'send_error' => '⚠ ошибка'];
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100) . '%' : '—';
?>
<div class="row"><h1 style="margin:0">Дашборд</h1><span class="sp"></span>
<form method="get"><input type="hidden" name="p" value="dashboard"><input type="hidden" name="days" value="<?= $days ?>"><select name="bot" onchange="this.form.submit()" style="width:auto"><?= bot_options($bot, 'Все боты') ?></select><noscript><button class="btn sm">OK</button></noscript></form>
<?php foreach ([7, 14, 30, 90] as $d) echo '<a class="btn sm ' . ($d === $days ? '' : 'sec') . '" href="?p=dashboard&bot=' . $bot . '&days=' . $d . '">' . $d . ' дн.</a>'; ?></div>
<?php if (!count(visible_bots())): ?><div class="flash err" style="margin-top:14px"><?= is_admin() ? 'Нет ни одного бота — <a href="?p=bots">подключите бота</a>.' : 'Вам не назначено ни одного бота — обратитесь к администратору.' ?></div><?php endif; ?>
<?php if (now() - (int)setting('last_tick', '0') > 300): ?><div class="flash err" style="margin-top:14px">Планировщик не запускался более 5 минут: задержки в цепочках и рассылки по расписанию не будут работать. Настройте cron (см. <a href="?p=settings">Настройки</a>).</div><?php endif; ?>
<div class="cards" style="margin-top:14px"><?php foreach ($kpi as [$l, $v]) echo '<div class="kpi"><b>' . number_format($v, 0, '', ' ') . '</b><span>' . h($l) . '</span></div>'; ?></div>

<div class="grid2"><div class="card"><h2>Новые пользователи</h2><?= spark($newSeries) ?></div>
<div class="card"><h2>Клики по кнопкам</h2><?= spark($clickSeries) ?></div></div>

<?php if (!$bot && count(visible_bots()) > 1): ?>
<div class="card"><h2>Боты</h2><table><tr><th>Бот</th><th>Пользователей</th><th>Новых за период</th><th>Заблокировали</th><th>Клики за период</th></tr>
<?php foreach (rows('SELECT b.*, (SELECT COUNT(*) FROM users u WHERE u.bot_id = b.id) users, (SELECT COUNT(*) FROM users u WHERE u.bot_id = b.id AND u.created_at >= ?) fresh,
  (SELECT COUNT(*) FROM users u WHERE u.bot_id = b.id AND u.status = \'blocked\') blocked,
  (SELECT COUNT(*) FROM events e JOIN users u ON u.id = e.user_id WHERE u.bot_id = b.id AND e.type = \'click\' AND e.created_at >= ?) clicks FROM bots b WHERE 1=1' . scope_sql('b.id') . ' ORDER BY b.id', [$since, $since]) as $bb): ?>
<tr><td><a href="?p=dashboard&bot=<?= $bb['id'] ?>&days=<?= $days ?>"><?= h(bot_label((int)$bb['id'])) ?></a></td><td><?= $bb['users'] ?></td><td><?= $bb['fresh'] ?></td><td><?= $bb['blocked'] ?></td><td><?= $bb['clicks'] ?></td></tr><?php endforeach; ?></table></div>
<?php endif; ?>
<div class="card"><h2>Источники <span class="muted">(пришли за период)</span></h2><table><tr><th>Источник</th><th>Польз.</th><th>Кликнули</th><th>Внешнее событие</th><th>В группах</th><th>Заблок.</th></tr>
<?php foreach ($sources as $s): ?><tr><td><a href="?p=users&source=<?= urlencode($s['s'] === '(без источника)' ? '-' : $s['s']) ?>"><?= h($s['s']) ?></a></td><td><?= $s['users'] ?></td>
<td><?= $s['clicked'] ?> <span class="muted"><?= $pct($s['clicked'], $s['users']) ?></span></td><td><?= $s['converted'] ?> <span class="muted"><?= $pct($s['converted'], $s['users']) ?></span></td>
<td><?= $s['grouped'] ?></td><td><?= (int)$s['blocked'] ?></td></tr><?php endforeach; if (!$sources) echo '<tr><td colspan="6" class="muted">Нет данных</td></tr>'; ?></table></div>

<div class="grid2"><div class="card"><h2>Группы</h2>
<?php foreach ($grp as $g): ?><div class="row" style="margin:6px 0"><a href="?p=users&group=<?= $g['id'] ?>" style="width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($g['name']) ?></a>
<div class="hbar sp"><i style="width:<?= round($g['cnt'] / $gmax * 100) ?>%;background:<?= h($g['color']) ?>"></i></div><b><?= $g['cnt'] ?></b></div><?php endforeach; if (!$grp) echo '<p class="muted">Групп нет</p>'; ?></div>
<div class="card"><h2>Воронки цепочек</h2>
<?php foreach ($funnel as [$f, $entered, $steps]): ?><h3><?= h($f['name']) ?> <span class="muted">вошло: <?= $entered ?></span></h3>
<?php foreach ($steps as $i => $s): ?><div class="row" style="margin:4px 0"><span style="width:70px">Шаг <?= $i + 1 ?></span><div class="hbar sp"><i style="width:<?= $entered ? round($s['sent'] / $entered * 100) : 0 ?>%"></i></div>
<span style="min-width:150px"><?= $s['sent'] ?> получили · <?= $s['clicks'] ?> кликнули</span></div><?php endforeach; endforeach; if (!$funnel) echo '<p class="muted">Цепочек нет</p>'; ?></div></div>

<div class="card"><h2>Кнопки <span class="muted">(клики за период)</span></h2><table><tr><th>Сообщение</th><th>Кнопка</th><th>Кликов</th><th>Уникальных</th><th>CTR</th></tr>
<?php foreach ($buttons as $b): $key = explode(':', $b['ref'])[0]; ?><tr><td><?= h(msg_label($key)) ?></td><td><?= h($b['label']) ?></td><td><?= $b['c'] ?></td><td><?= $b['u'] ?></td><td><?= $pct($b['u'], (int)($sentBy[$key] ?? 0)) ?></td></tr>
<?php endforeach; if (!$buttons) echo '<tr><td colspan="5" class="muted">Кликов пока нет</td></tr>'; ?></table></div>

<?php if ($bcs): ?><div class="card"><h2>Последние рассылки</h2><table><tr><th>Рассылка</th><th>Время</th><th>Доставлено</th><th>Ошибок</th><th>Кликнули</th></tr>
<?php foreach ($bcs as $b): $cl = (int)val("SELECT COUNT(DISTINCT user_id) FROM events WHERE type='click' AND ref LIKE ?$eb", ['b' . $b['id'] . ':%']); ?><tr><td><a href="?p=broadcast&id=<?= $b['id'] ?>"><?= h($b['name']) ?></a></td><td><?= fmt_dt((int)$b['started_at']) ?></td>
<td><?= $b['sent'] ?> / <?= $b['total'] ?></td><td><?= $b['failed'] ?></td><td><?= $cl ?> <span class="muted"><?= $pct($cl, (int)$b['sent']) ?></span></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<div class="card"><h2>Лента событий</h2><table>
<?php foreach ($feed as $e): ?><tr><td style="white-space:nowrap"><?= fmt_dt((int)$e['created_at'], 'd.m H:i:s') ?></td><td><?= $e['uid'] ? '<a href="?p=user&id=' . $e['uid'] . '">' . h(user_name($e)) . '</a>' : '—' ?></td>
<td><?= h($evNames[$e['type']] ?? $e['type']) ?></td><td class="muted"><?= h(in_array($e['type'], ['click', 'step_sent', 'bc_sent'], true) ? msg_label(explode(':', $e['ref'])[0]) . ($e['label'] ? ' → ' . $e['label'] : '') : ($e['label'] ?: $e['ref'])) ?></td></tr><?php endforeach; ?></table></div>
