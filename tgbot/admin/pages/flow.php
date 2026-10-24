<?php
$fid = (int)($_GET['id'] ?? 0);
$flow = row('SELECT * FROM flows WHERE id = ?', [$fid]);
if (!$flow || !bot_ok((int)$flow['bot_id'])) redirect('?p=flows');
$title = $flow['name'];
$units = ['s' => [1, 'сек'], 'm' => [60, 'мин'], 'h' => [3600, 'час'], 'd' => [86400, 'дн']];

function split_delay(int $sec): array
{
    if ($sec > 0) foreach ([86400 => 'd', 3600 => 'h', 60 => 'm'] as $n => $u) if ($sec % $n === 0) return [$sec / $n, $u];
    return [$sec, 's'];
}

if (is_post()) {
    $do = post('do');
    need('flows.edit');
    if ($do === 'save_flow') {
        $isDef = isset($_POST['is_default']) ? 1 : 0;
        $bot = (int)post('bot_id');
        need_scope($bot);
        if ($isDef) q('UPDATE flows SET is_default = 0 WHERE bot_id = ?', [$bot]);
        update('flows', $fid, ['bot_id' => $bot, 'name' => mb_substr(post('name'), 0, 80) ?: 'Цепочка', 'source' => mb_substr(post('source'), 0, 64),
            'is_default' => $isDef, 'active' => isset($_POST['active']) ? 1 : 0, 'group_id' => (int)post('group_id')]);
        flash('Настройки цепочки сохранены');
    } elseif ($do === 'step_add') {
        $pos = (int)val('SELECT COALESCE(MAX(position), -1) + 1 FROM steps WHERE flow_id = ?', [$fid]);
        $_POST['step_id'] = insert('steps', ['flow_id' => $fid, 'position' => $pos, 'delay' => 3600, 'body' => 'Новое сообщение']);   // откроется после редиректа
        flash('Шаг добавлен');
    } else {
        $sid = (int)post('step_id');
        $step = row('SELECT * FROM steps WHERE id = ? AND flow_id = ?', [$sid, $fid]);
        if ($step) {
            if ($do === 'step_save' || $do === 'step_test') {
                $data = [
                    'delay' => min(3650 * 86400, max(0, (int)post('delay_val')) * ($units[post('delay_unit')][0] ?? 60)),
                    'body' => mb_substr((string)($_POST['body'] ?? ''), 0, 4096), 'image' => clean_image(post('image')),
                    'buttons' => sanitize_buttons(json_decode(post('buttons', '[]'), true)), 'group_id' => (int)post('group_id'),
                ];
                update('steps', $sid, $data);
                if ($do === 'step_test') {
                    $tg = (int)setting('admin_tg_id', '0');
                    if (!$tg) flash('Укажите свой Telegram ID в настройках', 'err');
                    else {
                        $r = send_to_user(['id' => 0, 'bot_id' => (int)($flow['bot_id'] ?: val('SELECT MIN(id) FROM bots WHERE active = 1')), 'tg_id' => $tg, 'first_name' => 'Админ', 'last_name' => '', 'username' => '', 'source' => ''], $data, 's' . $sid);
                        flash(($r['ok'] ?? false) ? 'Тест отправлен вам в Telegram' : 'Ошибка отправки: ' . ($r['description'] ?? '?'), ($r['ok'] ?? false) ? 'ok' : 'err');
                    }
                } else flash('Шаг ' . ($step['position'] + 1) . ' сохранён');
            } elseif ($do === 'step_del') {
                q('DELETE FROM steps WHERE id = ?', [$sid]); flash('Шаг удалён');
            } elseif ($do === 'step_up' || $do === 'step_down') {
                $other = $do === 'step_up'
                    ? row('SELECT * FROM steps WHERE flow_id = ? AND position < ? ORDER BY position DESC LIMIT 1', [$fid, $step['position']])
                    : row('SELECT * FROM steps WHERE flow_id = ? AND position > ? ORDER BY position LIMIT 1', [$fid, $step['position']]);
                if ($other) { update('steps', $sid, ['position' => $other['position']]); update('steps', (int)$other['id'], ['position' => $step['position']]); }
            }
        }
    }
    redirect('?p=flow&id=' . $fid . (isset($_POST['step_id']) ? '#s' . (int)$_POST['step_id'] : ''));
}
$steps = rows('SELECT * FROM steps WHERE flow_id = ? ORDER BY position', [$fid]);
$stat = [];
foreach (rows("SELECT ref, COUNT(*) c, COUNT(DISTINCT user_id) u FROM events WHERE type IN ('step_sent','click') GROUP BY ref") as $r) $stat[$r['ref']] = $r;
?>
<?= pagehead($flow['name'], '<a class="btn sec" href="?p=flows">← Все цепочки</a>', ($flow['active'] ? 'Активна' : 'Выключена') . ' · бот: ' . bot_label((int)$flow['bot_id']) . ' · источник: ' . ($flow['source'] ?: 'любой') . ($flow['is_default'] ? ' · по умолчанию' : '')) ?>
<details class="card" <?= isset($_GET['settings']) ? 'open' : '' ?>><summary style="cursor:pointer;font-weight:600;list-style:none"><?= icon('gear') ?> Параметры цепочки <span class="muted" style="font-weight:400">— название, бот, источник, группа</span></summary>
<form method="post" class="guard"><?= csrf_field() ?><input type="hidden" name="do" value="save_flow">
<div class="grid3"><div><label>Название</label><input name="name" value="<?= h($flow['name']) ?>"></div>
<div><label>Бот</label><select name="bot_id"><?= bot_options((int)$flow['bot_id'], 'Все боты') ?></select></div>
<div><label>Источник (start-параметр)</label><input name="source" value="<?= h($flow['source']) ?>" placeholder="пусто = любой"></div></div>
<div class="grid3"><div><label>При входе добавить в группу</label><select name="group_id"><?= group_options((int)$flow['group_id']) ?></select></div></div>
<div class="row"><label class="chk"><input type="checkbox" name="active" <?= $flow['active'] ? 'checked' : '' ?>> Активна</label>
<label class="chk"><input type="checkbox" name="is_default" <?= $flow['is_default'] ? 'checked' : '' ?>> По умолчанию (для подписчиков без подходящего источника)</label></div>
<?php if ($flow['source']): foreach (rows('SELECT username FROM bots WHERE username <> \'\' AND (? = 0 OR id = ?)', [$flow['bot_id'], $flow['bot_id']]) as $bb): ?><p class="hint">Ссылка: <code data-copy>https://t.me/<?= h($bb['username']) ?>?start=<?= h($flow['source']) ?></code> (клик — скопировать)</p><?php endforeach; endif; ?>
<p><button class="btn" data-save>Сохранить параметры</button></p></form></details>

