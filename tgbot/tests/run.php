<?php
// Запуск: php tests/run.php   (без зависимостей; работает на временной SQLite-базе)
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/tgbot_test_' . bin2hex(random_bytes(4));
mkdir($tmp);
$cfgFile = $tmp . '/config.php';
file_put_contents($cfgFile, "<?php\nreturn " . var_export(['db' => $tmp . '/t.sqlite'], true) . ";\n");
putenv('TGBOT_CONFIG=' . $cfgFile);

require __DIR__ . '/../lib/bootstrap.php';
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ---------- мини-фреймворк ----------
$T = ['pass' => 0, 'fail' => 0, 'name' => ''];
function section(string $n): void { global $T; $T['name'] = $n; echo "\n== $n\n"; }
function ok($cond, string $msg): void
{
    global $T;
    if ($cond) { $T['pass']++; echo "  ✔ $msg\n"; }
    else { $T['fail']++; echo "  ✘ $msg   [{$T['name']}]\n"; }
}
function eq($a, $b, string $msg): void
{
    ok($a === $b, $msg . ($a === $b ? '' : ' (получено ' . var_export($a, true) . ', ожидалось ' . var_export($b, true) . ')'));
}
function contains(string $hay, string $needle, string $msg): void { ok(str_contains($hay, $needle), $msg); }

// ---------- транспорт Telegram: запись вызовов + управляемые ответы ----------
$CALLS = [];
$RESP = [];   // ['sendMessage' => fn(botId, params) => array]
$GLOBALS['TG_TRANSPORT'] = function (int $bot, string $method, array $p) use (&$CALLS, &$RESP): array {
    $CALLS[] = ['bot' => $bot, 'method' => $method, 'p' => $p];
    if (isset($RESP[$method])) return ($RESP[$method])($bot, $p);
    return ['ok' => true, 'result' => ['message_id' => 1]];
};
function calls(string $method = ''): array
{
    global $CALLS;
    return array_values(array_filter($CALLS, fn($c) => $method === '' || $c['method'] === $method));
}
function reset_calls(): void { global $CALLS, $RESP; $CALLS = []; $RESP = []; }

function reset_db(): void
{
    foreach (['admin_user_bots', 'admin_users', 'admin_roles', 'audit', 'users', 'user_groups', 'groups', 'flows', 'steps', 'user_flows', 'broadcasts', 'bq', 'events', 'in_rules', 'out_hooks', 'out_queue', 'bots', 'login_fail'] as $t) db()->exec("DELETE FROM $t");
    db()->exec("DELETE FROM sqlite_sequence");
    $GLOBALS['TG_TRANSPORT'] && reset_calls();
}
function mkbot(string $name = 'B'): int
{
    return insert('bots', ['name' => $name, 'token' => '1:' . bin2hex(random_bytes(16)), 'username' => strtolower($name) . '_bot', 'secret' => rand_token(8), 'active' => 1, 'created_at' => now()]);
}
function mkflow(int $bot, string $source = '', bool $default = false, array $delays = [0, 60]): int
{
    $f = insert('flows', ['bot_id' => $bot, 'name' => "F$bot-$source", 'source' => $source, 'is_default' => $default ? 1 : 0, 'active' => 1, 'created_at' => now()]);
    foreach ($delays as $i => $d) insert('steps', ['flow_id' => $f, 'position' => $i, 'delay' => $d, 'body' => "Step $i for {first_name}"]);
    return $f;
}
function from(int $id, string $name = 'Ann'): array { return ['id' => $id, 'first_name' => $name, 'username' => strtolower($name)]; }
function start(int $bot, int $tg, ?string $param = null, string $name = 'Ann'): array
{
    $u = user_touch(from($tg, $name), $param, $bot);
    flow_enroll($u);
    return $u;
}

migrate();
set_setting('secret', 'test-secret');
set_setting('tz', 'Europe/Moscow');

// ======================================================================
section('Markdown → Telegram HTML');
eq(md_to_html('**a** _b_ __c__ ~~d~~ ||e|| `f`'), '<b>a</b> <i>b</i> <u>c</u> <s>d</s> <tg-spoiler>e</tg-spoiler> <code>f</code>', 'базовая разметка');
eq(md_to_html('<script>alert(1)</script> & "q"'), '&lt;script&gt;alert(1)&lt;/script&gt; &amp; "q"', 'HTML экранируется');
eq(md_to_html('[x](https://a.com/?a=1&b=2)'), '<a href="https://a.com/?a=1&amp;b=2">x</a>', 'ссылка с & в атрибуте');
eq(md_to_html('[x](javascript:alert(1))'), '[x](javascript:alert(1))', 'javascript: не превращается в ссылку');
eq(md_to_html('[x](https://a.com/"onmouseover="y)'), '[x](https://a.com/"onmouseover="y)', 'кавычка в URL не пробивает атрибут');
eq(md_to_html("```\nx **y** <b>\n```"), '<pre>x **y** &lt;b&gt;</pre>', 'блок кода без форматирования внутри');
eq(md_to_html("> a\n> b\nc"), "<blockquote>a\nb</blockquote>\nc", 'цитата');
eq(md_to_html("# H\n- i"), "<b>H</b>\n• i", 'заголовок и список');
eq(md_to_html('snake_case_word 2*3*4'), 'snake_case_word 2*3*4', 'snake_case и умножение не курсив');
eq(md_to_html("\x01" . "0" . "\x02 text"), '0 text', 'управляющие символы не позволяют подставить stash');
eq(md_to_html("`a` \x010\x02"), '<code>a</code> 0', 'инъекция индекса stash невозможна');
eq(apply_vars('Hi {first_name}', ['first_name' => '<b>X</b>']), 'Hi &lt;b&gt;X&lt;/b&gt;', 'подстановка имени экранируется');

