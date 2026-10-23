<?php
// Запускать каждую минуту: * * * * * php /путь/tgbot/cron.php
// либо по HTTP: https://домен/tgbot/cron.php?key=CRON_KEY
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (!is_installed()) exit("not installed\n");
$cli = PHP_SAPI === 'cli';
if (!$cli && !hash_equals((string)setting('cron_key'), (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
@set_time_limit(0);
try {
    $r = run_tick($cli ? 50 : 25);
} catch (Throwable $e) {
    log_error($e->getMessage());
    $r = ['error' => $e->getMessage()];
}
header('Content-Type: application/json');
echo json_encode($r), "\n";