<div class="timeline">
<?php foreach ($steps as $i => $s): [$dv, $du] = split_delay((int)$s['delay']); $st = $stat['s' . $s['id']] ?? null;
  $preview = trim(preg_replace('/\s+/u', ' ', html_to_plain(md_to_html((string)$s['body'])))) ?: ($s['image'] ? '(картинка)' : '(пусто)');
  $nb = count(flat_buttons($s)); ?>
<div class="gap"><?= icon('check') ?> <?= $i === 0 ? ($s['delay'] ? 'через ' . h(human_delay((int)$s['delay'])) . ' после /start' : 'сразу после /start') : 'через ' . h(human_delay((int)$s['delay'])) . ' после предыдущего' ?></div>
<details class="card step" id="s<?= $s['id'] ?>" <?= count($steps) <= 2 ? 'open' : '' ?>>
<summary><span class="stepno"><?= $i + 1 ?></span><span class="steptxt"><b><?= h(mb_substr($preview, 0, 90)) ?></b>
<small><?= $nb ? $nb . ' ' . ($nb === 1 ? 'кнопка' : 'кнопок') . ' · ' : '' ?><?= $s['image'] ? 'с картинкой · ' : '' ?>отправлено: <?= (int)($st['c'] ?? 0) ?></small></span><span class="chev"><?= icon('chev') ?></span></summary>
<form method="post" class="stepbody guard"><?= csrf_field() ?><input type="hidden" name="step_id" value="<?= $s['id'] ?>">
<div class="row" style="margin:12px 0 4px"><label style="margin:0">Отправить через</label><input type="number" min="0" name="delay_val" value="<?= $dv ?>" style="width:90px" aria-label="Задержка">
<select name="delay_unit" style="width:auto" aria-label="Единица"><?php foreach ($units as $k => $u) echo '<option value="' . $k . '"' . ($k === $du ? ' selected' : '') . '>' . $u[1] . '</option>'; ?></select>
<span class="muted"><?= $i === 0 ? 'после входа подписчика в цепочку' : 'после предыдущего сообщения' ?></span>
<span class="sp"></span><label style="margin:0">Затем в группу</label><select name="group_id" style="width:auto"><?= group_options((int)$s['group_id'], '— не добавлять —') ?></select></div>
<div style="margin-top:10px"><?= msg_editor($s) ?></div>
<?php
 $clicks = array_filter($stat, fn($r, $k) => str_starts_with($k, 's' . $s['id'] . ':'), ARRAY_FILTER_USE_BOTH);
 if ($clicks): ?><p class="muted">Клики: <?php foreach ($clicks as $k => $r) echo '#' . (explode(':', $k)[1] + 1) . ' — ' . $r['c'] . ' (' . $r['u'] . ' польз.) &nbsp; '; ?></p><?php endif; ?>
<div class="row sticky-actions"><button class="btn" name="do" value="step_save" data-save>Сохранить шаг</button><button class="btn sec" name="do" value="step_test" title="Сохранит шаг и пришлёт сообщение вам в Telegram">Сохранить и отправить тест мне</button><span class="sp"></span>
<button class="btn sm sec" name="do" value="step_up" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Выше" title="Переместить выше">↑</button><button class="btn sm sec" name="do" value="step_down" <?= $i === count($steps) - 1 ? 'disabled' : '' ?> aria-label="Ниже" title="Переместить ниже">↓</button>
<button class="btn sm sec danger" name="do" value="step_del" data-confirm="Шаг <?= $i + 1 ?> будет удалён.">Удалить</button></div></form></details>
<?php endforeach; ?>
</div>
<form method="post"><?= csrf_field() ?><button class="btn sec" name="do" value="step_add"><?= icon('plus') ?> Добавить шаг</button></form>
