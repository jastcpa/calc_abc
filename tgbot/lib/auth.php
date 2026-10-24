<?php
// Пользователи админки: роли, права, доступ к ботам.
declare(strict_types=1);

/** Делегируемые права. Группы нужны для интерфейса. Управление пользователями/ролями/ботами/настройками — только у админа. */
const PERM_GROUPS = [
    'Дашборд' => ['dashboard.view' => 'Просмотр аналитики'],
    'Подписчики бота' => ['users.view' => 'Просмотр', 'users.edit' => 'Управление группами пользователей', 'users.delete' => 'Удаление', 'users.export' => 'Экспорт CSV'],
    'Группы' => ['groups.view' => 'Просмотр', 'groups.edit' => 'Создание групп (переименование/удаление — только при доступе ко всем ботам)'],
    'Цепочки' => ['flows.view' => 'Просмотр', 'flows.edit' => 'Редактирование'],
    'Рассылки' => ['broadcasts.view' => 'Просмотр', 'broadcasts.edit' => 'Создание и редактирование черновиков', 'broadcasts.send' => 'Планирование и запуск отправки'],
    'Постбеки (доступ ко всем ботам)' => ['postbacks.view' => 'Просмотр', 'postbacks.edit' => 'Редактирование'],
    'Ссылки' => ['links.view' => 'Генератор ссылок и источники'],
];

function all_perms(): array
{
    $o = [];
    foreach (PERM_GROUPS as $g) foreach ($g as $k => $_) $o[] = $k;
    return $o;
}

/** Загрузка пользователя админки вместе с ролью, правами и ботами. */
function auth_load(int $id): ?array
{
    $u = row('SELECT a.*, r.name AS role_name, r.perms AS role_perms, r.is_admin FROM admin_users a JOIN admin_roles r ON r.id = a.role_id WHERE a.id = ?', [$id]);
    if (!$u) return null;
    $u['is_admin'] = (bool)$u['is_admin'];
    $u['perms'] = $u['is_admin'] ? all_perms() : array_values(array_intersect(all_perms(), json_arr($u['role_perms'])));
    $u['bots'] = array_map('intval', array_column(rows('SELECT bot_id FROM admin_user_bots WHERE admin_id = ?', [$id]), 'bot_id'));
    return $u;
}

function me(): ?array { return $GLOBALS['ME'] ?? null; }

function is_admin(?array $u = null): bool { $u ??= me(); return (bool)($u['is_admin'] ?? false); }

function can(string $perm, ?array $u = null): bool
{
    $u ??= me();
    return $u && ($u['is_admin'] || in_array($perm, $u['perms'], true));
}

/** Ограничен ли пользователь списком ботов. null → доступ ко всем. Админ всегда видит всё. */
function bot_scope(?array $u = null): ?array
{
    $u ??= me();
    if (!$u || $u['is_admin'] || $u['all_bots']) return null;
    return $u['bots'];
}

function unrestricted(?array $u = null): bool { return bot_scope($u) === null; }

/** Бот доступен? Элемент «для всех ботов» (id=0) доступен только пользователю без ограничений. */
function bot_ok(int $botId, ?array $u = null): bool
{
    $s = bot_scope($u);
    return $s === null || ($botId > 0 && in_array($botId, $s, true));
}

/** SQL-условие " AND col IN (...)" для ограниченного пользователя; для остальных пусто. $allowZero — включать записи «для всех ботов». */
function scope_sql(string $col, bool $allowZero = false, ?array $u = null): string
{
    $s = bot_scope($u);
    if ($s === null) return '';
    $ids = $s ? implode(',', array_map('intval', $s)) : '';
    if ($allowZero) $ids = $ids === '' ? '0' : $ids . ',0';
    return $ids === '' ? ' AND 0' : " AND $col IN ($ids)";
}

function visible_bots(?array $u = null): array
{
    return rows('SELECT * FROM bots WHERE 1=1' . scope_sql('id', false, $u) . ' ORDER BY id');
}

function active_admin_count(): int
{
    return (int)val('SELECT COUNT(*) FROM admin_users a JOIN admin_roles r ON r.id = a.role_id WHERE r.is_admin = 1 AND a.active = 1');
}

function audit(string $action, string $detail = ''): void
{
    q('INSERT INTO audit (admin_id, action, detail, ip, at) VALUES (?,?,?,?,?)', [me()['id'] ?? null, $action, mb_substr($detail, 0, 500), $_SERVER['REMOTE_ADDR'] ?? '', now()]);
}

function password_problem(string $p): ?string
{
    if (mb_strlen($p) < 8) return 'Пароль не короче 8 символов';
    return null;
}

/** Создать стартовые роли, если их нет. Возвращает id роли админа. */
function seed_roles(): int
{
    $adm = (int)val('SELECT id FROM admin_roles WHERE is_admin = 1 LIMIT 1');
    if ($adm) return $adm;
    $adm = insert('admin_roles', ['name' => 'Администратор', 'description' => 'Полный доступ ко всему, управление пользователями, ролями, ботами и настройками', 'perms' => '[]', 'is_admin' => 1, 'created_at' => now()]);
    $mk = fn($n, $d, $p) => insert('admin_roles', ['name' => $n, 'description' => $d, 'perms' => json_encode($p), 'is_admin' => 0, 'created_at' => now()]);
    $mk('Менеджер рассылок', 'Готовит и запускает рассылки, видит аудиторию', ['dashboard.view', 'users.view', 'groups.view', 'groups.edit', 'broadcasts.view', 'broadcasts.edit', 'broadcasts.send', 'links.view']);
    $mk('Редактор контента', 'Редактирует цепочки и черновики рассылок, отправлять не может', ['dashboard.view', 'flows.view', 'flows.edit', 'broadcasts.view', 'broadcasts.edit', 'groups.view']);
    $mk('Аналитик', 'Только просмотр статистики и пользователей', ['dashboard.view', 'users.view', 'users.export', 'groups.view', 'flows.view', 'broadcasts.view', 'links.view']);
    return $adm;
}
