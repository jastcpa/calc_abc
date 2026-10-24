<?php
// Хелперы интерфейса админки.
declare(strict_types=1);

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf'] ?? '') . '">'; }
/** Проверка права на действие (POST). При отказе — сообщение и возврат на страницу. */
function need(string $perm): void
{
    if (can($perm)) return;
    flash('Недостаточно прав для этого действия', 'err');
    redirect('?p=' . urlencode((string)($_GET['p'] ?? '')) . (isset($_GET['id']) ? '&id=' . (int)$_GET['id'] : ''));
}

function need_scope(int $botId): void
{
    if (bot_ok($botId)) return;
    flash('Этот бот вам не назначен', 'err');
    redirect('?p=' . urlencode((string)($_GET['p'] ?? '')));
}

function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function post(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }
function post_ids(string $k): array { return array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$k] ?? []))))); }

function group_options(int $sel = 0, string $empty = '— нет —'): string
{
    $o = '<option value="0">' . h($empty) . '</option>';
    foreach (rows('SELECT id, name FROM groups ORDER BY name') as $g) {
        $o .= '<option value="' . $g['id'] . '"' . ($g['id'] == $sel ? ' selected' : '') . '>' . h($g['name']) . '</option>';
    }
    return $o;
}

function bot_options(int $sel = 0, string $all = 'Все боты'): string
{
    $o = ($all !== '' && unrestricted()) ? '<option value="0">' . h($all) . '</option>' : '';
    foreach (visible_bots() as $b) {
        $o .= '<option value="' . $b['id'] . '"' . ($b['id'] == $sel ? ' selected' : '') . '>' . h($b['name'] . ($b['username'] ? ' (@' . $b['username'] . ')' : '')) . ($b['active'] ? '' : ' [выкл]') . '</option>';
    }
    return $o;
}

function bot_names(): array
{
    static $m = null;
    return $m ??= array_column(rows('SELECT id, name, username FROM bots'), null, 'id');
}

function bot_label(int $id): string
{
    $b = bot_names()[$id] ?? null;
    return $b ? ($b['username'] ? '@' . $b['username'] : $b['name']) : 'все боты';
}

function group_tag(array $g): string
{
    return '<span class="tag" style="background:' . h($g['color'] ?: '#3b82f6') . '">' . h($g['name']) . '</span>';
}

function pager(int $total, int $page, int $per, array $params): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $out = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i > 1 && $i < $pages && abs($i - $page) > 3) { if (abs($i - $page) === 4) $out .= '…'; continue; }
        $out .= $i === $page ? '<span>' . $i . '</span>' : '<a href="?' . h(http_build_query($params + ['page' => $i])) . '">' . $i . '</a>';
    }
    return $out . '</div>';
}

function user_name(array $u): string
{
    $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $n !== '' ? $n : ($u['username'] ? '@' . $u['username'] : (string)$u['tg_id']);
}

