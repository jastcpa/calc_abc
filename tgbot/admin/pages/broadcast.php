<?php
$bid = (int)($_GET['id'] ?? 0);
$b = row('SELECT * FROM broadcasts WHERE id = ?', [$bid]);
if (!$b || !bot_ok((int)$b['bot_id'])) redirect('?p=broadcasts');
$title = $b['name'];
$editable = in_array($b['status'], ['draft', 'scheduled'], true);

if (is_post()) {
    $do = post('do');
    need(in_array($do, ['tick', 'cancel'], true) ? 'broadcasts.send' : 'broadcasts.edit');
    if (in_array($do, ['save', 'test'], true) && $editable) {
        need_scope((int)post('bot_id'));
        // без права отправки нельзя планировать/запускать и менять уже запланированную рассылку
        if ($do === 'save' && (post('mode', 'draft') !== 'draft' || $b['status'] === 'scheduled')) need('broadcasts.send');
        $data = [
            'bot_id' => (int)post('bot_id'), 'name' => mb_substr(post('name'), 0, 80) ?: 'Рассылка', 'body' => mb_substr((string)($_POST['body'] ?? ''), 0, 4096), 'image' => clean_image(post('image')),
            'buttons' => sanitize_buttons(json_decode(post('buttons', '[]'), true)),
            'inc_groups' => json_encode(post_ids('inc')), 'exc_groups' => json_encode(post_ids('exc')),
        ];
        $mode = post('mode', 'draft');
        if ($do === 'test') {
            update('broadcasts', $bid, $data);
            $tg = (int)setting('admin_tg_id', '0');
            if (!$tg) flash('Укажите свой Telegram ID в настройках', 'err');
            else {
                $r = send_to_user(['id' => 0, 'bot_id' => (int)($data['bot_id'] ?: val('SELECT MIN(id) FROM bots WHERE active = 1')), 'tg_id' => $tg, 'first_name' => 'Админ', 'last_name' => '', 'username' => '', 'source' => ''], $data, 'b' . $bid);
                flash(($r['ok'] ?? false) ? 'Тест отправлен вам в Telegram' : 'Ошибка: ' . ($r['description'] ?? '?'), ($r['ok'] ?? false) ? 'ok' : 'err');
            }
        } else {
            $at = parse_local(post('send_at'));
            if ($mode === 'schedule') {
                if (!$at) { flash('Укажите дату и время отправки', 'err'); update('broadcasts', $bid, $data); redirect('?p=broadcast&id=' . $bid); }
                $data += ['status' => 'scheduled', 'send_at' => $at];
            } elseif ($mode === 'now') {
                $data += ['status' => 'scheduled', 'send_at' => now()];
            } else {
                $data += ['status' => 'draft', 'send_at' => $at];
            }
            update('broadcasts', $bid, $data);
            if ($mode !== 'draft') audit('broadcast.' . $mode, '#' . $bid . ' ' . $data['name']);
            if ($mode === 'now') {
                bc_start($bid);
                run_tick(20);
                flash('Рассылка запущена');
            } else flash($mode === 'schedule' ? 'Рассылка запланирована на ' . fmt_dt($at) : 'Черновик сохранён');
        }
    } elseif ($do === 'tick') {
        $r = run_tick(25); flash('Отправлено в этом проходе: ' . ($r['broadcast'] ?? 0));
    } elseif ($do === 'cancel') {
        if ($b['status'] === 'scheduled') q("UPDATE broadcasts SET status='draft' WHERE id=?", [$bid]);
        elseif ($b['status'] === 'sending') {
            q("UPDATE bq SET status='skipped' WHERE broadcast_id=? AND status='pending'", [$bid]);
            q("UPDATE broadcasts SET status='cancelled', finished_at=? WHERE id=?", [now(), $bid]);
        }
        flash('Отменено');
    } elseif ($do === 'delete' && $b['status'] !== 'sending') {
        q('DELETE FROM bq WHERE broadcast_id=?', [$bid]); q('DELETE FROM broadcasts WHERE id=?', [$bid]);
        flash('Рассылка удалена'); redirect('?p=broadcasts');
    } elseif ($do === 'copy') {
        $nid = insert('broadcasts', ['name' => $b['name'] . ' (копия)', 'body' => $b['body'], 'image' => $b['image'], 'buttons' => $b['buttons'],
            'inc_groups' => $b['inc_groups'], 'exc_groups' => $b['exc_groups'], 'bot_id' => $b['bot_id'], 'created_at' => now()]);
        redirect('?p=broadcast&id=' . $nid);
    }
    redirect('?p=broadcast&id=' . $bid);
}
$inc = array_map('intval', json_arr($b['inc_groups'])); $exc = array_map('intval', json_arr($b['exc_groups']));
$groups = rows('SELECT g.*, (SELECT COUNT(*) FROM user_groups ug JOIN users uu ON uu.id = ug.user_id WHERE ug.group_id = g.id' . scope_sql('uu.bot_id') . ((int)$b['bot_id'] ? ' AND uu.bot_id = ' . (int)$b['bot_id'] : '') . ') cnt FROM groups g ORDER BY name');
$recipients = bc_count($b);
$sl = ['draft' => 'черновик', 'scheduled' => 'запланирована', 'sending' => 'отправляется', 'done' => 'завершена', 'cancelled' => 'отменена'];
?>
<p><a href="?p=broadcasts">← Все рассылки</a></p>
<h1><?= h($b['name']) ?> <span class="badge"><?= $sl[$b['status']] ?></span></h1>