// ======================================================================
section('URL-проверки и подписи');
ok(url_is_button_safe('https://a.com/x?y=1'), 'https разрешён');
ok(url_is_button_safe('tg://resolve?domain=x'), 'tg:// разрешён');
ok(!url_is_button_safe('javascript:alert(1)'), 'javascript: запрещён');
ok(!url_is_button_safe('data:text/html,hi'), 'data: запрещён');
ok(!url_is_button_safe('https://a.com/"><x'), 'кавычки запрещены');
ok(!url_is_public_http('http://127.0.0.1/x'), 'SSRF: loopback запрещён');
ok(!url_is_public_http('http://169.254.169.254/latest'), 'SSRF: metadata запрещён');
ok(!url_is_public_http('http://10.0.0.5/'), 'SSRF: private запрещён');
ok(!url_is_public_http('ftp://8.8.8.8/'), 'SSRF: не-http запрещён');
ok(url_is_public_http('https://8.8.8.8/x'), 'публичный IP разрешён');
ok(link_sig('s1', 0, 5) !== link_sig('s1', 0, 6) && link_sig('s1', 0, 5) !== link_sig('s1', 1, 5), 'подпись зависит от пользователя и кнопки');
eq(human_delay(3600), '1 ч', 'human_delay час'); eq(human_delay(90), '90 сек', 'human_delay секунды');

section('Нормализация кнопок и картинок');
$san = json_decode(sanitize_buttons([[['text' => ' Ok ', 'type' => 'url', 'url' => 'https://a.com'], ['text' => 'bad', 'url' => 'javascript:1'], 'str', ['text' => '']], 'notarray', [['text' => str_repeat('я', 100), 'type' => 'cb', 'reply' => 'r', 'group' => '7']]]), true);
eq(count($san), 2, 'мусорные ряды и небезопасные кнопки отброшены');
eq([$san[0][0]['text'], $san[0][0]['track']], ['Ok', true], 'текст тримится, track по умолчанию включён');
eq([mb_strlen($san[1][0]['text']), $san[1][0]['group']], [64, 7], 'длина текста ≤ 64, группа приведена к int');
eq(sanitize_buttons('garbage'), '[]', 'не-массив → пустой список');
eq(clean_image('javascript:alert(1)'), '', 'картинка: не-http отклонена');
eq(clean_image(' https://a.com/x.jpg '), 'https://a.com/x.jpg', 'картинка: https принята');

// ======================================================================
section('Пользователи и несколько ботов');
reset_db();
$b1 = mkbot('Alpha'); $b2 = mkbot('Beta');
$u1 = user_touch(from(100), 'fb_ads__clk_1', $b1);
eq([$u1['source'], $u1['label'], $u1['_new']], ['fb_ads', 'clk_1', true], 'source/label из start-параметра');
$u2 = user_touch(from(100), null, $b2);
ok($u2['_new'] && $u2['id'] !== $u1['id'], 'тот же tg_id в другом боте — отдельный пользователь');
eq((int)val('SELECT COUNT(*) FROM users'), 2, 'две записи');
$u1b = user_touch(from(100), 'other', $b1);
eq([$u1b['_new'], $u1b['source']], [false, 'fb_ads'], 'повторный вход: источник не перезаписывается');
$ux = user_touch(from(101), "<script>__'\"x", $b1);
eq([$ux['source'], $ux['label']], ['script', 'x'], 'start-параметр очищается от лишних символов');
user_blocked((int)$u1['id']);
eq(val('SELECT status FROM users WHERE id=?', [$u1['id']]), 'blocked', 'блокировка фиксируется');
user_touch(from(100), null, $b1);
eq(val('SELECT status FROM users WHERE id=?', [$u1['id']]), 'active', 'возврат пользователя снимает блокировку');
$un = user_touch(['id' => 100, 'first_name' => 'Ann'], null, $b1);
eq(val('SELECT username FROM users WHERE id=?', [$u1['id']]), 'ann', 'пустой username не затирает старый');

// ======================================================================
section('Выбор цепочки (боты × источники)');
reset_db();
$b1 = mkbot('Alpha'); $b2 = mkbot('Beta');
$fAllDef = mkflow(0, '', true);
$fB1Def = mkflow($b1, '', true);
$fB1Fb = mkflow($b1, 'fb');
$fAllFb = mkflow(0, 'fb');
eq((int)flow_pick('fb', $b1)['id'], $fB1Fb, 'источник + бот: приоритет цепочки бота');
eq((int)flow_pick('fb', $b2)['id'], $fAllFb, 'источник: цепочка «для всех ботов»');
eq((int)flow_pick('FB', $b1)['id'], $fB1Fb, 'источник без учёта регистра');
eq((int)flow_pick('zzz', $b1)['id'], $fB1Def, 'неизвестный источник → default бота');
eq((int)flow_pick(null, $b2)['id'], $fAllDef, 'у бота нет default → общий default');
q('UPDATE flows SET active = 0 WHERE id = ?', [$fB1Fb]);
eq((int)flow_pick('fb', $b1)['id'], $fAllFb, 'неактивная цепочка пропускается');

