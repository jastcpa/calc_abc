<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/engine.php';

if (is_installed()) migrate();

function log_error(string $msg): void
{
    @file_put_contents(APP_ROOT . '/data/error.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
}
