<?php
// Telegram Bot API + конвертер markdown -> Telegram HTML.
declare(strict_types=1);

function tg_api_base(): string
{
    return rtrim((string)setting('api_base', 'https://api.telegram.org'), '/');
}

/** Вызов метода Bot API. Возвращает декодированный ответ ['ok'=>bool, ...]. */
function tg(string $method, array $params = [], int $timeout = 20): array
{
    $token = (string)setting('bot_token', '');
    if ($token === '') return ['ok' => false, 'error_code' => 0, 'description' => 'Не задан токен бота'];
    $url = tg_api_base() . '/bot' . $token . '/' . $method;
    foreach ($params as $k => $v) if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'error_code' => 0, 'description' => 'cURL: ' . $err];
    $j = json_decode($res, true);
    return is_array($j) ? $j : ['ok' => false, 'error_code' => 0, 'description' => 'Некорректный ответ: ' . substr($res, 0, 200)];
}

function esc(string $s): string { return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Простой markdown -> HTML-подмножество Telegram.
 *  **жирный**  *курсив* или _курсив_  __подчёркнутый__  ~~зачёркнутый~~  ||спойлер||
 *  `код`  ```блок кода```  [текст](https://url)  > цитата  # заголовок  - список
 */
function md_to_html(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $stash = [];
    $put = function (string $html) use (&$stash): string {
        $stash[] = $html;
        return "\x01" . (count($stash) - 1) . "\x02";
    };

    $s = preg_replace_callback('/```[a-zA-Z0-9_+-]*\n?(.*?)```/s', fn($m) => $put('<pre>' . esc(trim($m[1], "\n")) . '</pre>'), $s);
    $s = preg_replace_callback('/`([^`\n]+)`/', fn($m) => $put('<code>' . esc($m[1]) . '</code>'), $s);

    $s = esc($s);

    // ссылки (после esc & уже &amp; — корректно для атрибута)
    $inline = function (string $t): string {
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $t);
        $t = preg_replace('/__(.+?)__/s', '<u>$1</u>', $t);
        $t = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $t);
        $t = preg_replace('/\|\|(.+?)\|\|/s', '<tg-spoiler>$1</tg-spoiler>', $t);
        $t = preg_replace('/(?<![\w*])\*(?![\s*])(.+?)(?<![\s*])\*(?![\w*])/s', '<i>$1</i>', $t);
        $t = preg_replace('/(?<![\w_])_(?![\s_])(.+?)(?<![\s_])_(?![\w_])/s', '<i>$1</i>', $t);
        return $t;
    };
    $s = preg_replace_callback('/\[([^\]\n]+)\]\(((?:https?|tg):\/\/[^\s)"<>]+)\)/', function ($m) use ($put, $inline) {
        return $put('<a href="' . $m[2] . '">' . $inline($m[1]) . '</a>');
    }, $s);

    // построчно: цитаты, заголовки, списки
    $out = [];
    $quote = [];
    $flush = function () use (&$quote, &$out) {
        if ($quote) { $out[] = '<blockquote>' . implode("\n", $quote) . '</blockquote>'; $quote = []; }
    };
    foreach (explode("\n", $s) as $line) {
        if (preg_match('/^&gt;\s?(.*)$/', $line, $m)) { $quote[] = $m[1]; continue; }
        $flush();
        if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) $line = '**' . $m[1] . '**';
        elseif (preg_match('/^[-*]\s+(.+)$/', $line, $m)) $line = '• ' . $m[1];
        $out[] = $line;
    }
    $flush();
    $s = $inline(implode("\n", $out));

    // blockquote не должен ломаться внутренними тегами — ок; восстановить stash
    $s = preg_replace_callback("/\x01(\d+)\x02/", fn($m) => $stash[(int)$m[1]], $s);
    return $s;
}

/** Подстановка {first_name} и т.п. в уже готовый HTML. */
function apply_vars(string $html, array $u): string
{
    $map = [
        '{first_name}' => $u['first_name'] ?? '',
        '{last_name}' => $u['last_name'] ?? '',
        '{username}' => $u['username'] ?? '',
        '{id}' => (string)($u['tg_id'] ?? ''),
        '{source}' => $u['source'] ?? '',
    ];
    foreach ($map as $k => $v) $map[$k] = esc((string)$v);
    return strtr($html, $map);
}

function html_to_plain(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tg_set_webhook(): array
{
    return tg('setWebhook', [
        'url' => base_url() . '/webhook.php',
        'secret_token' => (string)setting('webhook_secret'),
        'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        'max_connections' => 20,
    ]);
}
