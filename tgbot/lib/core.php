<?php
// Ядро: конфиг, база SQLite, настройки, хелперы.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

function cfg(string $key = '', $default = null)
{
    static $c = null;
    if ($c === null) {
        $f = config_path();
        $c = is_file($f) ? (require $f) : [];
    }
    return $key === '' ? $c : ($c[$key] ?? $default);
}

function config_path(): string
{
    return getenv('TGBOT_CONFIG') ?: APP_ROOT . '/config.php';
}

function is_installed(): bool
{
    return is_file(config_path());
}

function data_dir(): string
{
    return dirname((string)cfg('db', APP_ROOT . '/data/bot.sqlite'));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = cfg('db', APP_ROOT . '/data/bot.sqlite');
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $p = [])
{
    $r = q($sql, $p)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    q('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
    return (int)db()->lastInsertId();
}
function update(string $table, int $id, array $data): void
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    q("UPDATE $table SET $set WHERE id = ?", [...array_values($data), $id]);
}

function migrate(): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS bots (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, token TEXT NOT NULL, username TEXT,
  secret TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, last_update INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS login_fail (ip TEXT NOT NULL, at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS lf_ip ON login_fail(ip, at);
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INTEGER NOT NULL DEFAULT 0, tg_id INTEGER NOT NULL,
  username TEXT, first_name TEXT, last_name TEXT, lang TEXT,
  source TEXT, label TEXT, start_param TEXT,
  status TEXT NOT NULL DEFAULT 'active',
  created_at INTEGER NOT NULL, last_seen INTEGER, blocked_at INTEGER,
  UNIQUE (bot_id, tg_id)
);
CREATE TABLE IF NOT EXISTS groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL,
  color TEXT DEFAULT '#3b82f6', created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS user_groups (
  user_id INTEGER NOT NULL, group_id INTEGER NOT NULL, via TEXT, created_at INTEGER NOT NULL,
  PRIMARY KEY (user_id, group_id)
);
CREATE INDEX IF NOT EXISTS ug_group ON user_groups(group_id);
CREATE TABLE IF NOT EXISTS flows (
  id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT '', is_default INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1, group_id INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT, flow_id INTEGER NOT NULL, position INTEGER NOT NULL,
  delay INTEGER NOT NULL DEFAULT 0, body TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
  buttons TEXT NOT NULL DEFAULT '[]', group_id INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS steps_flow ON steps(flow_id, position);
CREATE TABLE IF NOT EXISTS user_flows (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, flow_id INTEGER NOT NULL,
  last_pos INTEGER NOT NULL DEFAULT -1, next_at INTEGER, status TEXT NOT NULL DEFAULT 'active',
  started_at INTEGER NOT NULL, UNIQUE (user_id, flow_id)
);
CREATE INDEX IF NOT EXISTS uf_due ON user_flows(status, next_at);
CREATE TABLE IF NOT EXISTS broadcasts (
  id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL, body TEXT NOT NULL DEFAULT '',
  image TEXT NOT NULL DEFAULT '', buttons TEXT NOT NULL DEFAULT '[]',
  inc_groups TEXT NOT NULL DEFAULT '[]', exc_groups TEXT NOT NULL DEFAULT '[]',
  send_at INTEGER, status TEXT NOT NULL DEFAULT 'draft',
  total INTEGER NOT NULL DEFAULT 0, sent INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER
);
CREATE TABLE IF NOT EXISTS bq (
  id INTEGER PRIMARY KEY AUTOINCREMENT, broadcast_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending', error TEXT, sent_at INTEGER
);
CREATE INDEX IF NOT EXISTS bq_b ON bq(broadcast_id, status);
CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT NOT NULL,
  ref TEXT NOT NULL DEFAULT '', label TEXT NOT NULL DEFAULT '', data TEXT, created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ev_type ON events(type, created_at);
CREATE INDEX IF NOT EXISTS ev_user ON events(user_id, created_at);
CREATE INDEX IF NOT EXISTS ev_ref ON events(type, ref);
CREATE TABLE IF NOT EXISTS in_rules (
  id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT NOT NULL, action TEXT NOT NULL DEFAULT 'add',
  group_id INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS out_hooks (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, url TEXT NOT NULL,
  method TEXT NOT NULL DEFAULT 'GET', event_type TEXT NOT NULL, filter TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS out_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT, hook_id INTEGER NOT NULL, user_id INTEGER,
  url TEXT NOT NULL, method TEXT NOT NULL, body TEXT, status TEXT NOT NULL DEFAULT 'pending',
  attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL, code INTEGER,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS oq_due ON out_queue(status, next_at);
SQL;
    // апгрейд со старой (однобот.) схемы: пересоздаём users с UNIQUE(bot_id, tg_id), добавляем bot_id
    $has = fn(string $t, string $c) => in_array($c, array_column(rows("PRAGMA table_info($t)"), 'name'), true);
    $legacyUsers = $has('users', 'id') && !$has('users', 'bot_id');
    if ($legacyUsers) db()->exec('ALTER TABLE users RENAME TO users_old');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
    foreach (['flows', 'broadcasts'] as $t) if (!$has($t, 'bot_id')) db()->exec("ALTER TABLE $t ADD COLUMN bot_id INTEGER NOT NULL DEFAULT 0");
    $bot = null;
    if ((int)val('SELECT COUNT(*) FROM bots') === 0 && ($tok = val("SELECT v FROM settings WHERE k = 'bot_token'"))) {
        $bot = insert('bots', ['name' => 'Основной бот', 'token' => $tok, 'username' => val("SELECT v FROM settings WHERE k = 'bot_username'"),
            'secret' => (string)(val("SELECT v FROM settings WHERE k = 'webhook_secret'") ?: rand_token(24)), 'active' => 1, 'created_at' => now()]);
    }
    if ($legacyUsers) {
        $bid = $bot ?: (int)val('SELECT MIN(id) FROM bots');
        db()->exec("INSERT INTO users (id, bot_id, tg_id, username, first_name, last_name, lang, source, label, start_param, status, created_at, last_seen, blocked_at)
          SELECT id, $bid, tg_id, username, first_name, last_name, lang, source, label, start_param, status, created_at, last_seen, blocked_at FROM users_old");
        db()->exec('DROP TABLE users_old');
        if ($bid) foreach (['flows', 'broadcasts'] as $t) db()->exec("UPDATE $t SET bot_id = $bid");
    }
    foreach (['users_source ON users(source)', 'users_label ON users(label)', 'users_created ON users(created_at)', 'users_bot ON users(bot_id)'] as $ix) {
        db()->exec('CREATE INDEX IF NOT EXISTS ' . $ix);
    }
}

// ---------- настройки ----------
function &settings_store(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (rows('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v']; } catch (Throwable $e) {}
    }
    return $cache;
}
function setting(string $k, $default = '')
{
    $c = &settings_store();
    return $c[$k] ?? $default;
}
function set_setting(string $k, string $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
    $c = &settings_store();
    $c[$k] = $v;
}

// ---------- хелперы ----------
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): int { return time(); }
function rand_token(int $bytes = 16): string { return bin2hex(random_bytes($bytes)); }

function tz(): DateTimeZone
{
    try { return new DateTimeZone((string)setting('tz', 'Europe/Moscow')); }
    catch (Throwable $e) { return new DateTimeZone('UTC'); }
}
function fmt_dt(?int $ts, string $f = 'd.m.Y H:i'): string
{
    if (!$ts) return '—';
    return (new DateTime('@' . $ts))->setTimezone(tz())->format($f);
}
/** "2025-01-31T14:30" (в часовом поясе админки) -> unix */
function parse_local(string $s): ?int
{
    if (trim($s) === '') return null;
    try { return (new DateTime(str_replace('T', ' ', $s), tz()))->getTimestamp(); }
    catch (Throwable $e) { return null; }
}
function local_input(?int $ts): string
{
    return $ts ? (new DateTime('@' . $ts))->setTimezone(tz())->format('Y-m-d\TH:i') : '';
}

function base_url(): string
{
    $u = (string)setting('base_url', '');
    if ($u !== '') return rtrim($u, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (basename($dir) === 'admin') $dir = dirname($dir);
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($dir, '/');
}

function json_arr($s): array
{
    $a = json_decode((string)$s, true);
    return is_array($a) ? $a : [];
}

function human_delay(int $sec): string
{
    if ($sec <= 0) return 'сразу';
    foreach ([86400 => 'д', 3600 => 'ч', 60 => 'мин'] as $n => $u) {
        if ($sec % $n === 0) return ($sec / $n) . ' ' . $u;
    }
    return $sec . ' сек';
}