/** Редактор сообщения: markdown + превью + кнопки. $m — строка шага/рассылки. */
function msg_editor(array $m): string
{
    ob_start(); ?>
    <div class="editor">
      <div>
        <div class="toolbar">
          <button data-wrap="**|**" title="Жирный"><b>B</b></button>
          <button data-wrap="_|_" title="Курсив"><i>I</i></button>
          <button data-wrap="__|__" title="Подчёркнутый"><u>U</u></button>
          <button data-wrap="~~|~~" title="Зачёркнутый"><s>S</s></button>
          <button data-wrap="||" title="Спойлер" data-ph="скрытый текст">▒</button>
          <button data-wrap="`|`" title="Код">&lt;/&gt;</button>
          <button data-wrap="```\n|\n```" title="Блок кода">{ }</button>
          <button data-wrap="> |" title="Цитата">❝</button>
          <button data-link title="Ссылка">🔗</button>
          <button data-var="{first_name}" title="Имя пользователя">{имя}</button>
          <button data-var="{username}">{username}</button>
        </div>
        <textarea name="body" rows="9" placeholder="Текст сообщения (markdown)"><?= h($m['body'] ?? '') ?></textarea>
        <div class="counter">0</div>
        <label>Картинка (URL, необязательно — текст станет подписью)</label>
        <input name="image" value="<?= h($m['image'] ?? '') ?>" placeholder="https://example.com/pic.jpg">
        <label>Кнопки под сообщением</label>
        <input type="hidden" name="buttons" value="<?= h($m['buttons'] ?? '[]') ?>">
        <div class="btn-rows"></div>
        <a href="#" data-addrow class="btn sec sm">+ добавить ряд кнопок</a>
        <p class="muted" style="font-size:12px">Разметка: <code>**жирный**</code> <code>_курсив_</code> <code>__подчёрк.__</code> <code>~~зачёрк.~~</code> <code>||спойлер||</code> <code>`код`</code> <code>[текст](https://...)</code> <code>&gt; цитата</code> <code>- список</code></p>
      </div>
      <div class="tgbg"><div class="muted" style="color:#fff;margin-bottom:6px">Предпросмотр</div>
        <div class="bubble"><div class="bubble-img"></div><div class="bubble-text"></div></div>
        <div class="bubble-btns"></div>
      </div>
    </div>
    <?php return ob_get_clean();
}

function spark(array $series, string $labelFmt = ''): string
{
    $max = max(1, ...array_values($series ?: [0]));
    $o = '<div class="bars">';
    foreach ($series as $d => $n) $o .= '<div style="height:' . max(1, (int)round($n / $max * 100)) . '%" data-t="' . h($d) . ': ' . $n . '"></div>';
    $o .= '</div>';
    $k = array_keys($series);
    if ($k) $o .= '<div class="axis"><span>' . h((string)reset($k)) . '</span><span>' . h((string)end($k)) . '</span></div>';
    return $o;
}

// ---------- иконки (inline SVG, без внешних зависимостей) ----------
function icon(string $n, string $cls = ''): string
{
    static $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'tag' => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
        'flows' => '<circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M6 9v3a3 3 0 0 0 3 3h6"/>',
        'send' => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="M16.2 7.8l-2.1 6.3-6.3 2.1 2.1-6.3 6.3-2.1z"/>',
        'repeat' => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'bot' => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>',
        'team' => '<circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4"/><circle cx="18" cy="17" r="3"/><path d="M18 12v2M18 20v2M13.5 17h2M20.5 17h2"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/>',
        'menu' => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'chev' => '<path d="M6 9l6 6 6-6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    ];
    return '<svg class="i ' . h($cls) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

// ---------- глобальный контекст бота (переключатель в верхней панели) ----------
function ctx_bot(): int
{
    $id = (int)($_SESSION['bot_ctx'] ?? 0);
    return ($id > 0 && bot_ok($id) && bot_get_fresh($id)) ? $id : 0;
}
function bot_get_fresh(int $id): ?array { return row('SELECT id FROM bots WHERE id = ?', [$id]); }

/** Какой бот применяется к списку: явный ?bot=, иначе глобальный контекст. */
function effective_bot(): int
{
    $b = isset($_GET['bot']) ? (int)$_GET['bot'] : ctx_bot();
    return ($b > 0 && bot_ok($b)) ? $b : 0;
}

// ---------- шаблоны блоков ----------
function empty_state(string $icon, string $title, string $text, string $cta = ''): string
{
    return '<div class="empty">' . icon($icon) . '<h3>' . h($title) . '</h3><p>' . h($text) . '</p>' . $cta . '</div>';
}

function delta(int $now, int $prev): string
{
    if ($prev === 0 && $now === 0) return '';
    if ($prev === 0) return '<span class="delta up" title="в прошлом периоде: 0">новое</span>';
    $d = (int)round(($now - $prev) / $prev * 100);
    if ($d > 999) return '<span class="delta up" title="в прошлом периоде: ' . $prev . '">▲ &gt;999%</span>';
    $cls = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat');
    return '<span class="delta ' . $cls . '" title="в прошлом периоде: ' . $prev . '">' . ($d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '')) . abs($d) . '%</span>';
}

function initials(string $s): string
{
    $s = trim($s) ?: '?';
    return mb_strtoupper(mb_substr($s, 0, 1));
}

function pagehead(string $title, string $actions = '', string $sub = ''): string
{
    return '<div class="pagehead"><h1>' . h($title) . '</h1><span class="sp"></span>' . $actions . ($sub !== '' ? '<p class="sub">' . h($sub) . '</p>' : '') . '</div>';
}
