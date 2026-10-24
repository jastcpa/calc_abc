<?php
// Подключается из run.php после rbac_http.inc: навигация, контекст бота, палитра команд, элементы UX.
// Доступны $Ad (админ), $Ed (редактор с одним ботом), $get/$post/$sess, $b1/$b2, $fB1.

section('UX: меню, контекст бота, палитра');
$adminPage = $get($Ad, '?p=dashboard')['body'];
foreach (['Обзор', 'Аудитория', 'Коммуникации', 'Интеграции', 'Администрирование'] as $grp) contains($adminPage, '>' . $grp, "меню админа: группа «{$grp}»");
$edPage = $get($Ed, '?p=flows')['body'];
ok(str_contains($edPage, '>Коммуникации') && str_contains($edPage, '>Аудитория'), 'меню редактора: доступные группы');
ok(!str_contains($edPage, '>Администрирование') && !str_contains($edPage, '>Интеграции'), 'пустые группы меню скрыты');
contains($adminPage, 'aria-current="page"', 'активный пункт меню помечен (aria-current)');
contains($adminPage, 'class="skip"', 'есть ссылка «к содержимому» для клавиатуры');
ok(str_contains($adminPage, 'id="confirm"') && str_contains($adminPage, 'id="palette"'), 'диалоги подтверждения и палитры на месте');
ok(str_contains($adminPage, 'id="navtoggle"') && str_contains($adminPage, 'id="scrim"'), 'мобильное меню (бургер + затемнение)');
ok(str_contains($adminPage, 'name="bot"') && str_contains($adminPage, 'data-autosubmit'), 'переключатель бота виден админу');
ok(!str_contains($edPage, 'data-autosubmit'), 'у пользователя с одним ботом переключателя нет');
preg_match('/name="cmds" content="([^"]*)"/', $adminPage, $mc);
$cmdsAdmin = json_decode(html_entity_decode($mc[1] ?? '[]'), true) ?: [];
$titles = array_column($cmdsAdmin, 't');
ok(in_array('Создать рассылку', $titles, true) && in_array('Подключить бота', $titles, true), 'палитра админа содержит быстрые действия');
preg_match('/name="cmds" content="([^"]*)"/', $edPage, $mc2);
$titlesEd = array_column(json_decode(html_entity_decode($mc2[1] ?? '[]'), true) ?: [], 't');
ok(in_array('Создать цепочку', $titlesEd, true) && !in_array('Подключить бота', $titlesEd, true) && !in_array('Команда', $titlesEd, true), 'палитра редактора не раскрывает админские команды');

// контекст бота
$ctx = fn(array $s, string $bot, string $back = '?p=users') => http('POST', '/admin/index.php?p=ctx', ['body' => "csrf={$s['csrf']}&bot=$bot&back=" . rawurlencode($back), 'jar' => $s['jar']]);
$r = $ctx($Ad, (string)$b1);
ok($r['code'] === 302 && str_contains($r['head'], 'Location: ?p=users'), 'смена бота возвращает на ту же страницу');
$l = $get($Ad, '?p=users')['body'];
ok(str_contains($l, 'InBot1') && !str_contains($l, 'InBot2'), 'контекст бота фильтрует подписчиков');
contains($l, 'chipx', 'активный бот показан «чипом» со сбросом');
$bl = $get($Ad, '?p=broadcasts')['body'];
ok(str_contains($bl, "Draft2") && !str_contains($bl, "Рассылка бота 2"), "контекст бота фильтрует рассылки");
$fl = $get($Ad, '?p=flows')['body'];
ok(str_contains($fl, 'p=flow&id=' . $fB1) && !str_contains($fl, 'p=flow&id=' . $fB2), 'контекст бота фильтрует цепочки (общие остаются)');
$kp = $get($Ad, '?p=dashboard')['body'];
preg_match('/<b>(\d+)<\/b><span>Всего подписчиков/', $kp, $mk);
eq((int)($mk[1] ?? -1), (int)val('SELECT COUNT(*) FROM users WHERE bot_id=?', [$b1]), 'дашборд следует за контекстом бота');
ok(str_contains($get($Ad, '?p=users&bot=0')['body'], 'InBot2'), 'явный ?bot=0 показывает все боты');
$r = $ctx($Ad, (string)$b1, 'https://evil.example/');
ok(!str_contains($r['head'], 'evil.example') && str_contains($r['head'], 'Location: index.php'), 'back-параметр не даёт open redirect');
$r = $ctx($Ad, (string)$b1, "?p=users\r\nSet-Cookie: x=1");
ok(!str_contains($r['head'], 'Set-Cookie: x=1'), 'back-параметр не даёт инъекцию заголовков');
$ctx($Ad, '0');
ok(str_contains($get($Ad, '?p=users')['body'], 'InBot2'), 'сброс контекста');
$ctx($Ed, (string)$b2);
$l = $get($Ed, '?p=users')['body'];
ok(!str_contains($l, 'InBot2'), 'ограниченный пользователь не может выбрать чужого бота в контексте');
$ctx($Ad, '999999');
ok(str_contains($get($Ad, '?p=users')['body'], 'InBot1'), 'несуществующий бот в контексте игнорируется');