// ======================================================================
section('Приветственная цепочка: задержки и отправка');
reset_db();
$b1 = mkbot('Alpha'); $b2 = mkbot('Beta');
$f = mkflow($b1, '', true, [0, 3600, 86400]);
$g = insert('groups', ['name' => 'Прогрев', 'color' => '#000', 'created_at' => now()]);
q('UPDATE steps SET group_id = ? WHERE flow_id = ? AND position = 1', [$g, $f]);
$u = start($b1, 500, null, 'Zed');
eq((int)val('SELECT COUNT(*) FROM user_flows'), 1, 'пользователь записан в цепочку');
$sent = flow_tick(time() + 5);
eq($sent, 1, 'сразу отправлен 1-й шаг');
$c = calls('sendMessage');
eq([count($c), $c[0]['bot'], $c[0]['p']['chat_id']], [1, $b1, 500], 'отправлено через токен именно этого бота');
contains($c[0]['p']['text'], 'Zed', 'имя подставлено');
eq(flow_tick(time() + 5), 0, 'до истечения задержки 2-й шаг не уходит');
$uf = row('SELECT * FROM user_flows');
ok(abs((int)$uf['next_at'] - (now() + 3600)) <= 3, 'next_at = сейчас + задержка шага');
q('UPDATE user_flows SET next_at = ?', [now() - 1]);
eq(flow_tick(time() + 5), 1, '2-й шаг уходит по истечении задержки');
ok((bool)val('SELECT 1 FROM user_groups WHERE user_id = ? AND group_id = ?', [$u['id'], $g]), 'после шага пользователь добавлен в группу');
q('UPDATE user_flows SET next_at = ?', [now() - 1]);
flow_tick(time() + 5);
eq(val('SELECT status FROM user_flows'), 'done', 'после последнего шага цепочка завершена');
eq((int)val("SELECT COUNT(*) FROM events WHERE type='flow_done'"), 1, 'событие flow_done');
eq(count(calls('sendMessage')), 3, 'всего 3 сообщения, без дублей');
flow_enroll($u);
eq((int)val('SELECT COUNT(*) FROM user_flows'), 1, 'повторный вход не дублирует запись');
flow_enroll($u, true);
eq(val('SELECT status FROM user_flows'), 'active', 'restart перезапускает цепочку');

