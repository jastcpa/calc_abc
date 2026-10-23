<?php
// Движок бота: пользователи, группы, события, отправка, цепочки, рассылки, постбеки.
declare(strict_types=1);

// ======================= события и исходящие постбеки =======================

function ev(?int $userId, string $type, string $ref = '', string $label = '', array $data = []): void
{
    q('INSERT INTO events (user_id, type, ref, label, data, created_at) VALUES (?,?,?,?,?,?)',
        [$userId, $type, $ref, mb_substr($label, 0, 200), $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, now()]);
    hooks_enqueue($type, $userId, $ref, $label, $data);
}

function hooks_enqueue(string $type, ?int $userId, string $ref, string $label, array $data): void
{
    $hooks = rows('SELECT * FROM out_hooks WHERE active = 1 AND event_type = ?', [$type]);
    if (!$hooks) return;
    $u = $userId ? row('SELECT * FROM users WHERE id = ?', [$userId]) : null;
    foreach ($hooks as $hk) {
        $f = trim($hk['filter']);
        if ($f !== '' && $f !== $ref && !str_starts_with($ref, $f . ':')) continue;
        $vars = [
            'tg_id' => $u['tg_id'] ?? '', 'user_id' => $userId ?? '', 'label' => $u['label'] ?? '',
            'source' => $u['source'] ?? '', 'username' => $u['username'] ?? '',
            'event' => $type, 'ref' => $ref, 'value' => $data['value'] ?? '', 'group' => $type === 'group_add' ? $label : '',
        ];
        $url = $hk['url'];
        foreach ($vars as $k => $v) $url = str_replace('{' . $k . '}', rawurlencode((string)$v), $url);
        q('INSERT INTO out_queue (hook_id, user_id, url, method, body, next_at, created_at) VALUES (?,?,?,?,?,?,?)',
            [$hk['id'], $userId, $url, $hk['method'], $hk['method'] === 'POST' ? json_encode($vars, JSON_UNESCAPED_UNICODE) : null, now(), now()]);
    }
}