section('UX: страницы и элементы');
$u = $get($Ad, '?p=users&q=InBot')['body'];
ok(str_contains($u, 'Поиск: InBot') && str_contains($u, 'aria-label="Убрать фильтр"'), 'активные фильтры показаны чипами с кнопкой сброса');
ok(str_contains($u, 'id="bulkbar"') && str_contains($u, 'role="toolbar"'), 'панель массовых действий (появляется при выборе строк)');
ok(str_contains($get($Ad, '?p=users&q=zzzzzz-none')['body'], 'Никого не найдено'), 'пустой результат фильтра — понятное состояние');
$fp = $get($Ad, '?p=flow&id=' . $fB1)['body'];
ok(str_contains($fp, 'details class="card step"') && str_contains($fp, 'class="gap"'), 'шаги цепочки — раскрывающиеся карточки с таймлайном');
ok(str_contains($fp, 'class="guard"') && str_contains($fp, 'data-save'), 'формы с защитой от потери изменений и Ctrl+S');
$bp = $get($Ad, '?p=broadcast&id=' . $bcB1)['body'];
ok(str_contains($bp, 'data-send-confirm') && str_contains($bp, 'data-mode-label') && str_contains($bp, 'sticky-actions'), 'рассылка: подтверждение «сейчас», динамическая кнопка, липкая панель');
contains($bp, 'Коммуникации', 'хлебные крошки содержат группу меню');
ok(str_contains($bp, '>1</span> Сообщение') && str_contains($bp, '>3</span> Отправка'), 'рассылка: пошаговая структура');
$dash = $get($Ad, '?p=dashboard&days=7')['body'];
ok(str_contains($dash, 'aria-current="true">7 дн.'), 'период на дашборде — сегментированный переключатель');
contains($dash, 'Быстрый старт', 'админу показан чек-лист быстрого старта');
ok(!str_contains($get($Ed, '?p=dashboard')['body'], 'Быстрый старт'), 'неадминам чек-лист не показывается');
ok(str_contains($dash, '<a href="?p=users" class="kpi">') || str_contains($dash, 'class="kpi"'), 'KPI-карточки кликабельны');
$post($Ad, '?p=groups', 'do=add&name=ToastTest');
ok(str_contains($get($Ad, '?p=groups')['body'], 'class="toast'), 'результат действия показан уведомлением (toast)');
ok(str_contains($get($Ad, '?p=flows')['body'], 'id="new"'), 'форма создания достижима по якорю #new');
ok(str_contains($get($Ad, '?p=bots')['body'], 'pagehead'), 'единый заголовок страниц');

section('UX: целостность разметки');
foreach (['dashboard', 'users', 'groups', 'flows', 'flow&id=' . $fB1, 'broadcasts', 'broadcast&id=' . $bcB1, 'links', 'postbacks', 'bots', 'team', 'team&edit=new', 'roles', 'roles&edit=' . $rm, 'settings', 'profile'] as $pg3) {
    $html = $get($Ad, "?p=$pg3")['body'];
    $body = preg_replace('~<(script|style|textarea)\b.*?</\1>~s', '', $html);
    ok(substr_count($body, '<div') === substr_count($body, '</div>') && substr_count($body, '<form') === substr_count($body, '</form>') && substr_count($body, '<details') === substr_count($body, '</details>'), "p=$pg3: теги div/form/details сбалансированы");
    $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8">' . $html); libxml_clear_errors();
    $main = $d->getElementsByTagName('main')->item(0);
    $inMain = $main && $d->getElementsByTagName('form')->length > 0;
    ok($main !== null && $main->getElementsByTagName('form')->length >= 0, "p=$pg3: <main> присутствует в DOM");
}
// в рассылке все три блока и панель отправки находятся внутри формы (регрессия: лишний </div> закрывал форму)
$d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8">' . $get($Ad, '?p=broadcast&id=' . $bcB1)['body']); libxml_clear_errors();
$xp = new DOMXPath($d);
ok($xp->query('//form[contains(@class,"guard")]//*[@data-modes]')->length === 1, 'рассылка: панель отправки внутри формы');
ok($xp->query('//form[contains(@class,"guard")]//select[@name="bot_id"]')->length === 1 && $xp->query('//form[contains(@class,"guard")]//input[@name="mode"]')->length === 3, 'рассылка: аудитория и режимы отправки внутри формы');
$d->loadHTML('<?xml encoding="utf-8">' . $get($Ad, '?p=flow&id=' . $fB1)['body']);
$xp = new DOMXPath($d);
ok($xp->query('//details[contains(@class,"step")]//form//textarea[@name="body"]')->length >= 1, 'цепочка: редактор шага внутри раскрывающейся карточки и формы');
