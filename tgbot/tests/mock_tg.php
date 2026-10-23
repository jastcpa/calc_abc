<?php
// Мини-имитация Telegram Bot API для интеграционных тестов (php -S). Лог запросов — в файле TG_MOCK_LOG.
$log = getenv('TG_MOCK_LOG') ?: sys_get_temp_dir() . '/tg_mock.log';
file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'post' => $_POST], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (str_contains($_SERVER['REQUEST_URI'], 'getMe')) echo '{"ok":true,"result":{"id":1,"first_name":"Mock","username":"mockbot"}}';
elseif (str_contains($_SERVER['REQUEST_URI'], '/hook')) echo 'ok';
else echo '{"ok":true,"result":{"message_id":1}}';