section('Цепочка: ошибки Telegram');
reset_db();
$b1 = mkbot('Alpha'); mkflow($b1, '', true, [0, 0]);
$u = start($b1, 600);
$RESP['sendMessage'] = fn() => ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'];
flow_tick(time() + 5);
eq(val('SELECT status FROM users WHERE id=?', [$u['id']]), 'blocked', '403 помечает пользователя заблокированным');
eq(val('SELECT status FROM user_flows'), 'stopped', 'цепочка остановлена');
reset_db(); $b1 = mkbot('A'); mkflow($b1, '', true, [0]); $u = start($b1, 601);
$RESP['sendMessage'] = fn() => ['ok' => false, 'error_code' => 429, 'description' => 'Too Many', 'parameters' => ['retry_after' => 40]];
flow_tick(time() + 5);
ok((int)val('SELECT next_at FROM user_flows') >= now() + 40, '429 откладывает отправку на retry_after');
eq(val('SELECT status FROM user_flows'), 'active', 'при 429 цепочка остаётся активной');
reset_db(); $b1 = mkbot('A'); mkflow($b1, '', true, [0]); $u = start($b1, 602);
$n = 0;
$RESP['sendMessage'] = function ($b, $p) use (&$n) { $n++; return isset($p['parse_mode']) ? ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"] : ['ok' => true, 'result' => []]; };
eq(flow_tick(time() + 5), 1, 'ошибка разбора разметки → повтор обычным текстом');
eq($n, 2, 'сделано 2 попытки');

// ======================================================================
section('Кнопки: callback, клики, группы');
reset_db();
$b1 = mkbot('A'); $f = mkflow($b1, '', true, [0]);
$g = insert('groups', ['name' => 'Нажали', 'color' => '#111', 'created_at' => now()]);
$btns = [[['text' => 'Да', 'type' => 'cb', 'reply' => 'Спасибо **за клик**', 'group' => $g], ['text' => 'Сайт', 'type' => 'url', 'url' => 'https://example.com/p', 'track' => true]],
         [['text' => 'Плохая', 'type' => 'url', 'url' => 'javascript:alert(1)'], ['text' => '', 'type' => 'cb']]];
q('UPDATE steps SET buttons = ? WHERE flow_id = ?', [json_encode($btns), $f]);
$stepId = (int)val('SELECT id FROM steps');
$u = start($b1, 700);
set_setting('base_url', 'https://bot.example.com/tgbot');
flow_tick(time() + 5);
$kb = calls('sendMessage')[0]['p']['reply_markup']['inline_keyboard'];
eq(count($kb), 1, 'небезопасная и пустая кнопки отфильтрованы вместе с рядом');
eq($kb[0][0]['callback_data'], "c|s$stepId|0", 'callback_data содержит ключ шага и индекс');
contains($kb[0][1]['url'], '/go.php?m=s' . $stepId . '&i=1&u=' . $u['id'] . '&s=' . link_sig("s$stepId", 1, (int)$u['id']), 'URL-кнопка ведёт через трекер с подписью');
reset_calls();
handle_callback(['id' => 'cb1', 'from' => from(700), 'data' => "c|s$stepId|0"], $b1);
eq((int)val("SELECT COUNT(*) FROM events WHERE type='click' AND ref=?", ["s$stepId:0"]), 1, 'клик записан');
ok((bool)val('SELECT 1 FROM user_groups WHERE user_id=? AND group_id=?', [$u['id'], $g]), 'клик добавил в группу');
eq(count(calls('answerCallbackQuery')), 1, 'callback подтверждён');
contains(calls('sendMessage')[0]['p']['text'], '<b>за клик</b>', 'ответ на клик отправлен с разметкой');
handle_callback(['id' => 'x', 'from' => from(700), 'data' => 'c|s999|0'], $b1);
handle_callback(['id' => 'x', 'from' => from(700), 'data' => 'garbage'], $b1);
handle_callback(['id' => 'x', 'from' => from(700), 'data' => "c|s$stepId|99"], $b1);
eq((int)val("SELECT COUNT(*) FROM events WHERE type='click'"), 1, 'мусорные callback игнорируются без ошибок');
set_setting('base_url', 'http://insecure.local');
$kb = build_markup(msg_by_key("s$stepId"), "s$stepId", $u)['inline_keyboard'];
eq($kb[0][1]['url'], 'https://example.com/p', 'без https трекинг отключается, ссылка прямая');

// ======================================================================
section('Рассылки: аудитория, боты, расписание');
reset_db();
$b1 = mkbot('A'); $b2 = mkbot('B'); $b3 = mkbot('Off'); q('UPDATE bots SET active = 0 WHERE id = ?', [$b3]);
$gv = insert('groups', ['name' => 'VIP', 'color' => '#1', 'created_at' => now()]);
$gx = insert('groups', ['name' => 'Excl', 'color' => '#2', 'created_at' => now()]);
$us = [];
foreach ([[$b1, 1], [$b1, 2], [$b1, 3], [$b2, 1], [$b2, 4], [$b3, 9]] as [$bot, $tg]) $us["$bot:$tg"] = user_touch(from($tg), null, $bot);
group_add((int)$us["$b1:1"]['id'], $gv); group_add((int)$us["$b1:2"]['id'], $gv); group_add((int)$us["$b1:2"]['id'], $gx); group_add((int)$us["$b2:1"]['id'], $gv);
user_blocked((int)$us["$b1:3"]['id']);
$mk = fn(array $o) => insert('broadcasts', $o + ['name' => 'x', 'body' => 'Привет {first_name}', 'created_at' => now()]);
$all = $mk([]);
eq(bc_count(row('SELECT * FROM broadcasts WHERE id=?', [$all])), 4, 'все боты: только активные боты и не заблокированные пользователи');
$only1 = $mk(['bot_id' => $b1]);
eq(bc_count(row('SELECT * FROM broadcasts WHERE id=?', [$only1])), 2, 'рассылка одного бота');
$vip = $mk(['inc_groups' => json_encode([$gv])]);
eq(bc_count(row('SELECT * FROM broadcasts WHERE id=?', [$vip])), 3, 'группа VIP по всем ботам (группы общие)');
$vipNoX = $mk(['inc_groups' => json_encode([$gv]), 'exc_groups' => json_encode([$gx])]);
eq(bc_count(row('SELECT * FROM broadcasts WHERE id=?', [$vipNoX])), 2, 'исключение группы');
$vipB2 = $mk(['bot_id' => $b2, 'inc_groups' => json_encode([$gv])]);
eq(bc_count(row('SELECT * FROM broadcasts WHERE id=?', [$vipB2])), 1, 'бот + группа');

reset_calls();
bc_start($vipNoX); bc_start($vipNoX);
eq((int)val('SELECT COUNT(*) FROM bq WHERE broadcast_id=?', [$vipNoX]), 2, 'повторный старт не дублирует очередь');
eq(bc_tick(time() + 10), 2, 'отправлено 2');
$byBot = array_count_values(array_column(calls('sendMessage'), 'bot'));
eq([$byBot[$b1] ?? 0, $byBot[$b2] ?? 0], [1, 1], 'каждому — через его собственного бота');
$r = row('SELECT * FROM broadcasts WHERE id=?', [$vipNoX]);
eq([$r['status'], $r['sent'], $r['total'], $r['failed']], ['done', 2, 2, 0], 'итоги рассылки');
eq((int)val("SELECT COUNT(*) FROM events WHERE type='bc_sent' AND ref=?", ["b$vipNoX"]), 2, 'события bc_sent');

reset_calls();
$future = $mk(['status' => 'scheduled', 'send_at' => now() + 3600, 'bot_id' => $b2]);
bc_tick(time() + 5);
eq([row('SELECT status FROM broadcasts WHERE id=?', [$future])['status'], count(calls('sendMessage'))], ['scheduled', 0], 'до времени отправки рассылка ждёт');
q('UPDATE broadcasts SET send_at = ? WHERE id = ?', [now() - 5, $future]);
bc_tick(time() + 5);
eq([row('SELECT status FROM broadcasts WHERE id=?', [$future])['status'], count(calls('sendMessage'))], ['done', 2], 'по наступлении времени — уходит');

reset_calls();
$fail = $mk(['bot_id' => $b1, 'status' => 'scheduled', 'send_at' => now() - 1]);
$RESP['sendMessage'] = fn($b, $p) => $p['chat_id'] == 1 ? ['ok' => false, 'error_code' => 400, 'description' => 'chat not found'] : ['ok' => true, 'result' => []];
bc_tick(time() + 5);
$r = row('SELECT * FROM broadcasts WHERE id=?', [$fail]);
eq([$r['sent'], $r['failed'], $r['status']], [1, 1, 'done'], 'ошибка одной доставки не рушит рассылку');

// ======================================================================
section('Постбеки входящие/исходящие');
reset_db();
$b1 = mkbot('A'); $b2 = mkbot('B');
$gp = insert('groups', ['name' => 'Купили', 'color' => '#1', 'created_at' => now()]);
insert('in_rules', ['event' => 'purchase', 'action' => 'add', 'group_id' => $gp]);
insert('in_rules', ['event' => 'refund', 'action' => 'remove', 'group_id' => $gp]);
$u = user_touch(from(1), 'src__LBL', $b1);
eq(postback_apply('PURCHASE', $u, ['value' => '10']), ['+' . $gp], 'правило add (регистр не важен)');
ok((bool)val('SELECT 1 FROM user_groups WHERE user_id=?', [$u['id']]), 'пользователь в группе');
eq(postback_apply('refund', $u), ['-' . $gp], 'правило remove');
ok(!val('SELECT 1 FROM user_groups WHERE user_id=?', [$u['id']]), 'пользователь вышел из группы');
eq(postback_apply('unknown', $u), [], 'неизвестное событие без правила — только лог');
set_setting('auto_group_events', '1');
eq(count(postback_apply('lead', $u)), 1, 'авто-группа для события без правила');
ok((bool)val("SELECT 1 FROM groups WHERE name='Событие: lead'"), 'группа «Событие: lead» создана');

insert('out_hooks', ['name' => 'tr', 'url' => 'https://tracker.test/pb?c={label}&t={tg_id}&e={event}&g={group}&s={source}', 'method' => 'GET', 'event_type' => 'group_add', 'filter' => '', 'active' => 1]);
insert('out_hooks', ['name' => 'only-click', 'url' => 'https://tracker.test/click?c={label}', 'method' => 'POST', 'event_type' => 'click', 'filter' => 's5', 'active' => 1]);
insert('out_hooks', ['name' => 'off', 'url' => 'https://tracker.test/off', 'method' => 'GET', 'event_type' => 'group_add', 'filter' => '', 'active' => 0]);
q('DELETE FROM out_queue');
group_add((int)$u['id'], $gp);
$qd = rows('SELECT * FROM out_queue');
eq(count($qd), 1, 'исходящий постбек поставлен в очередь (неактивные и чужие — нет)');
eq($qd[0]['url'], 'https://tracker.test/pb?c=LBL&t=1&e=group_add&g=' . rawurlencode('Купили') . '&s=src', 'макросы подставлены и экранированы');
ev((int)$u['id'], 'click', 's5:0', 'btn'); ev((int)$u['id'], 'click', 's50:0', 'btn'); ev((int)$u['id'], 'click', 's6:0', 'btn');
eq((int)val("SELECT COUNT(*) FROM out_queue WHERE url LIKE '%/click%'"), 1, 'фильтр: s5 совпадает, s50 и s6 — нет');
eq(json_decode((string)val("SELECT body FROM out_queue WHERE method='POST'"), true)['label'], 'LBL', 'POST-тело JSON содержит метку пользователя');
q("UPDATE out_queue SET url = 'http://127.0.0.1:1/x'");
hooks_process();
eq(val("SELECT status FROM out_queue ORDER BY id LIMIT 1"), 'failed', 'SSRF: запрос на локальный адрес заблокирован');

// ======================================================================
section('Безопасность: чистота ввода в БД-слое');
reset_db();
$b1 = mkbot('A');
$u = user_touch(['id' => 5, 'first_name' => "Robert'); DROP TABLE users;--"], null, $b1);
eq((int)val('SELECT COUNT(*) FROM users'), 1, 'SQL-инъекция через имя не выполняется');
$bc = insert('broadcasts', ['name' => 'x', 'body' => 'x', 'inc_groups' => json_encode(['1); DROP TABLE users;--']), 'created_at' => now()]);
ok(bc_count(row('SELECT * FROM broadcasts WHERE id=?', [$bc])) >= 0, 'id групп в рассылке приводятся к int');


// ======================================================================
section('Роли и права (unit)');
reset_db();
$adm = seed_roles();
eq((int)val('SELECT COUNT(*) FROM admin_roles'), 4, 'созданы системная и 3 стартовые роли');
eq(seed_roles(), $adm, 'seed_roles идемпотентен');
$b1 = mkbot('A'); $b2 = mkbot('B'); $b3 = mkbot('C');
$mkAdmin = function (string $login, int $role, int $all, array $bots = []) {
    $id = insert('admin_users', ['login' => $login, 'name' => $login, 'pass_hash' => 'x', 'role_id' => $role, 'all_bots' => $all, 'active' => 1, 'created_at' => now()]);
    foreach ($bots as $b) q('INSERT INTO admin_user_bots VALUES (?,?)', [$id, $b]);
    return auth_load($id);
};
$roleMgr = (int)val("SELECT id FROM admin_roles WHERE name='Менеджер рассылок'");
$roleAn = (int)val("SELECT id FROM admin_roles WHERE name='Аналитик'");
$A = $mkAdmin('root', $adm, 0);               // админ: all_bots в БД = 0, но права всё равно полные
$M = $mkAdmin('mgr', $roleMgr, 0, [$b1, $b2]);
$N = $mkAdmin('an', $roleAn, 1);
ok(is_admin($A) && can('settings.anything', $A), 'админ: любое право');
ok(bot_scope($A) === null && unrestricted($A), 'админ: доступ ко всем ботам независимо от флага');
ok(can('broadcasts.send', $M) && !can('flows.edit', $M) && !can('postbacks.view', $M), 'менеджер: только свои права');
ok(!can('broadcasts.send', $N) && can('users.export', $N), 'аналитик: просмотр и экспорт, без отправки');
eq(bot_scope($M), [$b1, $b2], 'менеджер ограничен назначенными ботами');
ok(bot_scope($N) === null, 'all_bots=1 снимает ограничение');
ok(bot_ok($b1, $M) && !bot_ok($b3, $M) && !bot_ok(0, $M) && bot_ok(0, $N), 'bot_ok: чужой бот и «все боты» запрещены ограниченному');
eq(scope_sql('u.bot_id', false, $M), " AND u.bot_id IN ($b1,$b2)", 'scope_sql ограниченного');
eq(scope_sql('u.bot_id', true, $M), " AND u.bot_id IN ($b1,$b2,0)", 'scope_sql с общими записями');
eq(scope_sql('u.bot_id', false, $N), '', 'scope_sql без ограничения пуст');
$E = $mkAdmin('nobots', $roleMgr, 0, []);
eq(scope_sql('u.bot_id', false, $E), ' AND 0', 'пользователь без ботов не видит ничего');
eq(count(visible_bots($M)), 2, 'visible_bots учитывает назначение');
$unknown = json_encode(['users.view', 'hack.everything']);
q('UPDATE admin_roles SET perms = ? WHERE id = ?', [$unknown, $roleAn]);
ok(!can('hack.everything', auth_load((int)$N['id'])), 'неизвестные права из БД игнорируются');
eq(active_admin_count(), 1, 'один активный админ');

// ======================================================================
section('HTTP: вебхук, постбек, трекер, админка');
reset_db();
$port = random_int(20000, 40000); $mport = $port + 1;
$mockLog = $tmp . '/mock.log';
$env = ['TGBOT_CONFIG' => $cfgFile, 'TG_MOCK_LOG' => $mockLog, 'PATH' => getenv('PATH')];
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [1 => ['file', $tmp . '/srv.log', 'w'], 2 => ['file', $tmp . '/srv.log', 'a']], $p1, null, $env);
$mock = proc_open([PHP_BINARY, '-S', "127.0.0.1:$mport", __DIR__ . '/mock_tg.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2, null, $env);
foreach ([$port, $mport] as $pt) {   // ждём, пока серверы начнут отвечать
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $pt, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
}
$GLOBALS['TG_TRANSPORT_BACKUP'] = $GLOBALS['TG_TRANSPORT'];

set_setting('api_base', "http://127.0.0.1:$mport");
set_setting('base_url', "http://127.0.0.1:$port");
set_setting('postback_key', 'PKEY');
$adminRoleId = seed_roles();
$adminUid = insert('admin_users', ['login' => 'admin', 'name' => 'Boss', 'pass_hash' => password_hash('correct horse', PASSWORD_DEFAULT), 'role_id' => $adminRoleId, 'all_bots' => 1, 'active' => 1, 'created_at' => now()]);
$b1 = mkbot('Http'); $b2 = mkbot('Http2');
$secret = (string)val('SELECT secret FROM bots WHERE id=?', [$b1]);
$fl = mkflow(0, '', true, [0]);
$jar = $tmp . '/jar';
function wait_for(callable $f, float $sec = 4.0)
{
    $end = microtime(true) + $sec;
    do { $v = $f(); if ($v) return $v; usleep(100000); } while (microtime(true) < $end);
    return $v;
}
function http(string $method, string $path, array $o = []): array
{
    global $port, $jar;
    $ch = curl_init("http://127.0.0.1:$port$path");
    $h = $o['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $o['jar'] ?? $jar, CURLOPT_COOKIEFILE => $o['jar'] ?? $jar, CURLOPT_HTTPHEADER => $h]);
    if (isset($o['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']);
    $r = curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'head' => substr((string)$r, 0, $hs), 'body' => substr((string)$r, $hs)];
}
$upd = fn(int $id, int $tg, string $text) => json_encode(['update_id' => $id, 'message' => ['chat' => ['id' => $tg, 'type' => 'private'], 'from' => ['id' => $tg, 'first_name' => '<img src=x onerror=alert(1)>'], 'text' => $text]]);
$sec = fn(string $s) => ["X-Telegram-Bot-Api-Secret-Token: $s"];

eq(http('POST', "/webhook.php?b=$b1", ['body' => $upd(1, 900, '/start'), 'headers' => $sec('wrong')])['code'], 403, 'вебхук: неверный секрет → 403');
eq(http('POST', "/webhook.php?b=$b1", ['body' => $upd(1, 900, '/start')])['code'], 403, 'вебхук: без секрета → 403');
eq(http('POST', "/webhook.php?b=$b2", ['body' => $upd(1, 900, '/start'), 'headers' => $sec($secret)])['code'], 403, 'вебхук: секрет другого бота → 403');
eq(http('POST', '/webhook.php?b=9999', ['body' => '{}', 'headers' => $sec($secret)])['code'], 403, 'вебхук: неизвестный бот → 403');
eq(http('POST', "/webhook.php?b=$b1", ['body' => $upd(10, 900, '/start fb__abc'), 'headers' => $sec($secret)])['code'], 200, 'вебхук: валидный апдейт → 200');
$usr = wait_for(fn() => row('SELECT * FROM users WHERE tg_id = 900'));   // обработка идёт после быстрого ответа Telegram
wait_for(fn() => val("SELECT COUNT(*) FROM events WHERE type='step_sent'"));
eq([$usr['bot_id'] ?? null, $usr['source'] ?? null, $usr['label'] ?? null], [$b1, 'fb', 'abc'], 'пользователь создан с ботом, источником и меткой');
http('POST', "/webhook.php?b=$b1", ['body' => $upd(10, 900, '/start'), 'headers' => $sec($secret)]);
usleep(800000);
eq((int)val("SELECT COUNT(*) FROM events WHERE type='start' AND user_id=?", [$usr['id']]), 1, 'повтор того же update_id игнорируется');
$sent = array_filter(file($mockLog), fn($l) => str_contains($l, 'sendMessage'));
ok(count($sent) >= 1, 'приветственное сообщение ушло в (mock) Telegram');
http('POST', "/webhook.php?b=$b2", ['body' => $upd(1, 900, '/start'), 'headers' => $sec((string)val('SELECT secret FROM bots WHERE id=?', [$b2]))]);
wait_for(fn() => (int)val('SELECT COUNT(*) FROM users WHERE tg_id = 900') === 2);
eq((int)val('SELECT COUNT(*) FROM users WHERE tg_id = 900'), 2, 'тот же человек во втором боте — отдельный пользователь');

$pb = fn(string $q) => http('GET', "/postback.php?$q");
eq($pb('event=x&label=abc')['code'], 403, 'постбек: без ключа → 403');
eq($pb('key=bad&event=x&label=abc')['code'], 403, 'постбек: неверный ключ → 403');
eq($pb('key=PKEY&label=abc')['code'], 400, 'постбек: без event → 400');
eq($pb('key=PKEY&event=x&label=nobody')['code'], 404, 'постбек: пользователь не найден → 404');
$gp = insert('groups', ['name' => 'Paid', 'color' => '#1', 'created_at' => now()]);
insert('in_rules', ['event' => 'paid', 'action' => 'add', 'group_id' => $gp]);
$r = $pb('key=PKEY&event=paid&label=abc&value=5');
eq([$r['code'], json_decode($r['body'], true)['groups'] ?? null], [200, ["+$gp"]], 'постбек по метке добавляет в группу');
$r = http('POST', '/postback.php', ['body' => json_encode(['event' => 'paid', 'tg_id' => 900, 'bot' => $b2]), 'headers' => ['Content-Type: application/json', 'X-Postback-Key: PKEY']]);
eq($r['code'], 200, 'постбек JSON + ключ в заголовке, сужение по боту');
ok((bool)val('SELECT 1 FROM user_groups ug JOIN users u ON u.id=ug.user_id WHERE u.bot_id=?', [$b2]), 'группа добавлена пользователю именно второго бота');

$gp2 = (int)val('SELECT id FROM steps LIMIT 1');
q('UPDATE steps SET buttons = ?', [json_encode([[['text' => 'Go', 'type' => 'url', 'url' => 'https://example.com/landing', 'group' => $gp]]])]);
$sig = link_sig("s$gp2", 0, (int)$usr['id']);
$r = http('GET', "/go.php?m=s$gp2&i=0&u={$usr['id']}&s=$sig");
ok($r['code'] === 302 && str_contains($r['head'], 'Location: https://example.com/landing'), 'go.php: валидная подпись → 302 на ссылку кнопки');
eq((int)val("SELECT COUNT(*) FROM events WHERE type='click' AND user_id=?", [$usr['id']]), 1, 'go.php: клик записан');
eq(http('GET', "/go.php?m=s$gp2&i=0&u={$usr['id']}&s=deadbeef0000")['code'], 404, 'go.php: неверная подпись → 404');
eq(http('GET', "/go.php?m=s$gp2&i=0&u=" . ($usr['id'] + 1) . "&s=$sig")['code'], 404, 'go.php: подпись нельзя перенести на другого пользователя');
q('UPDATE steps SET buttons = ?', [json_encode([[['text' => 'Bad', 'type' => 'url', 'url' => 'javascript:alert(1)']]])]);
eq(http('GET', "/go.php?m=s$gp2&i=0&u={$usr['id']}&s=" . link_sig("s$gp2", 0, (int)$usr['id']))['code'], 404, 'go.php: небезопасная ссылка не редиректится');
eq(http('GET', '/cron.php?key=nope')['code'], 403, 'cron по HTTP без ключа → 403');
eq(http('GET', '/data/')['code'] >= 400 ? 1 : 0, 1, 'каталог data/ недоступен');

// --- админка
$r = http('GET', '/admin/index.php');
ok(str_contains($r['body'], 'name="password"'), 'админка: без входа показывается форма логина');
foreach (['X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Content-Security-Policy'] as $hd) ok(stripos($r['head'], $hd) !== false, "заголовок безопасности $hd");
ok(!str_contains(http('GET', '/admin/index.php?p=users')['body'], 'Подписчики ('), 'админка: страницы недоступны без входа');
$r = http('POST', '/admin/index.php', ['body' => 'login=admin&password=wrong']);
contains($r['body'], 'Неверный логин', 'неверный пароль отклонён');
for ($i = 0; $i < 5; $i++) http('POST', '/admin/index.php', ['body' => 'login=admin&password=wrong']);
$r = http('POST', '/admin/index.php', ['body' => 'login=admin&password=correct+horse']);
contains($r['body'], 'Слишком много попыток', 'блокировка перебора пароля (даже верный пароль не пускает)');
q('DELETE FROM login_fail');
$r = http('POST', '/admin/index.php', ['body' => 'login=admin&password=correct+horse']);
ok($r['code'] === 302, 'верный пароль → вход');
$page = http('GET', '/admin/index.php?p=dashboard')['body'];
preg_match('/name="csrf" content="([^"]+)"/', $page, $m); $csrf = $m[1] ?? '';
ok($csrf !== '', 'CSRF-токен выдан');
eq(http('POST', '/admin/index.php?p=groups', ['body' => 'do=add&name=Hack'])['code'], 400, 'POST без CSRF → 400');
eq(http('POST', '/admin/index.php?p=groups', ['body' => 'do=add&name=Hack&csrf=wrong'])['code'], 400, 'POST с неверным CSRF → 400');
ok(!val("SELECT 1 FROM groups WHERE name='Hack'"), 'группа без CSRF не создана');
http('POST', '/admin/index.php?p=groups', ['body' => "do=add&name=Ok&csrf=$csrf"]);
ok((bool)val("SELECT 1 FROM groups WHERE name='Ok'"), 'POST с CSRF проходит');

foreach (['dashboard', 'dashboard&bot=' . $b1, 'users', 'users&bot=' . $b2, 'user&id=' . $usr['id'], 'groups', 'flows', 'flow&id=' . $fl, 'broadcasts', 'links', 'links&source=a&label=b', 'postbacks', 'bots', 'settings'] as $pg) {
    $r = http('GET', "/admin/index.php?p=$pg");
    ok($r['code'] === 200 && !preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error)\b/', $r['body']), "страница p=$pg: 200 без PHP-ошибок");
}
$dash = http('GET', '/admin/index.php?p=dashboard')['body'];
contains($dash, 'Новых за 30 дн.', 'дашборд: период по умолчанию 30 дней (регрессия: был 0)');
contains(http('GET', '/admin/index.php?p=dashboard&days=7')['body'], 'Новых за 7 дн.', 'дашборд: выбор периода 7 дней');
contains(http('GET', '/admin/index.php?p=dashboard&days=5')['body'], 'Новых за 30 дн.', 'дашборд: неверный период → 30');
$users = http('GET', '/admin/index.php?p=users')['body'];
ok(!str_contains($users, '<img src=x onerror') && str_contains($users, '&lt;img src=x onerror'), 'XSS: имя пользователя экранировано в списке');
$bcId = insert('broadcasts', ['bot_id' => $b1, 'name' => '<b>n</b>', 'body' => 'b', 'created_at' => now()]);
ok(!str_contains(http('GET', '/admin/index.php?p=broadcasts')['body'], '<b>n</b>'), 'XSS: название рассылки экранировано');
$r = http('POST', '/admin/index.php?p=preview', ['body' => "csrf=$csrf&body=" . rawurlencode('<script>alert(1)</script> **b**')]);
ok(!str_contains($r['body'], '<script>'), 'превью экранирует HTML');
user_touch(['id' => 901, 'first_name' => '=HYPERLINK("http://evil")'], null, $b1);
$csv = http('GET', '/admin/index.php?p=users&export=1')['body'];
ok(str_contains($csv, "\"'=HYPERLINK") && !preg_match('/,=HYPERLINK/', $csv), 'CSV-экспорт защищён от формул-инъекций');
$r = http('POST', '/admin/index.php?p=bots', ['body' => "csrf=$csrf&do=add&token=" . rawurlencode('777:' . str_repeat('a', 35)) . '&name=Third']);
eq((int)val("SELECT COUNT(*) FROM bots WHERE name='Third'"), 1, 'админка: добавление бота по токену (getMe через mock)');
eq(val("SELECT username FROM bots WHERE name='Third'"), 'mockbot', 'username бота получен из getMe');
$before = (int)val('SELECT COUNT(*) FROM bots');
http('POST', '/admin/index.php?p=bots', ['body' => "csrf=$csrf&do=add&token=" . rawurlencode('777:' . str_repeat('a', 35))]);
eq((int)val('SELECT COUNT(*) FROM bots'), $before, 'дубликат токена не добавляется');
http('POST', '/admin/index.php?p=bots', ['body' => "csrf=$csrf&do=delete&id=$b1"]);
ok((bool)val('SELECT 1 FROM bots WHERE id=?', [$b1]), 'бот с пользователями не удаляется');
$r = http('POST', '/admin/index.php?p=logout', ['body' => "csrf=$csrf"]);
ok($r['code'] === 302, 'выход');
ok(str_contains(http('GET', '/admin/index.php?p=users')['body'], 'name="password"'), 'после выхода доступ закрыт');

require __DIR__ . '/rbac_http.inc';
require __DIR__ . '/ux_http.inc';

proc_terminate($srv); proc_terminate($mock);

// ======================================================================
section('Миграция со старой однобот. схемы');
$tmp2 = $tmp . '/legacy'; mkdir($tmp2);
$legacy = new PDO('sqlite:' . $tmp2 . '/old.sqlite');
$legacy->exec("CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT); INSERT INTO settings VALUES ('bot_token','5:OLDTOKEN'),('bot_username','oldbot'),('admin_login','olduser'),('admin_hash','HASH');
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, tg_id INTEGER UNIQUE NOT NULL, username TEXT, first_name TEXT, last_name TEXT, lang TEXT, source TEXT, label TEXT, start_param TEXT, status TEXT NOT NULL DEFAULT 'active', created_at INTEGER NOT NULL, last_seen INTEGER, blocked_at INTEGER);
INSERT INTO users (tg_id, first_name, created_at) VALUES (42, 'Old', 1);
CREATE TABLE flows (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, source TEXT NOT NULL DEFAULT '', is_default INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, group_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL);
INSERT INTO flows (name, created_at) VALUES ('old flow', 1);");
$code = '<?php putenv("TGBOT_CONFIG=' . $tmp2 . '/c.php"); file_put_contents("' . $tmp2 . '/c.php", "<?php return [\'db\'=>\'' . $tmp2 . '/old.sqlite\'];"); require "' . __DIR__ . '/../lib/bootstrap.php";
$b = row("SELECT * FROM bots"); $u = row("SELECT * FROM users"); $f = row("SELECT * FROM flows");
$a = row("SELECT a.login, r.is_admin, a.all_bots FROM admin_users a JOIN admin_roles r ON r.id = a.role_id"); echo json_encode([$b["token"] ?? null, $u["bot_id"] == ($b["id"] ?? -1), $u["tg_id"], $f["bot_id"] == ($b["id"] ?? -1), $a]);';
file_put_contents($tmp2 . '/m.php', $code);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp2 . '/m.php') . ' 2>&1');
eq(json_decode((string)$out, true), ['5:OLDTOKEN', true, 42, true, ['login' => 'olduser', 'is_admin' => 1, 'all_bots' => 1]], 'старая установка: токен → бот, пользователи/цепочки привязаны к нему, единственный админ → пользователь с ролью «Администратор»');

// ----------------------------------------------------------------------
echo "\n" . str_repeat('=', 50) . "\nПройдено: {$T['pass']}, провалено: {$T['fail']}\n";
shell_exec('rm -rf ' . escapeshellarg($tmp));
exit($T['fail'] ? 1 : 0);
