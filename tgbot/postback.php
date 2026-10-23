<?php
// Входящий постбек от внешних систем.
// GET/POST: ?key=SECRET&event=purchase&label=CLICKID  (или tg_id=..., или uid=...)  [&value=...]
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$in = $_REQUEST;
if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
    $j = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($j)) $in = $j + $in;
}
$out = function (int $code, array $d) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };

if (!is_installed()) $out(503, ['ok' => false, 'error' => 'not installed']);
if (!hash_equals((string)setting('postback_key'), (string)($in['key'] ?? ''))) $out(403, ['ok' => false, 'error' => 'bad key']);
$event = trim((string)($in['event'] ?? ''));
if ($event === '') $out(400, ['ok' => false, 'error' => 'event is required']);

$u = null;
if (!empty($in['tg_id'])) $u = row('SELECT * FROM users WHERE tg_id = ?', [(int)$in['tg_id']]);
elseif (!empty($in['uid'])) $u = row('SELECT * FROM users WHERE id = ?', [(int)$in['uid']]);
elseif (!empty($in['label'])) $u = row('SELECT * FROM users WHERE label = ? ORDER BY id DESC LIMIT 1', [(string)$in['label']]);
if (!$u) $out(404, ['ok' => false, 'error' => 'user not found']);

$data = array_filter(['value' => isset($in['value']) ? (string)$in['value'] : null], fn($v) => $v !== null);
$applied = postback_apply(mb_substr($event, 0, 100), $u, $data);
$out(200, ['ok' => true, 'user_id' => (int)$u['id'], 'groups' => $applied]);
