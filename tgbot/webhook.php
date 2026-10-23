<?php
// Точка входа для Telegram (setWebhook). Всегда отвечает 200.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (!is_installed()) { http_response_code(503); exit('not installed'); }
$secret = (string)setting('webhook_secret');
if ($secret !== '' && !hash_equals($secret, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(403);
    exit('forbidden');
}
$update = json_decode((string)file_get_contents('php://input'), true);

// быстрый ответ Telegram, дальше обрабатываем в фоне
ignore_user_abort(true);
header('Content-Type: text/plain');
header('Content-Length: 2');
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); flush(); }

if (!is_array($update)) exit;

try {
    if (isset($update['callback_query'])) {
        handle_callback($update['callback_query']);
    } elseif (isset($update['my_chat_member'])) {
        $m = $update['my_chat_member'];
        if (($m['chat']['type'] ?? '') === 'private') {
            $u = row('SELECT id FROM users WHERE tg_id = ?', [(int)$m['chat']['id']]);
            $st = $m['new_chat_member']['status'] ?? '';
            if ($u && $st === 'kicked') user_blocked((int)$u['id']);
            elseif ($u && $st === 'member') {
                q("UPDATE users SET status='active', blocked_at=NULL WHERE id=?", [$u['id']]);
                ev((int)$u['id'], 'unblocked');
            }
        }
    } elseif (isset($update['message']) && ($update['message']['chat']['type'] ?? '') === 'private' && isset($update['message']['from'])) {
        $msg = $update['message'];
        $text = trim((string)($msg['text'] ?? ''));
        if (preg_match('~^/start(?:@\w+)?(?:\s+(\S+))?$~u', $text, $mm)) {
            $u = user_touch($msg['from'], $mm[1] ?? null);
            ev((int)$u['id'], 'start', (string)($mm[1] ?? ''), (string)($u['source'] ?? ''));
            flow_enroll($u, !$u['_new'] && setting('restart_on_start', '0') === '1');
            flow_tick(time() + 20, (int)$u['id']);
        } else {
            $u = user_touch($msg['from']);
            ev((int)$u['id'], 'message', '', mb_substr($text, 0, 100));
            $reply = trim((string)setting('default_reply', ''));
            if ($reply !== '') send_to_user($u, ['body' => $reply, 'image' => '', 'buttons' => '[]'], '');
        }
    }
    // псевдо-крон: если cron не настроен, отправка по расписанию идёт при активности бота
    if (now() - (int)setting('last_tick', '0') > 60) run_tick(15);
    else hooks_process(5);
} catch (Throwable $e) {
    log_error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
