<?php
// Демо-данные для скриншотов/ручной проверки. Запуск: TGBOT_CONFIG=/tmp/x/config.php php tests/seed_demo.php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
migrate();
mt_srand(7);
set_setting('admin_login', 'admin'); set_setting('admin_hash', password_hash('demo-pass-123', PASSWORD_DEFAULT));
set_setting('secret', 'demo'); set_setting('postback_key', 'demo-postback-key'); set_setting('cron_key', 'demo-cron-key');
set_setting('tz', 'Europe/Moscow'); set_setting('base_url', 'https://bots.example.com/tgbot'); set_setting('last_tick', (string)now());
set_setting('admin_tg_id', '100500');

$bots = [];
foreach ([['Магазин одежды', 'shop_demo_bot'], ['Курсы английского', 'english_demo_bot'], ['Новости канала', 'news_demo_bot']] as $i => [$n, $u])
    $bots[] = insert('bots', ['name' => $n, 'token' => ($i + 1) . ':DEMO' . str_repeat('x', 32), 'username' => $u, 'secret' => rand_token(8), 'active' => $i < 2 ? 1 : 0, 'created_at' => now() - 40 * 86400]);

$g = [];
foreach (['Новые' => '#3b82f6', 'Прогрев' => '#f59e0b', 'Кликнули оффер' => '#8b5cf6', 'Купили' => '#16a34a', 'VIP' => '#dc2626', 'Не купили' => '#6b7280'] as $n => $c)
    $g[$n] = insert('groups', ['name' => $n, 'color' => $c, 'created_at' => now() - 30 * 86400]);

$btn = fn($rows) => json_encode($rows, JSON_UNESCAPED_UNICODE);
$flows = [];
$mk = function (int $bot, string $name, string $src, int $def, array $steps, int $grp = 0) use (&$flows) {
    $f = insert('flows', ['bot_id' => $bot, 'name' => $name, 'source' => $src, 'is_default' => $def, 'active' => 1, 'group_id' => $grp, 'created_at' => now() - 30 * 86400]);
    foreach ($steps as $i => $s) insert('steps', ['flow_id' => $f, 'position' => $i, 'delay' => $s[0], 'body' => $s[1], 'image' => $s[2] ?? '', 'buttons' => $s[3] ?? '[]', 'group_id' => $s[4] ?? 0]);
    return $flows[$name] = $f;
};
$mk($bots[0], 'Приветствие магазина', '', 1, [
    [0, "Привет, **{first_name}**! 👋\nДобро пожаловать в наш магазин.\n\n> Скидка **10%** на первый заказ — только сегодня!", '',
        $btn([[['text' => '🛍 В каталог', 'type' => 'url', 'url' => 'https://shop.example.com/catalog', 'track' => true, 'group' => $g['Кликнули оффер']], ['text' => 'Хочу промокод', 'type' => 'cb', 'reply' => 'Ваш промокод: `WELCOME10` ✅', 'group' => $g['Прогрев']]]]), $g['Новые']],
    [3600, "Как дела, {first_name}? Подобрали для вас [новинки недели](https://shop.example.com/new). ||Спойлер: скидка до 30%||", '', $btn([[['text' => 'Смотреть новинки', 'type' => 'url', 'url' => 'https://shop.example.com/new', 'track' => true]]])],
    [86400, "Напоминаем: промокод **WELCOME10** сгорает завтра ⏰", '', '[]', $g['Прогрев']],
], $g['Новые']);
$mk($bots[0], 'Реклама Facebook', 'fb_ads', 0, [[0, "Привет из рекламы! Ваш бонус внутри 🎁", '', $btn([[['text' => 'Забрать бонус', 'type' => 'cb', 'reply' => 'Бонус начислен!']]])], [7200, 'Не забудьте про бонус!']]);
$mk(0, 'Общее приветствие', '', 1, [[0, 'Привет, {first_name}! Это общая цепочка для всех ботов.'], [1800, 'Вторая часть общей цепочки.']]);
$mk($bots[1], 'English: старт', '', 1, [
    [0, "Hello, **{first_name}**! 🇬🇧\nГотовы начать учить английский?", '', $btn([[['text' => 'Пройти тест уровня', 'type' => 'url', 'url' => 'https://english.example.com/test', 'track' => true], ['text' => 'Позже', 'type' => 'cb', 'reply' => 'Хорошо, напомним позже']]])],
    [43200, 'Урок 1: _Present Simple_ — смотрите по ссылке.'], [172800, 'Урок 2: Past Simple. Не пропускайте!', '', '[]', $g['Прогрев']]]);

