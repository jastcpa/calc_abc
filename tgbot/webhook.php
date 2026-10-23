<?php
// Точка входа для Telegram: webhook.php?b=<ID бота>. Всегда отвечает 200 на валидный запрос.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (!is_installed()) { http_response_code(503); exit('not installed'); }
$bot = row('SELECT * FROM bots WHERE id = ?', [(int)($_GET['b'] ?? 0)]);
$given = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!$bot || $bot['secret'] === '' || !hash_equals($bot['secret'], $given)) {
    http_response_code(403);
    exit('forbidden');
}
$botId = (int)$bot['id'];
$update = json_decode((string)file_get_contents('php://input'), true);

// быстрый ответ Telegram, дальше обрабатываем в фоне
ignore_user_abort(true);
header('Content-Type: text/plain');
header('Content-Length: 2');
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); flush(); }

if (!is_array($update) || !$bot['active']) exit;

try {
    // защита от повторной доставки одного и того же апдейта
    $uid = (int)($update['update_id'] ?? 0);
    if ($uid > 0) {
        if (q('UPDATE bots SET last_update = ? WHERE id = ? AND last_update < ?', [$uid, $botId, $uid])->rowCount() === 0) exit;
    }
    handle_update($update, $botId);
    // псевдо-крон: если cron не настроен, отправка по расписанию идёт при активности ботов
    if (now() - (int)setting('last_tick', '0') > 60) run_tick(15);
    else hooks_process(5);
} catch (Throwable $e) {
    log_error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
