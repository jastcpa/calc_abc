<?php
$title = 'Ссылки и источники';
$vis = visible_bots();
$botId = (int)($_GET['bot'] ?? ($vis[0]['id'] ?? 0));
if (!bot_ok($botId)) $botId = (int)($vis[0]['id'] ?? 0);
$botRow = row('SELECT * FROM bots WHERE id = ?', [$botId]);
$bot = $botRow['username'] ?? '';
$src = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['source'] ?? ''));
$lbl = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['label'] ?? ''));
$param = $src . ($lbl !== '' ? '__' . $lbl : '');
$stats = rows("SELECT COALESCE(NULLIF(source,''),'(без источника)') s, COUNT(*) users, SUM(status='blocked') blocked FROM users WHERE bot_id = ? GROUP BY 1 ORDER BY users DESC", [$botId]);
$flowBy = array_column(rows("SELECT lower(source) s, name FROM flows WHERE source <> '' AND bot_id IN (0, ?) ORDER BY bot_id", [$botId]), 'name', 's');
$def = val('SELECT name FROM flows WHERE is_default = 1 AND active = 1 AND bot_id IN (0, ?) ORDER BY bot_id DESC LIMIT 1', [$botId]);
?>
<h1>Ссылки и источники</h1>
<div class="card"><h2>Генератор ссылки</h2>
<?php if (!$bot): ?><p class="danger">Сначала <a href="?p=bots">подключите бота</a>.</p><?php endif; ?>
<form method="get" class="grid3"><input type="hidden" name="p" value="links">
<div><label>Бот</label><select name="bot"><?= bot_options($botId, '') ?></select></div>
<div><label>Источник (откуда пришёл пользователь)</label><input name="source" value="<?= h($src) ?>" placeholder="fb_ads, blogger_ivan, site"></div>
<div><label>Метка пользователя / click_id (необязательно)</label><input name="label" value="<?= h($lbl) ?>" placeholder="{click_id} из вашего трекера"></div>
<div><label>&nbsp;</label><button class="btn">Сформировать</button></div></form>
<?php if ($bot && $param !== ''): ?><p>Ссылка: <code data-copy>https://t.me/<?= h($bot) ?>?start=<?= h($param) ?></code></p>
<p class="muted">Источник → поле «Источник» пользователя и выбор цепочки. Метка (после <code>__</code>) → поле «Метка»: по ней внешние системы присылают постбеки. Допустимы латиница, цифры, <code>_</code> и <code>-</code>, до 64 символов всего. Подставьте макрос трекера вместо метки при формировании ссылки на стороне трекера.</p><?php endif; ?></div>
<div class="card"><h2>Источники пользователей этого бота</h2><table><tr><th>Источник</th><th>Пользователей</th><th>Заблокировали</th><th>Цепочка</th></tr>
<?php foreach ($stats as $s): $fl = $flowBy[strtolower($s['s'])] ?? $def; ?><tr><td><a href="?p=users&bot=<?= $botId ?>&source=<?= urlencode($s['s'] === '(без источника)' ? '-' : $s['s']) ?>"><?= h($s['s']) ?></a></td><td><?= $s['users'] ?></td><td><?= (int)$s['blocked'] ?></td><td><?= h($fl ?: '—') ?></td></tr><?php endforeach; ?></table></div>