$srcs = [['fb_ads', 40], ['tiktok', 25], ['blogger_ivan', 15], ['site', 12], [null, 8]];
$pickSrc = function () use ($srcs) { $r = mt_rand(1, 100); foreach ($srcs as [$s, $w]) { if (($r -= $w) <= 0) return $s; } return null; };
$names = ['Анна', 'Иван', 'Мария', 'Пётр', 'Елена', 'Дмитрий', 'Ольга', 'Сергей', 'Наталья', 'Алексей', 'Юлия', 'Андрей'];
$users = [];
for ($i = 0; $i < 160; $i++) {
    $bot = $bots[mt_rand(0, 9) < 6 ? 0 : (mt_rand(0, 9) < 7 ? 1 : 2)];
    $src = $pickSrc();
    $created = now() - mt_rand(0, 29 * 86400) - mt_rand(0, 80000);
    $uid = insert('users', ['bot_id' => $bot, 'tg_id' => 1000 + $i, 'username' => mt_rand(0, 2) ? 'user' . $i : null, 'first_name' => $names[array_rand($names)], 'last_name' => mt_rand(0, 1) ? 'Иванов' : null,
        'source' => $src, 'label' => $src ? 'clk' . mt_rand(10000, 99999) : null, 'start_param' => $src, 'status' => mt_rand(0, 9) === 0 ? 'blocked' : 'active', 'created_at' => $created, 'last_seen' => $created + mt_rand(0, 7 * 86400)]);
    $users[] = [$uid, $bot, $created, $src];
    $ev = fn(string $t, string $ref = '', string $label = '', int $dt = 0) => insert('events', ['user_id' => $uid, 'type' => $t, 'ref' => $ref, 'label' => $label, 'created_at' => min(now(), $created + $dt)]);
    $ev('start', (string)$src);
    $fl = $bot === $bots[0] ? ($src === 'fb_ads' ? $flows['Реклама Facebook'] : $flows['Приветствие магазина']) : ($bot === $bots[1] ? $flows['English: старт'] : $flows['Общее приветствие']);
    $steps = rows('SELECT * FROM steps WHERE flow_id = ? ORDER BY position', [$fl]);
    $reach = mt_rand(1, count($steps));
    insert('user_flows', ['user_id' => $uid, 'flow_id' => $fl, 'last_pos' => $reach - 1, 'next_at' => $reach < count($steps) ? now() + mt_rand(60, 80000) : null, 'status' => $reach < count($steps) ? 'active' : 'done', 'started_at' => $created]);
    foreach (array_slice($steps, 0, $reach) as $k => $s) {
        $ev('step_sent', 's' . $s['id'], '', $k * 3600);
        foreach (flat_buttons($s) as $bi => $b) if (mt_rand(0, 100) < 30 - $k * 8) $ev('click', 's' . $s['id'] . ':' . $bi, $b['text'], $k * 3600 + 60);
    }
    if (mt_rand(0, 100) < 25) { insert('user_groups', ['user_id' => $uid, 'group_id' => $g['Новые'], 'via' => 'flow', 'created_at' => $created]); }
    if (mt_rand(0, 100) < 20) insert('user_groups', ['user_id' => $uid, 'group_id' => $g['Прогрев'], 'via' => 'step', 'created_at' => $created]);
    if (mt_rand(0, 100) < 15) insert('user_groups', ['user_id' => $uid, 'group_id' => $g['Кликнули оффер'], 'via' => 'button', 'created_at' => $created]);
    if ($src && mt_rand(0, 100) < 10) {
        $ev('postback', 'purchase', 'purchase', 86400); insert('user_groups', ['user_id' => $uid, 'group_id' => $g['Купили'], 'via' => 'postback', 'created_at' => $created]);
        if (mt_rand(0, 3) === 0) insert('user_groups', ['user_id' => $uid, 'group_id' => $g['VIP'], 'via' => 'manual', 'created_at' => $created]);
    }
    if (mt_rand(0, 100) < 20) $ev('message', '', 'Сколько стоит доставка?', 5000);
}

