<?php
// Хелперы интерфейса админки.
declare(strict_types=1);

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf'] ?? '') . '">'; }
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
