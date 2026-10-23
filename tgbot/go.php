<?php
// Трекинг кликов по URL-кнопкам: логирует клик и перенаправляет на ссылку.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$key = (string)($_GET['m'] ?? '');
$idx = (int)($_GET['i'] ?? -1);
$tg = (int)($_GET['u'] ?? 0);
$sig = (string)($_GET['s'] ?? '');
if (!is_installed() || !preg_match('/^[sb]\d+$/', $key) || !hash_equals(link_sig($key, $idx, $tg), $sig)) {
    http_response_code(404);
    exit('Not found');
}
$m = msg_by_key($key);
$btn = $m ? (flat_buttons($m)[$idx] ?? null) : null;
$url = $btn ? trim((string)($btn['url'] ?? '')) : '';
if ($url === '') { http_response_code(404); exit('Not found'); }
$u = row('SELECT * FROM users WHERE tg_id = ?', [$tg]);
if ($u) {
    ev((int)$u['id'], 'click', "$key:$idx", (string)($btn['text'] ?? ''), ['url' => 1]);
    if (!empty($btn['group'])) group_add((int)$u['id'], (int)$btn['group'], 'button');
}
header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