insert('in_rules', ['event' => 'purchase', 'action' => 'add', 'group_id' => $g['Купили']]);
insert('in_rules', ['event' => 'refund', 'action' => 'remove', 'group_id' => $g['Купили']]);
insert('in_rules', ['event' => 'subscription_paid', 'action' => 'add', 'group_id' => $g['VIP']]);
$h1 = insert('out_hooks', ['name' => 'Трекер: старт', 'url' => 'https://tracker.example.com/postback?cid={label}&event=lead&tg={tg_id}', 'method' => 'GET', 'event_type' => 'start', 'filter' => '', 'active' => 1]);
insert('out_hooks', ['name' => 'CRM: клик по офферу', 'url' => 'https://crm.example.com/api/events', 'method' => 'POST', 'event_type' => 'group_add', 'filter' => (string)$g['Кликнули оффер'], 'active' => 1]);
foreach ([['sent', 200], ['sent', 200], ['failed', 500], ['pending', 502]] as $i => [$st, $code])
    insert('out_queue', ['hook_id' => $h1, 'user_id' => $users[$i][0], 'url' => 'https://tracker.example.com/postback?cid=clk' . (12345 + $i) . '&event=lead&tg=' . (1000 + $i), 'method' => 'GET', 'status' => $st, 'attempts' => 1, 'next_at' => now(), 'code' => $code, 'created_at' => now() - $i * 600]);

$bc = fn(array $o) => insert('broadcasts', $o + ['created_at' => now() - 5 * 86400]);
$b1 = $bc(['bot_id' => $bots[0], 'name' => 'Распродажа выходного дня', 'body' => "**Распродажа!** 🔥\nТолько до воскресенья скидки до 50%.\n\n[Перейти в каталог](https://shop.example.com/sale)", 'buttons' => $btn([[['text' => 'К скидкам', 'type' => 'url', 'url' => 'https://shop.example.com/sale', 'track' => true], ['text' => 'Напомнить позже', 'type' => 'cb', 'reply' => 'Напомним!']]]),
    'inc_groups' => json_encode([$g['Прогрев']]), 'exc_groups' => json_encode([$g['Купили']]), 'status' => 'done', 'send_at' => now() - 3 * 86400, 'started_at' => now() - 3 * 86400, 'finished_at' => now() - 3 * 86400 + 90, 'total' => 41, 'sent' => 39, 'failed' => 2]);
$bc(['bot_id' => 0, 'name' => 'Новогодняя рассылка (все боты)', 'body' => 'С наступающим, {first_name}! 🎄', 'status' => 'scheduled', 'send_at' => now() + 2 * 86400 + 3600]);
$bc(['bot_id' => $bots[1], 'name' => 'Урок недели (черновик)', 'body' => "Новый урок: **Present Perfect**\n- что это\n- когда использовать", 'status' => 'draft', 'inc_groups' => json_encode([$g['Прогрев']])]);
foreach ($users as $k => [$uid]) if ($k % 4 === 0) { $r = insert('events', ['user_id' => $uid, 'type' => 'bc_sent', 'ref' => "b$b1", 'label' => '', 'created_at' => now() - 3 * 86400]); if ($k % 8 === 0) insert('events', ['user_id' => $uid, 'type' => 'click', 'ref' => "b$b1:0", 'label' => 'К скидкам', 'created_at' => now() - 3 * 86400 + 300]); }
echo "seeded\n";