<?php if (!$editable): ?>
<div class="card"><h2>Статистика</h2>
<?php
 $ck = rows("SELECT label, ref, COUNT(*) c, COUNT(DISTINCT user_id) u FROM events WHERE type='click' AND ref LIKE ? GROUP BY ref ORDER BY ref", ['b' . $bid . ':%']);
 $skipped = (int)val("SELECT COUNT(*) FROM bq WHERE broadcast_id=? AND status='skipped'", [$bid]);
?>
<div class="cards"><div class="kpi"><b><?= $b['total'] ?></b><span>получателей</span></div><div class="kpi"><b><?= $b['sent'] ?></b><span>доставлено</span></div>
<div class="kpi"><b><?= $b['failed'] ?></b><span>ошибок</span></div><div class="kpi"><b><?= $skipped ?></b><span>пропущено</span></div>
<div class="kpi"><b><?= array_sum(array_column($ck, 'u')) ?></b><span>кликов (уник.)</span></div></div>
<?php if ($ck): ?><table><tr><th>Кнопка</th><th>Кликов</th><th>Уникальных</th></tr><?php foreach ($ck as $r) echo '<tr><td>' . h($r['label']) . '</td><td>' . $r['c'] . '</td><td>' . $r['u'] . '</td></tr>'; ?></table><?php endif; ?>
<p class="muted">Начало: <?= fmt_dt((int)$b['started_at']) ?> · Конец: <?= fmt_dt((int)$b['finished_at']) ?></p>
<form method="post" class="row"><?= csrf_field() ?>
<?php if ($b['status'] === 'sending'): ?><button class="btn" name="do" value="tick">Продолжить отправку сейчас</button><button class="btn red" name="do" value="cancel" data-confirm="Остановить рассылку?">Остановить</button><?php endif; ?>
<button class="btn sec" name="do" value="copy">Создать копию</button>
<?php if ($b['status'] !== 'sending'): ?><button class="btn sec" name="do" value="delete" data-confirm="Удалить рассылку?">Удалить</button><?php endif; ?></form></div>
<div class="card"><h2>Текст</h2><div class="tgbg"><div class="bubble"><?= apply_vars(md_to_html($b['body']), ['first_name' => 'Иван']) ?></div></div></div>
<?php else: ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><label>Название (для вас)</label><input name="name" value="<?= h($b['name']) ?>"><div style="margin-top:12px"><?= msg_editor($b) ?></div></div>
<div class="card"><h2>Кому отправлять</h2>
<label>Бот</label><select name="bot_id" style="max-width:360px"><?= bot_options((int)$b['bot_id'], 'Все боты (каждому — через его бота)') ?></select>
<p class="muted">Получатели — активные пользователи выбранного бота (или всех ботов), не заблокировавшие бота. Если группы не выбраны — всем. Группы общие для всех ботов.</p>
<div class="grid2"><div><b>Только участникам групп (любой из):</b>
<?php foreach ($groups as $g): ?><label class="chk"><input type="checkbox" name="inc[]" value="<?= $g['id'] ?>" <?= in_array((int)$g['id'], $inc, true) ? 'checked' : '' ?>> <?= group_tag($g) ?> <span class="muted"><?= $g['cnt'] ?></span></label><?php endforeach; if (!$groups) echo '<p class="muted">Нет групп</p>'; ?></div>
<div><b>Исключить участников групп:</b>
<?php foreach ($groups as $g): ?><label class="chk"><input type="checkbox" name="exc[]" value="<?= $g['id'] ?>" <?= in_array((int)$g['id'], $exc, true) ? 'checked' : '' ?>> <?= group_tag($g) ?></label><?php endforeach; ?></div></div>
<p>Сейчас под условия подходит: <b><?= $recipients ?></b> польз. <span class="muted">(пересчитается после сохранения)</span></p></div>
<div class="card"><h2>Когда отправить</h2>
<label class="chk"><input type="radio" name="mode" value="draft" <?= $b['status'] === 'draft' ? 'checked' : '' ?>> Сохранить как черновик</label>
<label class="chk"><input type="radio" name="mode" value="schedule" <?= $b['status'] === 'scheduled' ? 'checked' : '' ?>> Запланировать на:
<input type="datetime-local" name="send_at" value="<?= h(local_input($b['send_at'] ? (int)$b['send_at'] : null)) ?>" style="width:auto"> <span class="muted">(время по поясу <?= h(setting('tz', 'Europe/Moscow')) ?>, сейчас <?= fmt_dt(now()) ?>)</span></label>
<label class="chk"><input type="radio" name="mode" value="now"> Отправить сейчас</label>
<div class="row" style="margin-top:10px"><button class="btn" name="do" value="save">Применить</button><button class="btn sec" name="do" value="test">Отправить тест мне</button>
<?php if ($b['status'] === 'scheduled'): ?><button class="btn sec" name="do" value="cancel">Снять с планирования</button><?php endif; ?>
<button class="btn sec" name="do" value="delete" data-confirm="Удалить рассылку?">Удалить</button></div></div>
</form>
<?php endif; ?>