/** Защита от SSRF: только http(s) и только публичные адреса (если не включено allow_private_hooks). */
function url_is_public_http(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    if (setting('allow_private_hooks', '0') === '1') return true;
    $host = trim($p['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

/** Ссылки кнопок: только http(s) и tg://. */
function url_is_button_safe(string $url): bool
{
    return (bool)preg_match('~^(https?|tg)://[^\s"<>]+$~i', $url);
}

function hooks_process(int $limit = 20): int
{
    $n = 0;
    foreach (rows("SELECT * FROM out_queue WHERE status = 'pending' AND next_at <= ? ORDER BY id LIMIT $limit", [now()]) as $j) {
        if (!url_is_public_http($j['url'])) { q("UPDATE out_queue SET status='failed', code=0 WHERE id=?", [$j['id']]); continue; }
        $ch = curl_init($j['url']);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
        if ($j['method'] === 'POST') {
            $opt += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $j['body'], CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
        }
        curl_setopt_array($ch, $opt);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $att = (int)$j['attempts'] + 1;
        if ($code >= 200 && $code < 300) {
            q("UPDATE out_queue SET status='sent', code=?, attempts=? WHERE id=?", [$code, $att, $j['id']]);
        } else {
            q('UPDATE out_queue SET status=?, code=?, attempts=?, next_at=? WHERE id=?',
                [$att >= 5 ? 'failed' : 'pending', $code, $att, now() + 60 * $att * $att, $j['id']]);
        }
        $n++;
    }
    return $n;
}

// ======================= пользователи и группы =======================

function user_touch(array $from, ?string $param = null, int $botId = 0): array
{
    $tgId = (int)$from['id'];
    $u = row('SELECT * FROM users WHERE bot_id = ? AND tg_id = ?', [$botId, $tgId]);
    $fields = [
        'username' => $from['username'] ?? null, 'first_name' => $from['first_name'] ?? null,
        'last_name' => $from['last_name'] ?? null, 'lang' => $from['language_code'] ?? null,
    ];
    $source = null; $label = null;
    if ($param !== null && $param !== '') {
        $param = preg_replace('/[^A-Za-z0-9_-]/', '', mb_substr($param, 0, 64));
        [$source, $label] = array_pad(explode('__', $param, 2), 2, null);
        $source = ($source !== null && $source !== '') ? $source : null;
        $label = ($label !== null && $label !== '') ? $label : null;
    }
    if (!$u) {
        $id = insert('users', $fields + [
            'bot_id' => $botId, 'tg_id' => $tgId, 'source' => $source, 'label' => $label, 'start_param' => $param,
            'status' => 'active', 'created_at' => now(), 'last_seen' => now(),
        ]);
        $u = row('SELECT * FROM users WHERE id = ?', [$id]);
        $u['_new'] = true;
        return $u;
    }
    $upd = array_filter($fields, fn($v) => $v !== null) + ['last_seen' => now()];
    if (!$u['source'] && $source) { $upd['source'] = $source; $upd['start_param'] = $param; }
    if (!$u['label'] && $label) $upd['label'] = $label;
    if ($u['status'] === 'blocked') { $upd['status'] = 'active'; $upd['blocked_at'] = null; }
    update('users', (int)$u['id'], $upd);
    $u = row('SELECT * FROM users WHERE id = ?', [$u['id']]);
    $u['_new'] = false;
    return $u;
}

function user_blocked(int $uid): void
{
    $u = row('SELECT status FROM users WHERE id = ?', [$uid]);
    if ($u && $u['status'] !== 'blocked') {
        q("UPDATE users SET status='blocked', blocked_at=? WHERE id=?", [now(), $uid]);
        q("UPDATE user_flows SET status='stopped' WHERE user_id=? AND status='active'", [$uid]);
        ev($uid, 'blocked');
    }
}

function group_add(int $uid, int $gid, string $via = ''): bool
{
    if ($gid <= 0) return false;
    $g = row('SELECT name FROM groups WHERE id = ?', [$gid]);
    if (!$g) return false;
    $st = q('INSERT OR IGNORE INTO user_groups (user_id, group_id, via, created_at) VALUES (?,?,?,?)', [$uid, $gid, $via, now()]);
    if ($st->rowCount() > 0) { ev($uid, 'group_add', (string)$gid, $g['name']); return true; }
    return false;
}

function group_remove(int $uid, int $gid): void
{
    $g = row('SELECT name FROM groups WHERE id = ?', [$gid]);
    $st = q('DELETE FROM user_groups WHERE user_id = ? AND group_id = ?', [$uid, $gid]);
    if ($g && $st->rowCount() > 0) ev($uid, 'group_remove', (string)$gid, $g['name']);
}

function group_ensure(string $name): int
{
    $id = val('SELECT id FROM groups WHERE name = ?', [$name]);
    return $id ? (int)$id : insert('groups', ['name' => $name, 'color' => '#8b5cf6', 'created_at' => now()]);
}

// ======================= сообщения и кнопки =======================

function msg_by_key(string $key): ?array
{
    $t = $key[0] ?? ''; $id = (int)substr($key, 1);
    if ($t === 's') return row('SELECT * FROM steps WHERE id = ?', [$id]);
    if ($t === 'b') return row('SELECT * FROM broadcasts WHERE id = ?', [$id]);
    return null;
}

function msg_label(string $key): string
{
    $t = $key[0] ?? ''; $id = (int)substr($key, 1);
    if ($t === 's') {
        $r = row('SELECT s.position, f.name FROM steps s JOIN flows f ON f.id = s.flow_id WHERE s.id = ?', [$id]);
        return $r ? 'Цепочка «' . $r['name'] . '», шаг ' . ($r['position'] + 1) : $key;
    }
    if ($t === 'b') {
        $r = row('SELECT name FROM broadcasts WHERE id = ?', [$id]);
        return $r ? 'Рассылка «' . $r['name'] . '»' : $key;
    }
    return $key;
}

/** Нормализация кнопок из формы: структура, длины, только безопасные ссылки. Возвращает JSON. */
function sanitize_buttons($raw): string
{
    $out = [];
    foreach (is_array($raw) ? array_slice($raw, 0, 8) : [] as $rowBtns) {
        $row = [];
        foreach (is_array($rowBtns) ? array_slice($rowBtns, 0, 8) : [] as $b) {
            if (!is_array($b)) continue;
            $text = mb_substr(trim((string)($b['text'] ?? '')), 0, 64);
            if ($text === '') continue;
            $btn = ['text' => $text, 'group' => max(0, (int)($b['group'] ?? 0))];
            if (($b['type'] ?? 'url') === 'cb') {
                $btn += ['type' => 'cb', 'reply' => mb_substr((string)($b['reply'] ?? ''), 0, 4000)];
            } else {
                $url = trim((string)($b['url'] ?? ''));
                if (!url_is_button_safe($url)) continue;
                $btn += ['type' => 'url', 'url' => mb_substr($url, 0, 1000), 'track' => ($b['track'] ?? true) !== false];
            }
            $row[] = $btn;
        }
        if ($row) $out[] = $row;
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

function clean_image(string $url): string
{
    $url = trim($url);
    return preg_match('~^https?://[^\s"<>]+$~i', $url) ? mb_substr($url, 0, 1000) : '';
}

function flat_buttons(array $m): array
{
    $flat = [];
    foreach (json_arr($m['buttons'] ?? '[]') as $r => $rowBtns) {
        foreach ((array)$rowBtns as $b) $flat[] = $b + ['_row' => $r];
    }
    return $flat;
}

function link_sig(string $key, int $idx, int $userId): string
{
    return substr(hash_hmac('sha256', "$key|$idx|$userId", (string)setting('secret')), 0, 12);
}

function build_markup(array $m, string $key, array $u): ?array
{
    $rows = []; $i = 0;
    $track = str_starts_with(base_url(), 'https://');
    foreach (json_arr($m['buttons'] ?? '[]') as $rowBtns) {
        $out = [];
        foreach ((array)$rowBtns as $b) {
            $text = trim((string)($b['text'] ?? ''));
            if ($text === '') { $i++; continue; }
            if (($b['type'] ?? 'url') === 'url') {
                $url = trim((string)($b['url'] ?? ''));
                if (!url_is_button_safe($url)) { $i++; continue; }
                if ($track && ($b['track'] ?? true) && $key !== '' && str_starts_with($url, 'http')) {
                    $url = base_url() . '/go.php?m=' . $key . '&i=' . $i . '&u=' . (int)$u['id'] . '&s=' . link_sig($key, $i, (int)$u['id']);
                }
                $out[] = ['text' => $text, 'url' => $url];
            } else {
                $out[] = ['text' => $text, 'callback_data' => "c|$key|$i"];
            }
            $i++;
        }
        if ($out) $rows[] = $out;
    }
    return $rows ? ['inline_keyboard' => $rows] : null;
}

/** Отправка сообщения (шаг/рассылка) пользователю. */
function send_to_user(array $u, array $m, string $key): array
{
    $html = apply_vars(md_to_html((string)$m['body']), $u);
    $img = trim((string)($m['image'] ?? ''));
    if ($html === '' && $img === '') return ['ok' => false, 'description' => 'Пустое сообщение'];
    $markup = build_markup($m, $key, $u);
    $p = ['chat_id' => $u['tg_id'], 'parse_mode' => 'HTML'];
    if ($markup) $p['reply_markup'] = $markup;
    if ($img !== '') { $method = 'sendPhoto'; $p['photo'] = $img; if ($html !== '') $p['caption'] = $html; }
    else { $method = 'sendMessage'; $p['text'] = $html; }

    $bot = (int)($u['bot_id'] ?? 0);
    $r = tg($bot, $method, $p);
    if (!($r['ok'] ?? false) && stripos((string)($r['description'] ?? ''), 'parse entities') !== false) {
        unset($p['parse_mode']);
        $plain = html_to_plain($html);
        if ($method === 'sendPhoto') $p['caption'] = $plain; else $p['text'] = $plain;
        $r = tg($bot, $method, $p);
    }
    if (!($r['ok'] ?? false) && ($r['error_code'] ?? 0) == 403) user_blocked((int)$u['id']);
    return $r;
}

/** Ответ на нажатие кнопки (callback_query). */
function handle_callback(array $cq, int $botId = 0): void
{
    $data = (string)($cq['data'] ?? '');
    $from = $cq['from'] ?? [];
    $ack = fn(string $text = '') => tg($botId, 'answerCallbackQuery', ['callback_query_id' => $cq['id']] + ($text !== '' ? ['text' => $text] : []));
    if (!preg_match('/^c\|([sb]\d+)\|(\d+)$/', $data, $mm)) { $ack(); return; }
    [, $key, $idx] = $mm;
    $u = user_touch($from, null, $botId);
    $m = msg_by_key($key);
    $btn = $m ? (flat_buttons($m)[(int)$idx] ?? null) : null;
    if (!$btn) { $ack(); return; }
    ev((int)$u['id'], 'click', "$key:$idx", (string)($btn['text'] ?? ''));
    if (!empty($btn['group'])) group_add((int)$u['id'], (int)$btn['group'], 'button');
    $ack();
    $reply = trim((string)($btn['reply'] ?? ''));
    if ($reply !== '') send_to_user($u, ['body' => $reply, 'image' => '', 'buttons' => '[]'], '');
}

// ======================= цепочки =======================

/** Цепочка бота имеет приоритет над общей (bot_id = 0); сначала совпадение по источнику, затем «по умолчанию». */
function flow_pick(?string $source, int $botId = 0): ?array
{
    if ($source) {
        $f = row("SELECT * FROM flows WHERE active = 1 AND source <> '' AND lower(source) = lower(?) AND bot_id IN (0, ?) ORDER BY bot_id DESC, id LIMIT 1", [$source, $botId]);
        if ($f) return $f;
    }
    return row("SELECT * FROM flows WHERE active = 1 AND is_default = 1 AND bot_id IN (0, ?) ORDER BY bot_id DESC, id LIMIT 1", [$botId]);
}

function flow_enroll(array $u, bool $restart = false): void
{
    $flow = flow_pick($u['source'], (int)$u['bot_id']);
    if (!$flow) return;
    $existing = row('SELECT id FROM user_flows WHERE user_id = ? AND flow_id = ?', [$u['id'], $flow['id']]);
    if ($existing) {
        if (!$restart) return;
        q('DELETE FROM user_flows WHERE id = ?', [$existing['id']]);
    }
    $first = row('SELECT delay FROM steps WHERE flow_id = ? ORDER BY position LIMIT 1', [$flow['id']]);
    if (!$first) return;
    insert('user_flows', [
        'user_id' => $u['id'], 'flow_id' => $flow['id'], 'last_pos' => -1,
        'next_at' => now() + (int)$first['delay'], 'status' => 'active', 'started_at' => now(),
    ]);
    ev((int)$u['id'], 'flow_start', (string)$flow['id'], $flow['name']);
    if ($flow['group_id']) group_add((int)$u['id'], (int)$flow['group_id'], 'flow');
}

/** Отправка просроченных шагов цепочек. */
function flow_tick(int $deadline, ?int $onlyUser = null): int
{
    $sent = 0;
    $sql = "SELECT uf.* FROM user_flows uf JOIN flows f ON f.id = uf.flow_id
            WHERE uf.status = 'active' AND f.active = 1 AND uf.next_at <= ?" . ($onlyUser ? ' AND uf.user_id = ' . (int)$onlyUser : '') . ' ORDER BY uf.next_at LIMIT 300';
    foreach (rows($sql, [now()]) as $uf) {
        if (time() >= $deadline) break;
        // захват строки (защита от параллельных запусков)
        $claim = q('UPDATE user_flows SET next_at = ? WHERE id = ? AND next_at = ?', [now() + 300, $uf['id'], $uf['next_at']]);
        if ($claim->rowCount() === 0) continue;
        $u = row('SELECT * FROM users WHERE id = ?', [$uf['user_id']]);
        if (!$u || $u['status'] === 'blocked') { q("UPDATE user_flows SET status='stopped' WHERE id=?", [$uf['id']]); continue; }
        $step = row('SELECT * FROM steps WHERE flow_id = ? AND position > ? ORDER BY position LIMIT 1', [$uf['flow_id'], $uf['last_pos']]);
        if (!$step) { q("UPDATE user_flows SET status='done' WHERE id=?", [$uf['id']]); continue; }

        $r = send_to_user($u, $step, 's' . $step['id']);
        if ($r['ok'] ?? false) {
            $sent++;
            ev((int)$u['id'], 'step_sent', 's' . $step['id'], '');
            if ($step['group_id']) group_add((int)$u['id'], (int)$step['group_id'], 'step');
            $next = row('SELECT delay FROM steps WHERE flow_id = ? AND position > ? ORDER BY position LIMIT 1', [$uf['flow_id'], $step['position']]);
            if ($next) {
                q('UPDATE user_flows SET last_pos = ?, next_at = ? WHERE id = ?', [$step['position'], now() + (int)$next['delay'], $uf['id']]);
            } else {
                q("UPDATE user_flows SET last_pos = ?, status='done' WHERE id = ?", [$step['position'], $uf['id']]);
                ev((int)$u['id'], 'flow_done', (string)$uf['flow_id']);
            }
            usleep(40000);
        } elseif (($r['error_code'] ?? 0) == 403) {
            q("UPDATE user_flows SET status='stopped' WHERE id=?", [$uf['id']]);
        } elseif (($r['error_code'] ?? 0) == 429) {
            q('UPDATE user_flows SET next_at = ? WHERE id = ?', [now() + (int)($r['parameters']['retry_after'] ?? 30) + 1, $uf['id']]);
            break;
        } else {
            ev((int)$u['id'], 'send_error', 's' . $step['id'], (string)($r['description'] ?? ''));
            q('UPDATE user_flows SET next_at = ? WHERE id = ?', [now() + 600, $uf['id']]);
        }
    }
    return $sent;
}

// ======================= рассылки =======================

function bc_recipient_sql(array $b): array
{
    $w = ["u.status = 'active'", 'u.bot_id IN (SELECT id FROM bots WHERE active = 1)']; $p = [];
    if ((int)($b['bot_id'] ?? 0) > 0) { $w[] = 'u.bot_id = ?'; $p[] = (int)$b['bot_id']; }
    $inc = array_values(array_filter(array_map('intval', json_arr($b['inc_groups']))));
    $exc = array_values(array_filter(array_map('intval', json_arr($b['exc_groups']))));
    if ($inc) $w[] = 'EXISTS (SELECT 1 FROM user_groups g WHERE g.user_id = u.id AND g.group_id IN (' . implode(',', $inc) . '))';
    if ($exc) $w[] = 'NOT EXISTS (SELECT 1 FROM user_groups g WHERE g.user_id = u.id AND g.group_id IN (' . implode(',', $exc) . '))';
    return [implode(' AND ', $w), $p];
}

function bc_count(array $b): int
{
    [$w, $p] = bc_recipient_sql($b);
    return (int)val("SELECT COUNT(*) FROM users u WHERE $w", $p);
}

function bc_start(int $id): void
{
    $b = row('SELECT * FROM broadcasts WHERE id = ?', [$id]);
    if (!$b || !in_array($b['status'], ['draft', 'scheduled'], true)) return;
    [$w, $p] = bc_recipient_sql($b);
    db()->beginTransaction();
    // захват: параллельный запуск не создаст очередь дважды
    if (q("UPDATE broadcasts SET status='sending' WHERE id = ? AND status IN ('draft','scheduled')", [$id])->rowCount() === 0) { db()->rollBack(); return; }
    q("INSERT INTO bq (broadcast_id, user_id) SELECT $id, u.id FROM users u WHERE $w", $p);
    $total = (int)val('SELECT COUNT(*) FROM bq WHERE broadcast_id = ?', [$id]);
    q("UPDATE broadcasts SET status='sending', total=?, started_at=? WHERE id=?", [$total, now(), $id]);
    db()->commit();
}

function bc_tick(int $deadline): int
{
    foreach (rows("SELECT id FROM broadcasts WHERE status='scheduled' AND send_at IS NOT NULL AND send_at <= ?", [now()]) as $b) bc_start((int)$b['id']);
    $sent = 0;
    foreach (rows("SELECT * FROM broadcasts WHERE status='sending' ORDER BY id") as $b) {
        $key = 'b' . $b['id'];
        while (time() < $deadline) {
            $batch = rows("SELECT * FROM bq WHERE broadcast_id = ? AND status='pending' ORDER BY id LIMIT 25", [$b['id']]);
            if (!$batch) break;
            foreach ($batch as $it) {
                if (time() >= $deadline) break 2;
                $u = row('SELECT * FROM users WHERE id = ?', [$it['user_id']]);
                if (!$u || $u['status'] === 'blocked') {
                    q("UPDATE bq SET status='skipped' WHERE id=?", [$it['id']]);
                    continue;
                }
                $r = send_to_user($u, $b, $key);
                if ($r['ok'] ?? false) {
                    q("UPDATE bq SET status='sent', sent_at=? WHERE id=?", [now(), $it['id']]);
                    q('UPDATE broadcasts SET sent = sent + 1 WHERE id = ?', [$b['id']]);
                    ev((int)$u['id'], 'bc_sent', $key);
                    $sent++;
                } elseif (($r['error_code'] ?? 0) == 429) {
                    sleep(min(30, (int)($r['parameters']['retry_after'] ?? 5)));
                } else {
                    q("UPDATE bq SET status='failed', error=? WHERE id=?", [mb_substr((string)($r['description'] ?? ''), 0, 200), $it['id']]);
                    q('UPDATE broadcasts SET failed = failed + 1 WHERE id = ?', [$b['id']]);
                }
                usleep(40000); // ~25 сообщений/сек, лимит Telegram — 30
            }
        }
        if ((int)val("SELECT COUNT(*) FROM bq WHERE broadcast_id = ? AND status='pending'", [$b['id']]) === 0) {
            q("UPDATE broadcasts SET status='done', finished_at=? WHERE id=?", [now(), $b['id']]);
        }
    }
    return $sent;
}

// ======================= планировщик =======================

function run_tick(int $seconds = 25): array
{
    $fh = fopen(data_dir() . '/tick.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) return ['locked' => true];
    set_setting('last_tick', (string)now());
    $deadline = time() + $seconds;
    $res = ['flow' => flow_tick($deadline), 'broadcast' => bc_tick($deadline), 'hooks' => hooks_process()];
    flock($fh, LOCK_UN);
    fclose($fh);
    return $res;
}

// ======================= входящие постбеки =======================

function postback_apply(string $event, array $u, array $data = []): array
{
    $uid = (int)$u['id'];
    ev($uid, 'postback', $event, $event, $data);
    $applied = [];
    $rules = rows('SELECT * FROM in_rules WHERE lower(event) = lower(?)', [$event]);
    if (!$rules && setting('auto_group_events', '0') === '1') {
        $gid = group_ensure('Событие: ' . $event);
        $rules = [['action' => 'add', 'group_id' => $gid]];
    }
    foreach ($rules as $r) {
        if ($r['action'] === 'remove') group_remove($uid, (int)$r['group_id']);
        else group_add($uid, (int)$r['group_id'], 'postback');
        $applied[] = ($r['action'] === 'remove' ? '-' : '+') . $r['group_id'];
    }
    return $applied;
}

// ======================= обработка апдейтов Telegram =======================

function handle_update(array $update, int $botId): void
{
    if (isset($update['callback_query'])) {
        handle_callback($update['callback_query'], $botId);
    } elseif (isset($update['my_chat_member'])) {
        $m = $update['my_chat_member'];
        if (($m['chat']['type'] ?? '') === 'private') {
            $u = row('SELECT id FROM users WHERE bot_id = ? AND tg_id = ?', [$botId, (int)$m['chat']['id']]);
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
            $u = user_touch($msg['from'], $mm[1] ?? null, $botId);
            ev((int)$u['id'], 'start', (string)($mm[1] ?? ''), (string)($u['source'] ?? ''));
            flow_enroll($u, !$u['_new'] && setting('restart_on_start', '0') === '1');
            flow_tick(time() + 20, (int)$u['id']);
        } else {
            $u = user_touch($msg['from'], null, $botId);
            ev((int)$u['id'], 'message', '', mb_substr($text, 0, 100));
            $reply = trim((string)setting('default_reply', ''));
            if ($reply !== '') send_to_user($u, ['body' => $reply, 'image' => '', 'buttons' => '[]'], '');
        }
    }
}
